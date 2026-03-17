<?php

declare(strict_types=1);

namespace SafeMigrate\Jobs;

use SafeMigrate\Checkpoints\CheckpointRepository;
use SafeMigrate\Compatibility\BuilderRegistry;
use SafeMigrate\Diagnostics\PreflightRunner;
use SafeMigrate\Export\ExportPlanBuilder;
use SafeMigrate\Export\PackageBuilder;
use SafeMigrate\Import\PackageLoader;
use SafeMigrate\Import\PackageValidator;
use SafeMigrate\Import\RestoreService;
use SafeMigrate\Import\RestoreStages;
use SafeMigrate\Import\RestoreVerifier;
use SafeMigrate\Import\RestoreWorkspaceManager;
use SafeMigrate\Logging\Logger;
use SafeMigrate\Maintenance\ArtifactCleanupService;
use SafeMigrate\Product\FeaturePolicy;
use SafeMigrate\Product\SettingsService;
use SafeMigrate\Remote\RemoteMigrationService;
use SafeMigrate\Remote\TransferSessionService;
use SafeMigrate\Remap\RemapEngine;
use SafeMigrate\Support\SupportBundleService;
use SafeMigrate\Testing\FailureInjectionService;
use Throwable;

final class JobService
{
    public const LOCK_OPTION = 'safe_migrate_destructive_lock';

    /**
     * @var array<int, string>
     */
    private const AUTO_ROLLBACK_AFTER_STAGES = [
        RestoreStages::FILESYSTEM_APPLIED,
        RestoreStages::DATABASE_APPLIED,
        RestoreStages::REMAP_APPLIED,
        RestoreStages::VERIFICATION_PASSED,
    ];

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly CheckpointRepository $checkpoints,
        private readonly Logger $logger,
        private readonly PreflightRunner $preflightRunner,
        private readonly ExportPlanBuilder $exportPlanBuilder,
        private readonly PackageBuilder $packageBuilder,
        private readonly PackageLoader $packageLoader,
        private readonly PackageValidator $packageValidator,
        private readonly RestoreService $restoreService,
        private readonly RestoreVerifier $restoreVerifier,
        private readonly RestoreWorkspaceManager $restoreWorkspaceManager,
        private readonly RemapEngine $remapEngine,
        private readonly ArtifactCleanupService $artifactCleanupService,
        private readonly SupportBundleService $supportBundleService,
        private readonly BuilderRegistry $builderRegistry,
        private readonly SettingsService $settingsService,
        private readonly FeaturePolicy $featurePolicy,
        private readonly FailureInjectionService $failureInjectionService,
        private readonly TransferSessionService $transferSessionService,
        private readonly RemoteMigrationService $remoteMigrationService
    ) {
    }

    public function runPreflight(int $userId): array
    {
        $jobId = $this->jobs->create('preflight', 'running', [
            'requested_by' => $userId,
            'requested_at' => current_time('mysql', true),
        ]);

        $this->logger->info($jobId, 'Preflight job started.', ['user_id' => $userId]);

        try {
            $report = $this->preflightRunner->run();

            $this->checkpoints->create($jobId, 'preflight.completed', $report);
            $this->jobs->update($jobId, [
                'status' => $report['status'] === 'ready' ? 'completed' : 'needs_attention',
                'payload' => [
                    'requested_by' => $userId,
                    'report' => $report,
                    'report_summary' => [
                        'health_score' => $report['health_score'],
                        'blockers' => count($report['blockers']),
                        'warnings' => count($report['warnings']),
                    ],
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job' => $this->jobs->find($jobId), 'report' => $report];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($jobId);
            $this->logger->error($jobId, 'Preflight job failed.', ['message' => $throwable->getMessage()]);
            throw $throwable;
        }
    }

    public function buildExportPlan(int $userId): array
    {
        $jobId = $this->jobs->create('export_plan', 'running', [
            'requested_by' => $userId,
            'requested_at' => current_time('mysql', true),
        ]);

        $this->logger->info($jobId, 'Export plan job started.', ['user_id' => $userId]);

        try {
            $result = $this->exportPlanBuilder->build($jobId);
            $summary = $result['summary'];

            $this->checkpoints->create($jobId, 'export.plan.created', $summary);
            $this->jobs->update($jobId, [
                'status' => 'completed',
                'payload' => [
                    'requested_by' => $userId,
                    'export_plan_summary' => $summary,
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job' => $this->jobs->find($jobId), 'plan' => $summary];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($jobId);
            throw $throwable;
        }
    }

    public function runExport(int $userId): array
    {
        $jobId = $this->jobs->create('export', 'running', [
            'requested_by' => $userId,
            'requested_at' => current_time('mysql', true),
        ]);

        try {
            $package = $this->packageBuilder->build($jobId, 'safe-migrate-export');
            $summary = $package['summary'];
            $summary['compatibility'] = [
                'detected_builders' => $package['manifest']['compatibility']['builders'] ?? [],
                'builder_warnings' => $package['manifest']['compatibility']['warnings'] ?? [],
            ];

            $this->checkpoints->create($jobId, 'export.package.created', $summary);
            $this->jobs->update($jobId, [
                'status' => 'completed',
                'payload' => [
                    'requested_by' => $userId,
                    'export_summary' => $summary,
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job' => $this->jobs->find($jobId), 'export' => $summary];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($jobId);
            throw $throwable;
        }
    }

    public function validatePackage(int $userId, string $artifactDirectory): array
    {
        $jobId = $this->jobs->create('validate_package', 'running', [
            'requested_by' => $userId,
            'artifact_directory' => $artifactDirectory,
            'requested_at' => current_time('mysql', true),
        ]);

        try {
            $package = $this->packageLoader->load($artifactDirectory);
            $validation = $this->packageValidator->validate($package, ['safe-migrate-export']);
            $validation['detected_builders'] = $package['manifest']['site']['detected_builders'] ?? [];
            $validation['builder_warnings'] = $this->builderRegistry->warnings(
                $this->builderRegistry->detectedFromManifest($package['manifest'])
            );

            $this->checkpoints->create($jobId, 'import.package.validated', $validation);
            $this->jobs->update($jobId, [
                'status' => $validation['status'] === 'valid' ? 'completed' : 'needs_attention',
                'payload' => [
                    'requested_by' => $userId,
                    'artifact_directory' => $artifactDirectory,
                    'validation' => $validation,
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job' => $this->jobs->find($jobId), 'validation' => $validation];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($jobId);
            throw $throwable;
        }
    }

    public function settings(): array
    {
        return $this->settingsService->get();
    }

    public function createTransferToken(int $userId): array
    {
        return [
            'transfer_token' => $this->transferSessionService->issueTransferToken($userId),
        ];
    }

    public function pullRemotePackage(int $userId, string $sourceUrl, string $transferToken): array
    {
        $jobId = $this->jobs->create('push_pull', 'running', [
            'requested_by' => $userId,
            'source_url' => $sourceUrl,
            'requested_at' => current_time('mysql', true),
        ]);

        try {
            $summary = $this->remoteMigrationService->pull($jobId, $sourceUrl, $transferToken);
            $this->checkpoints->create($jobId, 'push_pull.completed', $summary);
            $job = $this->jobs->find($jobId);
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $payload['requested_by'] = $userId;
            $payload['source_url'] = $sourceUrl;
            $payload['push_pull'] = $summary;
            $this->jobs->update($jobId, [
                'status' => 'completed',
                'payload' => $payload,
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job' => $this->jobs->find($jobId), 'push_pull' => $summary];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($jobId);
            $this->logger->error($jobId, 'Push/Pull migration failed.', [
                'source_url' => $sourceUrl,
                'message' => $throwable->getMessage(),
            ]);
            throw $throwable;
        }
    }

    public function simulateRestore(int $userId, string $artifactDirectory): array
    {
        $jobId = $this->jobs->create('restore_simulation', 'running', [
            'requested_by' => $userId,
            'artifact_directory' => $artifactDirectory,
            'requested_at' => current_time('mysql', true),
        ]);

        try {
            $package = $this->packageLoader->load($artifactDirectory);
            $validation = $this->packageValidator->validate($package, ['safe-migrate-export']);

            if ($validation['status'] !== 'valid') {
                throw new \RuntimeException('Package is not valid for restore simulation.');
            }

            $manifest = $package['manifest'];
            $summary = [
                'artifact_directory' => $artifactDirectory,
                'filesystem_chunks' => count($manifest['filesystem']['artifacts']['chunks'] ?? []),
                'database_tables' => count($manifest['database']['segments']['tables'] ?? []),
                'database_segments' => (int) ($manifest['database']['segments']['segment_count'] ?? 0),
                'detected_builders' => $manifest['site']['detected_builders'] ?? [],
                'builder_warnings' => $this->builderRegistry->warnings($this->builderRegistry->detectedFromManifest($manifest)),
                'status' => 'ready_for_restore',
            ];

            $this->checkpoints->create($jobId, 'restore.simulation.completed', $summary);
            $this->jobs->update($jobId, [
                'status' => 'completed',
                'payload' => [
                    'requested_by' => $userId,
                    'restore_simulation' => $summary,
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job' => $this->jobs->find($jobId), 'simulation' => $summary];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($jobId);
            throw $throwable;
        }
    }

    public function previewRestoreWorkspace(int $userId, string $artifactDirectory): array
    {
        $jobId = $this->jobs->create('restore_preview', 'running', [
            'requested_by' => $userId,
            'artifact_directory' => $artifactDirectory,
            'requested_at' => current_time('mysql', true),
        ]);

        try {
            $package = $this->packageLoader->load($artifactDirectory);
            $validation = $this->packageValidator->validate($package, ['safe-migrate-export']);

            if ($validation['status'] !== 'valid') {
                throw new \RuntimeException('Package is not valid for restore preview.');
            }

            $workspace = $this->restoreWorkspaceManager->create($jobId);
            $this->restoreWorkspaceManager->writeCheckpoint($workspace, '01-package-accepted', [
                'artifact_directory' => $artifactDirectory,
                'validation' => $validation,
            ]);
            $this->jobs->update($jobId, ['progress_percent' => 20]);

            $manifest = $package['manifest'];
            $filesystem = $this->restoreService->previewFilesystemRestore($manifest, $workspace);
            $this->restoreWorkspaceManager->writeCheckpoint($workspace, '02-filesystem-preview', $filesystem);
            $this->jobs->update($jobId, ['progress_percent' => 55]);

            $rules = $this->buildRemapRules($manifest);
            $database = $this->restoreService->previewDatabaseRestore($manifest, $workspace, $rules);
            $this->restoreWorkspaceManager->writeCheckpoint($workspace, '03-database-preview', $database);
            $this->jobs->update($jobId, ['progress_percent' => 90]);

            $summary = [
                'artifact_directory' => $artifactDirectory,
                'workspace' => $workspace,
                'filesystem_chunks' => $filesystem['restored_chunks'],
                'database_segments' => $database['staged_segments'],
                'remap_rules' => $rules,
                'builder_detection' => $manifest['site']['detected_builders'] ?? [],
                'builder_warnings' => $this->builderRegistry->warnings($this->builderRegistry->detectedFromManifest($manifest)),
                'status' => 'preview_ready',
            ];

            $this->checkpoints->create($jobId, 'restore.preview.completed', $summary);
            $this->jobs->update($jobId, [
                'status' => 'completed',
                'payload' => [
                    'requested_by' => $userId,
                    'restore_preview' => $summary,
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job' => $this->jobs->find($jobId), 'preview' => $summary];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($jobId);
            throw $throwable;
        }
    }

    public function runRestoreExecute(int $userId, string $artifactDirectory, bool $confirmDestructive): array
    {
        if (! $confirmDestructive) {
            throw new \RuntimeException('Destructive restore requires confirm_destructive=true.');
        }

        $jobId = $this->jobs->create('restore_execute', 'running', [
            'requested_by' => $userId,
            'artifact_directory' => $artifactDirectory,
            'confirm_destructive' => true,
            'requested_at' => current_time('mysql', true),
        ]);

        return $this->continueRestoreExecution($jobId);
    }

    public function resumeJob(int $userId, int $jobId): array
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException('Job not found.');
        }

        return match ((string) ($job['type'] ?? '')) {
            'restore_execute' => $this->continueRestoreExecution($jobId, $userId),
            'push_pull' => $this->continuePushPull($jobId, $userId),
            default => throw new \RuntimeException('Only restore_execute and push_pull jobs can be resumed.'),
        };
    }

    private function continuePushPull(int $jobId, int $userId): array
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException('Push/Pull job not found.');
        }

        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $sourceUrl = (string) ($payload['source_url'] ?? '');

        if ($sourceUrl === '') {
            throw new \RuntimeException('Push/Pull job is missing its source URL.');
        }

        try {
            $summary = $this->remoteMigrationService->pull($jobId, $sourceUrl);
            $this->checkpoints->create($jobId, 'push_pull.completed', $summary);
            $job = $this->jobs->find($jobId);
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $payload['requested_by'] = $userId;
            $payload['source_url'] = $sourceUrl;
            $payload['push_pull'] = $summary;
            $this->jobs->update($jobId, [
                'status' => 'completed',
                'payload' => $payload,
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job' => $this->jobs->find($jobId), 'push_pull' => $summary];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($jobId);
            $this->logger->error($jobId, 'Push/Pull migration resume failed.', [
                'source_url' => $sourceUrl,
                'message' => $throwable->getMessage(),
            ]);
            throw $throwable;
        }
    }

    public function runRestoreRollback(int $userId, int $sourceJobId): array
    {
        $sourceJob = $this->jobs->find($sourceJobId);

        if ($sourceJob === null) {
            throw new \RuntimeException('Source restore job not found.');
        }

        $snapshotArtifact = (string) ($sourceJob['payload']['snapshot_summary']['artifact_directory'] ?? '');

        if ($snapshotArtifact === '') {
            throw new \RuntimeException('rollback_unavailable');
        }

        $rollbackJobId = $this->jobs->create('restore_rollback', 'running', [
            'requested_by' => $userId,
            'source_job_id' => $sourceJobId,
            'snapshot_artifact_directory' => $snapshotArtifact,
            'requested_at' => current_time('mysql', true),
        ]);

        $this->acquireDestructiveLock($rollbackJobId, 'restore_rollback');

        try {
            $summary = $this->performRollback($rollbackJobId, $sourceJobId, $snapshotArtifact);
            $this->jobs->update($rollbackJobId, [
                'status' => 'completed',
                'payload' => [
                    'requested_by' => $userId,
                    'source_job_id' => $sourceJobId,
                    'rollback_summary' => $summary,
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);
            $this->markSourceRollbackCompleted($sourceJobId, $summary);

            return ['job' => $this->jobs->find($rollbackJobId), 'rollback' => $summary];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($rollbackJobId);
            throw $throwable;
        } finally {
            $this->releaseDestructiveLock($rollbackJobId);
        }
    }

    public function cleanupArtifacts(int $userId): array
    {
        $jobId = $this->jobs->create('cleanup_artifacts', 'running', [
            'requested_by' => $userId,
            'requested_at' => current_time('mysql', true),
        ]);

        try {
            $cleanup = $this->artifactCleanupService->cleanup();

            $this->checkpoints->create($jobId, 'maintenance.cleanup.completed', $cleanup);
            $this->jobs->update($jobId, [
                'status' => 'completed',
                'payload' => [
                    'requested_by' => $userId,
                    'cleanup' => $cleanup,
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job' => $this->jobs->find($jobId), 'cleanup' => $cleanup];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($jobId);
            throw $throwable;
        }
    }

    public function exportSupportBundle(int $userId, int $jobId): array
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException('Job not found.');
        }

        $bundle = $this->supportBundleService->build($jobId);
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $payload['support_bundle'] = array_replace(
            is_array($payload['support_bundle'] ?? null) ? $payload['support_bundle'] : [],
            $bundle
        );

        $this->jobs->update($jobId, ['payload' => $payload]);
        $this->logger->info($jobId, 'Support bundle exported.', ['user_id' => $userId, 'path' => $bundle['path']]);

        return ['job' => $this->jobs->find($jobId), 'support_bundle' => $bundle];
    }

    private function continueRestoreExecution(int $jobId, ?int $resumedByUserId = null): array
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException('Restore job not found.');
        }

        $artifactDirectory = (string) ($job['payload']['artifact_directory'] ?? '');

        if ($artifactDirectory === '') {
            throw new \RuntimeException('Restore job is missing artifact_directory.');
        }

        try {
            $this->acquireDestructiveLock($jobId, 'restore_execute');
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $workspace = is_array($payload['workspace'] ?? null)
                ? $payload['workspace']
                : $this->restoreWorkspaceManager->create($jobId);
            $payload['workspace'] = $workspace;
            $payload['failure_injection'] = is_array($payload['failure_injection'] ?? null)
                ? $payload['failure_injection']
                : $this->failureInjectionService->current();

            $package = $this->packageLoader->load($artifactDirectory);
            $manifest = $package['manifest'];
            $payload['package_manifest_path'] = (string) ($package['manifest_path'] ?? '');
            $payload['detected_builders'] = $manifest['site']['detected_builders'] ?? [];
            $payload['builder_warnings'] = $this->builderRegistry->warnings(
                $this->builderRegistry->detectedFromManifest($manifest)
            );
            $lastStage = (string) ($payload['last_successful_stage'] ?? '');
            $filesystemSummary = is_array($payload['filesystem_summary'] ?? null) ? $payload['filesystem_summary'] : [];
            $databaseSummary = is_array($payload['database_summary'] ?? null) ? $payload['database_summary'] : [];

            if (! $this->hasReachedStage($lastStage, RestoreStages::PACKAGE_VALIDATED)) {
                $payload = $this->beginRestoreStage($jobId, $payload, RestoreStages::PACKAGE_VALIDATED, 5);
                $validation = $this->packageValidator->validate($package, ['safe-migrate-export']);

                if ($validation['status'] !== 'valid') {
                    throw new \RuntimeException('Package validation failed before restore.');
                }

                $payload['validation'] = $validation;
                $payload = $this->markRestoreStage($jobId, $payload, RestoreStages::PACKAGE_VALIDATED, [
                    'validation' => $validation,
                ], 10);
                $lastStage = RestoreStages::PACKAGE_VALIDATED;
            }

            if (! $this->hasReachedStage($lastStage, RestoreStages::SNAPSHOT_CREATED)) {
                $payload = $this->beginRestoreStage($jobId, $payload, RestoreStages::SNAPSHOT_CREATED, 15);
                $snapshot = $this->createSnapshotForRestore($jobId, (int) ($resumedByUserId ?? ($payload['requested_by'] ?? 0)), $workspace);
                $payload['snapshot_summary'] = $snapshot['summary'];
                $payload['snapshot_job_id'] = $snapshot['job_id'];
                $payload = $this->markRestoreStage($jobId, $payload, RestoreStages::SNAPSHOT_CREATED, $snapshot, 25);
                $this->failureInjectionService->throwIfConfigured('after_snapshot');
                $lastStage = RestoreStages::SNAPSHOT_CREATED;
            }

            if (! $this->hasReachedStage($lastStage, RestoreStages::WORKSPACE_PREPARED)) {
                $payload = $this->beginRestoreStage($jobId, $payload, RestoreStages::WORKSPACE_PREPARED, 30);
                $rules = $this->buildRemapRules($manifest);
                $databasePreview = $this->restoreService->previewDatabaseRestore($manifest, $workspace, $rules);
                $payload['remap_rules'] = $rules;
                $payload['database_preview'] = $databasePreview;
                $payload = $this->markRestoreStage($jobId, $payload, RestoreStages::WORKSPACE_PREPARED, [
                    'workspace' => $workspace,
                    'database_preview' => $databasePreview,
                    'remap_rules' => $rules,
                ], 40);
                $lastStage = RestoreStages::WORKSPACE_PREPARED;
            }

            if (! $this->hasReachedStage($lastStage, RestoreStages::FILESYSTEM_APPLIED)) {
                $payload = $this->beginRestoreStage($jobId, $payload, RestoreStages::FILESYSTEM_APPLIED, 50);
                $filesystemSummary = $this->restoreService->applyFilesystemRestore($manifest, ABSPATH);
                $payload['filesystem_summary'] = $filesystemSummary;
                $payload = $this->markRestoreStage($jobId, $payload, RestoreStages::FILESYSTEM_APPLIED, $filesystemSummary, 55);
                $this->failureInjectionService->throwIfConfigured('after_filesystem');
                $lastStage = RestoreStages::FILESYSTEM_APPLIED;
            }

            if (! $this->hasReachedStage($lastStage, RestoreStages::DATABASE_APPLIED)) {
                $payload = $this->beginRestoreStage($jobId, $payload, RestoreStages::DATABASE_APPLIED, 65);
                $databaseSummary = $this->restoreService->applyDatabaseRestore($workspace);
                $payload['database_summary'] = $databaseSummary;
                $payload = $this->markRestoreStage($jobId, $payload, RestoreStages::DATABASE_APPLIED, $databaseSummary, 70);
                $this->failureInjectionService->throwIfConfigured('after_database');
                $lastStage = RestoreStages::DATABASE_APPLIED;
            }

            if (! $this->hasReachedStage($lastStage, RestoreStages::REMAP_APPLIED)) {
                $payload = $this->beginRestoreStage($jobId, $payload, RestoreStages::REMAP_APPLIED, 75);
                $payload = $this->markRestoreStage($jobId, $payload, RestoreStages::REMAP_APPLIED, [
                    'remap_rules' => $payload['remap_rules'] ?? [],
                ], 80);
                $lastStage = RestoreStages::REMAP_APPLIED;
            }

            if (! $this->hasReachedStage($lastStage, RestoreStages::VERIFICATION_PASSED)) {
                $payload = $this->beginRestoreStage($jobId, $payload, RestoreStages::VERIFICATION_PASSED, 85);
                $verification = $this->restoreVerifier->verify($package, [
                    'filesystem' => $filesystemSummary,
                    'database' => $databaseSummary,
                ]);

                if ($verification['status'] !== 'passed') {
                    throw new \RuntimeException('Restore verification failed: ' . implode(' | ', $verification['issues']));
                }

                $payload['verification'] = $verification;
                $payload = $this->markRestoreStage($jobId, $payload, RestoreStages::VERIFICATION_PASSED, $verification, 90);
                $this->failureInjectionService->throwIfConfigured('verification');
            }

            $payload = $this->beginRestoreStage($jobId, $payload, RestoreStages::SWITCH_OVER_COMPLETED, 95);
            $payload = $this->markRestoreStage($jobId, $payload, RestoreStages::SWITCH_OVER_COMPLETED, [
                'workspace' => $workspace,
                'snapshot_artifact_directory' => $payload['snapshot_summary']['artifact_directory'] ?? '',
            ], 100);

            $this->jobs->update($jobId, [
                'status' => 'completed',
                'payload' => $this->withSupportBundle($payload, $jobId, null),
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return [
                'job' => $this->jobs->find($jobId),
                'restore' => [
                    'artifact_directory' => $artifactDirectory,
                    'snapshot_artifact_directory' => (string) ($payload['snapshot_summary']['artifact_directory'] ?? ''),
                    'workspace' => $workspace,
                    'status' => 'switch_over_completed',
                ],
            ];
        } catch (Throwable $throwable) {
            $currentJob = $this->jobs->find($jobId);
            $payload = is_array($currentJob['payload'] ?? null) ? $currentJob['payload'] : [];
            $failedStage = $this->resolveFailureStage($throwable, $payload);
            $lastSuccessfulStage = (string) ($payload['last_successful_stage'] ?? '');
            $payload['failure'] = [
                'stage' => $failedStage,
                'classification' => $this->classificationForStage($failedStage),
                'message' => $throwable->getMessage(),
            ];
            $this->logger->error($jobId, 'Restore execution failed.', $payload['failure']);
            $payload = $this->withSupportBundle($payload, $jobId, $failedStage);

            if (in_array($lastSuccessfulStage, self::AUTO_ROLLBACK_AFTER_STAGES, true)) {
                $rollbackStatus = $this->attemptAutomaticRollback($jobId, $payload);
                $payload['failure']['rollback_status'] = $rollbackStatus['status'];
                $payload['failure']['rollback_job_id'] = $rollbackStatus['job_id'];

                if ($rollbackStatus['status'] === 'completed') {
                    $payload['rollback_summary'] = $rollbackStatus['summary'];
                    $payload['current_stage'] = RestoreStages::ROLLBACK_COMPLETED;
                    $payload['last_successful_stage'] = RestoreStages::ROLLBACK_COMPLETED;
                    $this->checkpoints->create($jobId, 'restore.' . RestoreStages::ROLLBACK_COMPLETED, [
                        'rollback' => $rollbackStatus['summary'],
                    ]);
                    $payload = $this->withSupportBundle($payload, $jobId, null);
                    $this->jobs->update($jobId, [
                        'status' => 'rolled_back',
                        'payload' => $payload,
                        'progress_percent' => 100,
                        'finished_at' => current_time('mysql', true),
                    ]);
                } else {
                    $this->jobs->update($jobId, [
                        'status' => 'rollback_failed',
                        'payload' => $payload,
                        'progress_percent' => 100,
                        'finished_at' => current_time('mysql', true),
                    ]);
                }
            } else {
                $this->jobs->update($jobId, [
                    'status' => 'failed',
                    'payload' => $payload,
                    'progress_percent' => (int) ($currentJob['progress_percent'] ?? 0),
                    'finished_at' => current_time('mysql', true),
                ]);
            }

            $this->clearConsumedFailureInjection($throwable, $payload);
            throw $throwable;
        } finally {
            $this->releaseDestructiveLock($jobId);
        }
    }

    private function createSnapshotForRestore(int $restoreJobId, int $userId, array $workspace): array
    {
        $snapshotJobId = $this->jobs->create('restore_snapshot', 'running', [
            'requested_by' => $userId,
            'parent_restore_job_id' => $restoreJobId,
            'requested_at' => current_time('mysql', true),
        ]);

        $artifactDirectory = trailingslashit((string) $workspace['snapshot']) . 'package';

        try {
            $snapshot = $this->packageBuilder->build($snapshotJobId, 'safe-migrate-snapshot', $artifactDirectory);
            $this->checkpoints->create($snapshotJobId, 'restore.snapshot.created', $snapshot['summary']);
            $this->logger->info($snapshotJobId, 'Restore snapshot package created.', [
                'parent_restore_job_id' => $restoreJobId,
                'artifact_directory' => $snapshot['summary']['artifact_directory'] ?? '',
            ]);
            $this->jobs->update($snapshotJobId, [
                'status' => 'completed',
                'payload' => [
                    'requested_by' => $userId,
                    'parent_restore_job_id' => $restoreJobId,
                    'snapshot_summary' => $snapshot['summary'],
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['job_id' => $snapshotJobId, 'summary' => $snapshot['summary']];
        } catch (Throwable $throwable) {
            $this->markGenericFailure($snapshotJobId);
            throw new \RuntimeException('Snapshot creation failed: ' . $throwable->getMessage(), 0, $throwable);
        }
    }

    private function performRollback(int $rollbackJobId, int $sourceJobId, string $snapshotArtifact): array
    {
        $package = $this->packageLoader->load($snapshotArtifact);
        $validation = $this->packageValidator->validate($package, ['safe-migrate-snapshot']);

        if ($validation['status'] !== 'valid') {
            throw new \RuntimeException('Snapshot package is not valid for rollback.');
        }

        $workspace = $this->restoreWorkspaceManager->create($rollbackJobId);
        $rules = $this->buildRemapRules($package['manifest']);
        $this->logger->info($rollbackJobId, 'Rollback workspace prepared.', [
            'source_job_id' => $sourceJobId,
            'workspace' => $workspace['base'] ?? '',
        ]);
        $this->restoreService->previewDatabaseRestore($package['manifest'], $workspace, $rules);
        $filesystemSummary = $this->restoreService->applyFilesystemRestore($package['manifest'], ABSPATH);
        $databaseSummary = $this->restoreService->applyDatabaseRestore($workspace);
        $verification = $this->restoreVerifier->verify($package, [
            'filesystem' => $filesystemSummary,
            'database' => $databaseSummary,
        ]);

        if ($verification['status'] !== 'passed') {
            throw new \RuntimeException('Rollback verification failed: ' . implode(' | ', $verification['issues']));
        }

        $summary = [
            'source_job_id' => $sourceJobId,
            'snapshot_artifact_directory' => $snapshotArtifact,
            'workspace' => $workspace,
            'filesystem' => $filesystemSummary,
            'database' => $databaseSummary,
            'verification' => $verification,
            'status' => 'rollback_completed',
        ];

        $this->checkpoints->create($rollbackJobId, 'restore.rollback.completed', $summary);
        $this->logger->info($rollbackJobId, 'Rollback completed.', [
            'source_job_id' => $sourceJobId,
            'snapshot_artifact_directory' => $snapshotArtifact,
        ]);

        return $summary;
    }

    private function attemptAutomaticRollback(int $sourceJobId, array $sourcePayload): array
    {
        $snapshotArtifact = (string) ($sourcePayload['snapshot_summary']['artifact_directory'] ?? '');

        if ($snapshotArtifact === '') {
            return ['status' => 'rollback_unavailable', 'job_id' => 0, 'summary' => []];
        }

        $rollbackJobId = $this->jobs->create('restore_rollback', 'running', [
            'requested_by' => (int) ($sourcePayload['requested_by'] ?? 0),
            'source_job_id' => $sourceJobId,
            'snapshot_artifact_directory' => $snapshotArtifact,
            'requested_at' => current_time('mysql', true),
            'automatic' => true,
        ]);

        try {
            $summary = $this->performRollback($rollbackJobId, $sourceJobId, $snapshotArtifact);
            $this->jobs->update($rollbackJobId, [
                'status' => 'completed',
                'payload' => [
                    'source_job_id' => $sourceJobId,
                    'rollback_summary' => $summary,
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['status' => 'completed', 'job_id' => $rollbackJobId, 'summary' => $summary];
        } catch (Throwable $throwable) {
            $this->jobs->update($rollbackJobId, [
                'status' => 'failed',
                'payload' => [
                    'source_job_id' => $sourceJobId,
                    'message' => $throwable->getMessage(),
                ],
                'progress_percent' => 100,
                'finished_at' => current_time('mysql', true),
            ]);

            return ['status' => 'failed', 'job_id' => $rollbackJobId, 'summary' => []];
        }
    }

    private function acquireDestructiveLock(int $jobId, string $jobType): void
    {
        $lock = get_option(self::LOCK_OPTION);

        if (is_array($lock) && (int) ($lock['job_id'] ?? 0) !== $jobId) {
            $lockedJob = $this->jobs->find((int) ($lock['job_id'] ?? 0));

            if ($lockedJob !== null && ($lockedJob['status'] ?? '') === 'running') {
                throw new \RuntimeException('Another destructive restore operation is already running.');
            }
        }

        update_option(self::LOCK_OPTION, [
            'job_id' => $jobId,
            'job_type' => $jobType,
            'locked_at' => current_time('mysql', true),
        ], false);
    }

    private function releaseDestructiveLock(int $jobId): void
    {
        $lock = get_option(self::LOCK_OPTION);

        if (is_array($lock) && (int) ($lock['job_id'] ?? 0) === $jobId) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private function beginRestoreStage(int $jobId, array $payload, string $stage, int $progress): array
    {
        $payload['current_stage'] = $stage;
        $this->logger->info($jobId, 'Restore stage started.', [
            'stage' => $stage,
            'progress_percent' => $progress,
        ]);
        $payload = $this->withSupportBundle($payload, $jobId, $stage);

        $this->jobs->update($jobId, [
            'status' => 'running',
            'payload' => $payload,
            'progress_percent' => $progress,
        ]);

        return $payload;
    }

    private function markRestoreStage(int $jobId, array $payload, string $stage, array $state, int $progress): array
    {
        $payload['current_stage'] = $stage;
        $payload['last_successful_stage'] = $stage;
        $this->logger->info($jobId, 'Restore stage completed.', [
            'stage' => $stage,
            'progress_percent' => $progress,
        ]);
        $this->checkpoints->create($jobId, 'restore.' . $stage, $state);
        $payload = $this->withSupportBundle($payload, $jobId, null);
        $this->jobs->update($jobId, [
            'status' => 'running',
            'payload' => $payload,
            'progress_percent' => $progress,
        ]);

        return $payload;
    }

    private function hasReachedStage(string $lastSuccessfulStage, string $targetStage): bool
    {
        if ($lastSuccessfulStage === '') {
            return false;
        }

        $ordered = RestoreStages::ordered();
        $lastIndex = array_search($lastSuccessfulStage, $ordered, true);
        $targetIndex = array_search($targetStage, $ordered, true);

        if ($lastIndex === false || $targetIndex === false) {
            return false;
        }

        return $lastIndex >= $targetIndex;
    }

    /**
     * @return array<string, string>
     */
    private function buildRemapRules(array $manifest): array
    {
        $rules = $this->remapEngine->rules(
            (string) ($manifest['site']['home_url'] ?? ''),
            home_url('/'),
            (string) ($manifest['site']['abspath'] ?? ''),
            ABSPATH
        );

        return $this->builderRegistry->normalizeRules($manifest, $rules);
    }

    private function classificationForStage(string $stage): string
    {
        return match ($stage) {
            'lock_conflict' => 'lock_conflict',
            'rollback_unavailable' => 'rollback_unavailable',
            RestoreStages::PACKAGE_VALIDATED => 'package_validation_failure',
            RestoreStages::SNAPSHOT_CREATED => 'snapshot_failure',
            RestoreStages::WORKSPACE_PREPARED => 'workspace_prepare_failure',
            RestoreStages::FILESYSTEM_APPLIED => 'filesystem_apply_failure',
            RestoreStages::DATABASE_APPLIED => 'database_apply_failure',
            RestoreStages::REMAP_APPLIED => 'remap_failure',
            RestoreStages::VERIFICATION_PASSED => 'verification_failure',
            default => 'restore_failure',
        };
    }

    private function resolveFailureStage(Throwable $throwable, array $payload): string
    {
        $message = $throwable->getMessage();

        if (str_contains($message, 'Another destructive restore operation is already running.')) {
            return 'lock_conflict';
        }

        if (str_contains($message, 'rollback_unavailable')) {
            return 'rollback_unavailable';
        }

        return (string) ($payload['current_stage'] ?? 'unknown');
    }

    private function markSourceRollbackCompleted(int $sourceJobId, array $summary): void
    {
        $sourceJob = $this->jobs->find($sourceJobId);

        if ($sourceJob === null) {
            return;
        }

        $payload = is_array($sourceJob['payload'] ?? null) ? $sourceJob['payload'] : [];
        $payload['rollback_summary'] = $summary;
        $payload['current_stage'] = RestoreStages::ROLLBACK_COMPLETED;
        $payload['last_successful_stage'] = RestoreStages::ROLLBACK_COMPLETED;
        $this->checkpoints->create($sourceJobId, 'restore.' . RestoreStages::ROLLBACK_COMPLETED, [
            'rollback' => $summary,
        ]);
        $payload = $this->withSupportBundle($payload, $sourceJobId, null);
        $this->jobs->update($sourceJobId, [
            'status' => 'rolled_back',
            'payload' => $payload,
            'progress_percent' => 100,
            'finished_at' => current_time('mysql', true),
        ]);
    }

    private function withSupportBundle(array $payload, int $jobId, ?string $failedStage): array
    {
        $latestCheckpoint = $this->checkpoints->latestForJob($jobId);
        $summary = [
            'manifest_path' => (string) ($payload['package_manifest_path'] ?? ''),
            'snapshot_path' => (string) ($payload['snapshot_summary']['artifact_directory'] ?? ''),
            'restore_workspace_path' => (string) ($payload['workspace']['base'] ?? ''),
            'failed_stage' => $failedStage,
            'last_successful_checkpoint' => (string) ($latestCheckpoint['stage'] ?? ''),
            'failure_classification' => (string) ($payload['failure']['classification'] ?? ''),
        ];
        $bundle = $this->supportBundleService->build($jobId, ['support_bundle' => $summary] + $payload);
        $payload['support_bundle'] = array_replace($summary, $bundle);

        return $payload;
    }

    private function clearConsumedFailureInjection(Throwable $throwable, array $payload): void
    {
        $config = is_array($payload['failure_injection'] ?? null) ? $payload['failure_injection'] : [];

        if (! (bool) ($config['once'] ?? false) || ! (bool) ($config['enabled'] ?? false)) {
            return;
        }

        $message = $throwable->getMessage();
        $configuredMessage = (string) ($config['message'] ?? '');

        if (($configuredMessage !== '' && $configuredMessage === $message) || str_contains($message, 'Injected failure')) {
            $this->failureInjectionService->clear();
        }
    }

    private function markGenericFailure(int $jobId): void
    {
        $this->jobs->update($jobId, [
            'status' => 'failed',
            'progress_percent' => 100,
            'finished_at' => current_time('mysql', true),
        ]);
    }
}

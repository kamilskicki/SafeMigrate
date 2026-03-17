<?php

declare(strict_types=1);

namespace SafeMigrate\Cli;

use SafeMigrate\Jobs\JobRepository;
use SafeMigrate\Jobs\JobSummaryFormatter;
use SafeMigrate\Jobs\JobService;
use SafeMigrate\Support\ArtifactPaths;
use SafeMigrate\Testing\FailureInjectionService;
use Throwable;

final class SafeMigrateCommand
{
    public function __construct(
        private readonly JobService $jobService,
        private readonly JobRepository $jobs,
        private readonly FailureInjectionService $failureInjectionService
    ) {
    }

    public function preflight(array $args, array $assocArgs): void
    {
        $this->runCompact(
            fn (): array => $this->jobService->runPreflight($this->userId($assocArgs)),
            static fn (array $result): array => [
                'job_id' => (int) ($result['job']['id'] ?? 0),
                'status' => (string) ($result['job']['status'] ?? 'unknown'),
                'report' => [
                    'status' => (string) ($result['report']['status'] ?? 'unknown'),
                    'health_score' => (int) ($result['report']['health_score'] ?? 0),
                    'blocker_count' => count((array) ($result['report']['blockers'] ?? [])),
                    'warning_count' => count((array) ($result['report']['warnings'] ?? [])),
                ],
            ]
        );
    }

    public function export_plan(array $args, array $assocArgs): void
    {
        $this->runCompact(
            fn (): array => $this->jobService->buildExportPlan($this->userId($assocArgs)),
            static fn (array $result): array => [
                'job_id' => (int) ($result['job']['id'] ?? 0),
                'status' => (string) ($result['job']['status'] ?? 'unknown'),
                'plan' => [
                    'artifact_directory' => (string) ($result['plan']['artifact_directory'] ?? ''),
                    'total_files' => (int) ($result['plan']['total_files'] ?? 0),
                    'total_bytes' => (int) ($result['plan']['total_bytes'] ?? 0),
                    'chunk_count' => (int) ($result['plan']['chunk_count'] ?? 0),
                    'database_tables' => (int) ($result['plan']['database_tables'] ?? 0),
                    'database_bytes' => (int) ($result['plan']['database_bytes'] ?? 0),
                ],
            ]
        );
    }

    public function export(array $args, array $assocArgs): void
    {
        $this->runCompact(
            fn (): array => $this->jobService->runExport($this->userId($assocArgs)),
            static fn (array $result): array => JobSummaryFormatter::summarizeResult($result, 'export')
        );
    }

    public function transfer_token(array $args, array $assocArgs): void
    {
        $this->runCompact(
            fn (): array => $this->jobService->createTransferToken($this->userId($assocArgs)),
            static fn (array $result): array => ['transfer_token' => $result['transfer_token'] ?? []]
        );
    }

    public function push_pull(array $args, array $assocArgs): void
    {
        $sourceUrl = (string) ($assocArgs['source-url'] ?? '');
        $transferToken = (string) ($assocArgs['transfer-token'] ?? '');

        if ($sourceUrl === '' || $transferToken === '') {
            \WP_CLI::error('Missing --source-url or --transfer-token.');
        }

        $this->runCompact(
            fn (): array => $this->jobService->pullRemotePackage($this->userId($assocArgs), $sourceUrl, $transferToken),
            static fn (array $result): array => JobSummaryFormatter::summarizeResult($result, 'push_pull')
        );
    }

    public function validate(array $args, array $assocArgs): void
    {
        $artifactDirectory = $this->artifactDirectory($assocArgs);
        $this->runCompact(
            fn (): array => $this->jobService->validatePackage($this->userId($assocArgs), $artifactDirectory),
            static fn (array $result): array => JobSummaryFormatter::summarizeResult($result, 'validation')
        );
    }

    public function preview_restore(array $args, array $assocArgs): void
    {
        $artifactDirectory = $this->artifactDirectory($assocArgs);
        $this->runCompact(
            fn (): array => $this->jobService->previewRestoreWorkspace($this->userId($assocArgs), $artifactDirectory),
            static fn (array $result): array => JobSummaryFormatter::summarizeResult($result, 'preview')
        );
    }

    public function restore_execute(array $args, array $assocArgs): void
    {
        $artifactDirectory = $this->artifactDirectory($assocArgs);
        $confirm = $this->boolFlag($assocArgs, 'confirm-destructive');
        $this->runCompact(
            fn (): array => $this->jobService->runRestoreExecute($this->userId($assocArgs), $artifactDirectory, $confirm),
            static fn (array $result): array => JobSummaryFormatter::summarizeResult($result, 'restore')
        );
    }

    public function rollback(array $args, array $assocArgs): void
    {
        $jobId = (int) ($assocArgs['job-id'] ?? 0);

        if ($jobId <= 0) {
            \WP_CLI::error('Missing --job-id.');
        }

        $this->runCompact(
            fn (): array => $this->jobService->runRestoreRollback($this->userId($assocArgs), $jobId),
            static fn (array $result): array => JobSummaryFormatter::summarizeResult($result, 'rollback')
        );
    }

    public function support_bundle(array $args, array $assocArgs): void
    {
        $jobId = (int) ($assocArgs['job-id'] ?? 0);

        if ($jobId <= 0) {
            \WP_CLI::error('Missing --job-id.');
        }

        $this->runCompact(
            fn (): array => $this->jobService->exportSupportBundle($this->userId($assocArgs), $jobId),
            static fn (array $result): array => [
                'job_id' => (int) ($result['job']['id'] ?? 0),
                'status' => (string) ($result['job']['status'] ?? 'unknown'),
                'support_bundle' => [
                    'directory' => (string) ($result['support_bundle']['directory'] ?? ''),
                    'path' => (string) ($result['support_bundle']['path'] ?? ''),
                    'generated_at' => (string) ($result['support_bundle']['generated_at'] ?? ''),
                    'checkpoint_count' => (int) ($result['support_bundle']['checkpoint_count'] ?? 0),
                    'log_count' => (int) ($result['support_bundle']['log_count'] ?? 0),
                    'redacted' => (bool) ($result['support_bundle']['redacted'] ?? false),
                ],
            ]
        );
    }

    public function resume(array $args, array $assocArgs): void
    {
        $jobId = (int) ($assocArgs['job-id'] ?? 0);

        if ($jobId <= 0) {
            \WP_CLI::error('Missing --job-id.');
        }

        $this->runCompact(
            fn (): array => $this->jobService->resumeJob($this->userId($assocArgs), $jobId),
            static function (array $result): array {
                return isset($result['push_pull'])
                    ? JobSummaryFormatter::summarizeResult($result, 'push_pull')
                    : JobSummaryFormatter::summarizeResult($result, 'restore');
            }
        );
    }

    public function cleanup(array $args, array $assocArgs): void
    {
        $this->runCompact(
            fn (): array => $this->jobService->cleanupArtifacts($this->userId($assocArgs)),
            static fn (array $result): array => [
                'job_id' => (int) ($result['job']['id'] ?? 0),
                'status' => (string) ($result['job']['status'] ?? 'unknown'),
                'cleanup' => $result['cleanup'] ?? [],
            ]
        );
    }

    public function inject_failure(array $args, array $assocArgs): void
    {
        $stage = (string) ($assocArgs['stage'] ?? '');

        if ($stage === '') {
            \WP_CLI::error('Missing --stage.');
        }

        \WP_CLI::line((string) wp_json_encode([
            'available' => $this->failureInjectionService->isAvailable(),
            'failure_injection' => $this->failureInjectionService->configure([
                'stage' => $stage,
                'message' => (string) ($assocArgs['message'] ?? ''),
                'once' => $this->boolFlag($assocArgs, 'once', true),
                'enabled' => true,
            ]),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function clear_failure(array $args, array $assocArgs): void
    {
        $this->failureInjectionService->clear();
        \WP_CLI::success('Failure injection cleared.');
    }

    public function e2e(array $args, array $assocArgs): void
    {
        $userId = $this->userId($assocArgs);
        $summary = [];
        $injectStage = (string) ($assocArgs['inject-stage'] ?? '');

        if ($injectStage !== '') {
            $this->failureInjectionService->configure([
                'stage' => $injectStage,
                'message' => sprintf('Injected failure at stage: %s', $injectStage),
                'once' => true,
                'enabled' => true,
            ]);
        }

        try {
            $export = $this->jobService->runExport($userId);
            $artifactDirectory = (string) ($export['export']['artifact_directory'] ?? '');
            $summary['export'] = JobSummaryFormatter::summarizeResult($export, 'export');

            $validate = $this->jobService->validatePackage($userId, $artifactDirectory);
            $summary['validate'] = JobSummaryFormatter::summarizeResult($validate, 'validation');

            $preview = $this->jobService->previewRestoreWorkspace($userId, $artifactDirectory);
            $summary['preview'] = JobSummaryFormatter::summarizeResult($preview, 'preview');

            if ($this->boolFlag($assocArgs, 'destructive')) {
                $restore = $this->jobService->runRestoreExecute($userId, $artifactDirectory, true);
                $summary['restore'] = JobSummaryFormatter::summarizeResult($restore, 'restore');

                if ($this->boolFlag($assocArgs, 'rollback-after')) {
                    $restoreJobId = (int) ($restore['job']['id'] ?? 0);
                    $rollback = $this->jobService->runRestoreRollback($userId, $restoreJobId);
                    $summary['rollback'] = JobSummaryFormatter::summarizeResult($rollback, 'rollback');
                }
            }
        } catch (Throwable $throwable) {
            $summary['error'] = ['message' => $throwable->getMessage()];

            if ($this->boolFlag($assocArgs, 'destructive')) {
                $summary['restore'] ??= $this->latestJobSummary(['restore_execute']);
                $summary['rollback'] ??= $this->latestJobSummary(['restore_rollback']);
            }

            \WP_CLI::line((string) wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            \WP_CLI::error($throwable->getMessage(), false);
            exit(1);
        } finally {
            if ($injectStage !== '') {
                $this->failureInjectionService->clear();
            }
        }

        \WP_CLI::line((string) wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function run(callable $operation): void
    {
        try {
            $result = $operation();
            \WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $throwable) {
            \WP_CLI::error($throwable->getMessage(), false);
            exit(1);
        }
    }

    private function runCompact(callable $operation, callable $formatter): void
    {
        $this->run(
            static function () use ($operation, $formatter): array {
                $result = $operation();

                return $formatter(is_array($result) ? $result : []);
            }
        );
    }

    private function userId(array $assocArgs): int
    {
        return max(1, (int) ($assocArgs['user'] ?? 1));
    }

    private function artifactDirectory(array $assocArgs): string
    {
        $artifactDirectory = (string) ($assocArgs['artifact-directory'] ?? '');

        if ($artifactDirectory !== '') {
            return $artifactDirectory;
        }

        $directories = ArtifactPaths::exportDirectories();

        if ($directories === []) {
            \WP_CLI::error('No export artifacts found.');
        }

        return (string) $directories[0];
    }

    private function boolFlag(array $assocArgs, string $key, bool $default = false): bool
    {
        $value = strtolower((string) ($assocArgs[$key] ?? ($default ? '1' : '0')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function latestJobSummary(array $types): ?array
    {
        $jobs = $this->jobs->findLatestMatching(
            $types,
            ['running', 'failed', 'completed', 'rolled_back', 'rollback_failed', 'needs_attention'],
            1
        );

        if ($jobs === []) {
            return null;
        }

        return JobSummaryFormatter::summarizeJob($jobs[0]);
    }

}

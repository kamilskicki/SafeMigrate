<?php

declare(strict_types=1);

namespace SafeMigrate\Remote;

use SafeMigrate\Api\ApiErrorClassifier;
use SafeMigrate\Contracts\RegistersHooks;
use SafeMigrate\Diagnostics\PreflightRunner;
use SafeMigrate\Jobs\Capabilities;
use SafeMigrate\Jobs\JobRepository;
use SafeMigrate\Jobs\JobService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class TransferRestController implements RegistersHooks
{
    public function __construct(
        private readonly TransferSessionService $transferSessions,
        private readonly RemoteMigrationService $remoteMigrationService,
        private readonly PreflightRunner $preflightRunner,
        private readonly JobRepository $jobs,
        private readonly JobService $jobService,
        private readonly ApiErrorClassifier $errorClassifier
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('safe-migrate/v1', '/transfer-token', [
            'methods' => 'POST',
            'callback' => [$this, 'createTransferToken'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('safe-migrate/v1', '/push-pull', [
            'methods' => 'POST',
            'callback' => [$this, 'pushPull'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route('safe-migrate/v1', '/remote/session', [
            'methods' => 'POST',
            'callback' => [$this, 'createRemoteSession'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('safe-migrate/v1', '/remote/preflight', [
            'methods' => 'GET',
            'callback' => [$this, 'remotePreflight'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('safe-migrate/v1', '/remote/export', [
            'methods' => 'POST',
            'callback' => [$this, 'remoteExport'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('safe-migrate/v1', '/remote/export/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'remoteExportStatus'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('safe-migrate/v1', '/remote/download/manifest/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'downloadManifest'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('safe-migrate/v1', '/remote/download/files-index/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'downloadFilesIndex'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('safe-migrate/v1', '/remote/download/chunk/(?P<id>\d+)/(?P<index>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'downloadChunk'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('safe-migrate/v1', '/remote/download/database-table/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'downloadDatabaseTable'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can(Capabilities::MANAGE);
    }

    public function createTransferToken(): WP_REST_Response|WP_Error
    {
        return $this->handle(fn (): array => $this->jobService->createTransferToken(get_current_user_id()), 'safe_migrate_transfer_token_failed');
    }

    public function pushPull(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => $this->jobService->pullRemotePackage(
                get_current_user_id(),
                (string) $request->get_param('source_url'),
                (string) $request->get_param('transfer_token')
            ),
            'safe_migrate_push_pull_failed'
        );
    }

    public function createRemoteSession(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => [
                'session' => $this->transferSessions->createSessionFromTransferToken(
                    (string) $request->get_param('transfer_token'),
                    is_array($request->get_param('target')) ? $request->get_param('target') : []
                ),
                'source_site' => [
                    'home' => home_url('/'),
                    'siteurl' => site_url('/'),
                    'plugin_version' => SAFE_MIGRATE_VERSION,
                ],
            ],
            'safe_migrate_remote_session_failed'
        );
    }

    public function remotePreflight(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => [
                'report' => $this->preflightRunner->run(),
                'source_site' => $this->authenticatedSession($request)['target'] ?? [],
            ],
            'safe_migrate_remote_preflight_failed'
        );
    }

    public function remoteExport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            function () use ($request): array {
                $session = $this->authenticatedSession($request);
                $existingJobId = $this->transferSessions->exportJobId($session);

                if ($existingJobId > 0) {
                    return ['export_job_id' => $existingJobId];
                }

                wp_raise_memory_limit('admin');
                @ini_set('memory_limit', '-1');
                @set_time_limit(0);

                $export = $this->jobService->runExport($this->transferSessions->createdBy($session));
                $jobId = (int) ($export['job']['id'] ?? 0);
                $artifactDirectory = (string) ($export['export']['artifact_directory'] ?? '');
                $this->transferSessions->attachExportJob((string) ($session['id'] ?? ''), $jobId, $artifactDirectory);

                return ['export_job_id' => $jobId];
            },
            'safe_migrate_remote_export_failed'
        );
    }

    public function remoteExportStatus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            function () use ($request): array {
                $session = $this->authenticatedSession($request);
                $jobId = (int) $request['id'];
                $this->assertSessionJob($session, $jobId);
                $job = $this->jobs->find($jobId);

                if ($job === null) {
                    throw new \RuntimeException('Job not found.');
                }

                return [
                    'job' => $job,
                    'export' => (array) ($job['payload']['export_summary'] ?? []),
                ];
            },
            'safe_migrate_remote_export_status_failed'
        );
    }

    public function downloadManifest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->downloadArtifactFile($request, 'manifest');
    }

    public function downloadFilesIndex(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->downloadArtifactFile($request, 'files_index');
    }

    public function downloadChunk(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            function () use ($request): array {
                $job = $this->artifactJob($request);
                $chunkIndex = (int) $request['index'];

                foreach ((array) ($job['payload']['export_summary'] ?? []) as $unused) {
                    // no-op to keep payload access consistent with hydrate type
                }

                $checkpointed = $this->jobs->find((int) $request['id']);
                $payload = is_array($checkpointed['payload'] ?? null) ? $checkpointed['payload'] : [];
                $artifactDirectory = (string) ($payload['export_summary']['artifact_directory'] ?? '');
                $manifest = json_decode((string) file_get_contents($artifactDirectory . '/manifest.json'), true);

                foreach (($manifest['filesystem']['artifacts']['chunks'] ?? []) as $chunk) {
                    if ((int) ($chunk['index'] ?? 0) !== $chunkIndex) {
                        continue;
                    }

                    return $this->fileResponse((string) ($chunk['path'] ?? ''));
                }

                throw new \RuntimeException('Requested filesystem chunk not found.');
            },
            'safe_migrate_remote_chunk_download_failed'
        );
    }

    public function downloadDatabaseTable(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            function () use ($request): array {
                $job = $this->artifactJob($request);
                $tableName = rawurldecode((string) $request->get_param('table'));

                if ($tableName === '') {
                    throw new \RuntimeException('Missing database table parameter.');
                }

                $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                $artifactDirectory = (string) ($payload['export_summary']['artifact_directory'] ?? '');
                $manifest = json_decode((string) file_get_contents($artifactDirectory . '/manifest.json'), true);

                foreach (($manifest['database']['segments']['tables'] ?? []) as $table) {
                    if ((string) ($table['table'] ?? '') !== $tableName) {
                        continue;
                    }

                    return $this->directoryZipResponse((string) ($table['directory'] ?? ''), sanitize_title_with_dashes($tableName) . '.zip');
                }

                throw new \RuntimeException('Requested database table export not found.');
            },
            'safe_migrate_remote_database_download_failed'
        );
    }

    private function downloadArtifactFile(WP_REST_Request $request, string $kind): WP_REST_Response|WP_Error
    {
        return $this->handle(
            function () use ($request, $kind): array {
                $job = $this->artifactJob($request);
                $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                $summary = (array) ($payload['export_summary'] ?? []);
                $path = $kind === 'manifest'
                    ? (string) ($summary['manifest_path'] ?? '')
                    : (string) ($summary['files_index_path'] ?? '');

                return $this->fileResponse($path);
            },
            'safe_migrate_remote_artifact_download_failed'
        );
    }

    private function artifactJob(WP_REST_Request $request): array
    {
        $session = $this->authenticatedSession($request);
        $jobId = (int) $request['id'];
        $this->assertSessionJob($session, $jobId);

        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException('Job not found.');
        }

        if ((string) ($job['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('Remote export is not ready yet.');
        }

        return $job;
    }

    private function authenticatedSession(WP_REST_Request $request): array
    {
        return $this->transferSessions->authenticateSession((string) $request->get_header('X-Safe-Migrate-Session'));
    }

    private function assertSessionJob(array $session, int $jobId): void
    {
        if ($this->transferSessions->exportJobId($session) !== $jobId) {
            throw new \RuntimeException('Transfer session is not authorized for this export job.');
        }
    }

    private function fileResponse(string $path): array
    {
        if ($path === '' || ! is_file($path)) {
            throw new \RuntimeException('Requested artifact file does not exist.');
        }

        return [
            'filename' => basename($path),
            'checksum_sha256' => hash_file('sha256', $path),
            'contents_b64' => base64_encode((string) file_get_contents($path)),
        ];
    }

    private function directoryZipResponse(string $directory, string $filename): array
    {
        if ($directory === '' || ! is_dir($directory)) {
            throw new \RuntimeException('Requested artifact directory does not exist.');
        }

        $archivePath = wp_tempnam($filename);

        if (! is_string($archivePath) || $archivePath === '') {
            throw new \RuntimeException('Could not allocate temporary archive path.');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new \RuntimeException('Could not create temporary archive.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = ltrim(str_replace('\\', '/', str_replace($directory, '', $path)), '/');

            if ($relative === '') {
                continue;
            }

            $zip->addFile($path, $relative);
        }

        $zip->close();
        $response = $this->fileResponse($archivePath);
        unlink($archivePath);

        return $response;
    }

    private function handle(callable $operation, string $defaultCode): WP_REST_Response|WP_Error
    {
        try {
            return new WP_REST_Response($operation(), 200);
        } catch (\Throwable $throwable) {
            $classified = $this->errorClassifier->classify($throwable->getMessage());

            return new WP_Error($defaultCode, $throwable->getMessage(), [
                'status' => $classified['status'],
                'safe_migrate_code' => $classified['safe_migrate_code'],
            ]);
        }
    }
}

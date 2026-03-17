<?php

declare(strict_types=1);

namespace SafeMigrate\Remote;

use SafeMigrate\Import\PackageLoader;
use SafeMigrate\Import\PackageValidator;
use SafeMigrate\Jobs\JobRepository;
use SafeMigrate\Logging\Logger;
use SafeMigrate\Support\ArtifactPaths;
use ZipArchive;

final class RemoteMigrationService
{
    private const POLL_DELAY_SECONDS = 5;
    private const POLL_ATTEMPTS = 180;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly Logger $logger,
        private readonly PackageLoader $packageLoader,
        private readonly PackageValidator $packageValidator
    ) {
    }

    public function pull(int $jobId, string $sourceUrl, string $transferToken = ''): array
    {
        $sourceUrl = $this->normalizeSourceUrl($sourceUrl);
        $baseUrl = $this->baseUrl($sourceUrl);
        $artifactDirectory = $this->artifactDirectory($jobId);
        wp_mkdir_p($artifactDirectory);

        if (! is_dir($artifactDirectory)) {
            throw new \RuntimeException(sprintf('Could not create artifact directory %s.', $artifactDirectory));
        }
        $state = $this->loadState($jobId, $sourceUrl, $artifactDirectory);
        $credential = (string) ($state['remote_session_token'] ?? '');

        if ($credential === '') {
            if ($transferToken === '') {
                throw new \RuntimeException('Push/Pull transfer cannot resume before a remote session has been established.');
            }

            $session = $this->createRemoteSession($baseUrl, $transferToken);
            $credential = (string) ($session['credential'] ?? '');

            if ($credential === '') {
                throw new \RuntimeException('Remote session credential is missing.');
            }

            $state['remote_session_id'] = (string) ($session['session_id'] ?? '');
            $state['remote_session_token'] = $credential;
            $state['stage'] = 'session_established';
            $this->persistState($jobId, $state, 10, 'Remote session established.', [], 'running', true);
        }

        if (($state['remote_preflight'] ?? []) === []) {
            $preflight = $this->requestJson('GET', $baseUrl . '/remote/preflight', null, $credential);
            $state['remote_preflight'] = (array) ($preflight['report'] ?? []);
            $state['stage'] = 'remote_preflight_completed';
            $this->persistState($jobId, $state, 20, 'Remote preflight completed.', [], 'running', true);
        }

        $remoteJobId = (int) ($state['remote_export_job_id'] ?? 0);

        if ($remoteJobId <= 0) {
            $exportKickoff = $this->requestJson('POST', $baseUrl . '/remote/export', [], $credential, 1800);
            $remoteJobId = (int) ($exportKickoff['export_job_id'] ?? 0);

            if ($remoteJobId <= 0) {
                throw new \RuntimeException('Remote export job could not be created.');
            }

            $state['remote_export_job_id'] = $remoteJobId;
            $state['stage'] = 'remote_export_started';
            $this->persistState($jobId, $state, 30, 'Remote export started.', [], 'running', true);
        }

        $remoteExport = $this->pollRemoteExport($baseUrl, $credential, $remoteJobId, $jobId, $state);
        $state['remote_export'] = (array) ($remoteExport['export'] ?? []);
        $manifest = $this->ensureManifest($baseUrl, $credential, $remoteJobId, $artifactDirectory, $state, $jobId);
        $state = $this->syncTransferCounts($state, $manifest);
        $this->ensureFilesIndex($baseUrl, $credential, $remoteJobId, $artifactDirectory, $state, $jobId);
        $state['stage'] = 'artifact_download';
        $this->persistState($jobId, $state, 50);

        foreach (($manifest['filesystem']['artifacts']['chunks'] ?? []) as $chunk) {
            $chunkIndex = (int) ($chunk['index'] ?? 0);

            if ($chunkIndex <= 0 || $this->chunkReady($artifactDirectory, $chunk)) {
                $state = $this->markChunkDownloaded($state, $chunkIndex);
                $this->persistTransferProgress($jobId, $state);
                continue;
            }

            $chunkBody = $this->requestJson(
                'GET',
                $baseUrl . '/remote/download/chunk/' . $remoteJobId . '/' . $chunkIndex,
                null,
                $credential
            );

            $targetPath = $artifactDirectory . '/chunks/chunk-' . str_pad((string) $chunkIndex, 3, '0', STR_PAD_LEFT) . '.zip';
            $this->writeDownloadedFile($targetPath, $chunkBody);
            $state = $this->markChunkDownloaded($state, $chunkIndex);
            $this->persistTransferProgress($jobId, $state);
        }

        $state['stage'] = 'filesystem_downloaded';
        $this->persistState($jobId, $state, 75, 'Filesystem chunks downloaded.', [], 'running', true);

        foreach (($manifest['database']['segments']['tables'] ?? []) as $table) {
            $tableName = (string) ($table['table'] ?? '');

            if ($tableName === '') {
                continue;
            }

            if ($this->tableReady($artifactDirectory, $table)) {
                $state = $this->markTableDownloaded($state, $tableName);
                $this->persistTransferProgress($jobId, $state);
                continue;
            }

            $tableBody = $this->requestJson(
                'GET',
                add_query_arg(
                    ['table' => rawurlencode($tableName)],
                    $baseUrl . '/remote/download/database-table/' . $remoteJobId
                ),
                null,
                $credential
            );

            $this->extractTableBundle($artifactDirectory, $tableName, $tableBody);
            $state = $this->markTableDownloaded($state, $tableName);
            $this->persistTransferProgress($jobId, $state);
        }

        $state['stage'] = 'database_downloaded';
        $this->persistState($jobId, $state, 90, 'Database table bundles downloaded.', [], 'running', true);

        $package = $this->packageLoader->load($artifactDirectory);
        $validation = $this->packageValidator->validate($package, ['safe-migrate-export']);

        if ($validation['status'] !== 'valid') {
            throw new \RuntimeException('Remote package validation failed: ' . implode(' | ', (array) ($validation['issues'] ?? [])));
        }

        $summary = [
            'source_url' => $sourceUrl,
            'artifact_directory' => $artifactDirectory,
            'remote_session_id' => (string) ($state['remote_session_id'] ?? ''),
            'remote_export_job_id' => $remoteJobId,
            'remote_preflight' => $state['remote_preflight'] ?? [],
            'remote_export' => $remoteExport['export'] ?? [],
            'validation' => $validation,
            'transfer_progress' => $this->publicProgress($state, 'completed'),
        ];
        $state['stage'] = 'completed';
        $this->persistState($jobId, $state, 100, 'Push/Pull package ready.', ['push_pull' => $summary], 'completed', true);

        return $summary;
    }

    private function createRemoteSession(string $baseUrl, string $transferToken): array
    {
        $body = [
            'transfer_token' => $transferToken,
            'target' => [
                'home' => home_url('/'),
                'siteurl' => site_url('/'),
                'abspath' => ABSPATH,
            ],
        ];

        $response = $this->requestJson('POST', $baseUrl . '/remote/session', $body);

        return (array) ($response['session'] ?? []);
    }

    private function pollRemoteExport(string $baseUrl, string $credential, int $remoteJobId, int $localJobId, array &$state): array
    {
        for ($attempt = 1; $attempt <= self::POLL_ATTEMPTS; $attempt++) {
            $response = $this->requestJson('GET', $baseUrl . '/remote/export/' . $remoteJobId, null, $credential);
            $job = (array) ($response['job'] ?? []);
            $status = (string) ($job['status'] ?? '');

            if ($status === 'completed') {
                return $response;
            }

            if (in_array($status, ['failed', 'rollback_failed'], true)) {
                throw new \RuntimeException('Remote export failed.');
            }

            $remoteProgress = (int) ($job['progress_percent'] ?? 0);
            $mappedProgress = min(70, 30 + (int) round($remoteProgress * 0.4));
            $state['remote_progress_percent'] = $remoteProgress;
            $state['stage'] = 'remote_export_running';
            $this->persistState($localJobId, $state, $mappedProgress);

            sleep(self::POLL_DELAY_SECONDS);
        }

        throw new \RuntimeException('Remote export timed out.');
    }

    private function writeDownloadedFile(string $path, array $body): void
    {
        $encoded = (string) ($body['contents_b64'] ?? '');
        $expectedChecksum = (string) ($body['checksum_sha256'] ?? '');

        if ($encoded === '') {
            throw new \RuntimeException('Downloaded file payload is empty.');
        }

        $contents = base64_decode($encoded, true);

        if ($contents === false) {
            throw new \RuntimeException('Downloaded file payload could not be decoded.');
        }

        wp_mkdir_p(dirname($path));

        if (! is_dir(dirname($path))) {
            throw new \RuntimeException(sprintf('Could not create download directory for %s.', $path));
        }

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf('Could not write downloaded file %s.', $path));
        }

        if ($expectedChecksum !== '' && hash_file('sha256', $path) !== $expectedChecksum) {
            throw new \RuntimeException(sprintf('Checksum mismatch for downloaded file %s.', basename($path)));
        }
    }

    private function extractTableBundle(string $artifactDirectory, string $tableName, array $body): void
    {
        $safeTable = sanitize_title_with_dashes($tableName);
        $archivePath = $artifactDirectory . '/database/' . $safeTable . '.zip';
        $targetDirectory = $artifactDirectory . '/database/' . $safeTable;

        $this->writeDownloadedFile($archivePath, $body);
        wp_mkdir_p($targetDirectory);

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath);

        if ($opened !== true) {
            throw new \RuntimeException(sprintf('Could not open remote table bundle for %s.', $tableName));
        }

        $zip->extractTo($targetDirectory);
        $zip->close();

        if (! unlink($archivePath)) {
            throw new \RuntimeException(sprintf('Could not remove temporary table bundle %s.', $archivePath));
        }
    }

    private function requestJson(string $method, string $url, ?array $body = null, string $credential = '', int $timeout = 120): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($credential !== '') {
            $headers['X-Safe-Migrate-Session'] = $credential;
        }

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'body' => $body === null ? null : wp_json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : [];

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? 'Remote request failed.') : 'Remote request failed.';
            throw new \RuntimeException($message);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function progress(int $jobId, int $percent, string $message, array $payload, string $status = 'running'): void
    {
        $job = $this->jobs->find($jobId);
        $jobPayload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $jobPayload = array_replace_recursive($jobPayload, $payload);

        $this->jobs->update($jobId, [
            'status' => $status,
            'payload' => $jobPayload,
            'progress_percent' => $percent,
        ]);
        $this->logger->info($jobId, $message, ['progress_percent' => $percent] + $payload);
    }

    private function artifactDirectory(int $jobId): string
    {
        return ArtifactPaths::exportJobDirectory($jobId);
    }

    private function normalizeSourceUrl(string $sourceUrl): string
    {
        $sourceUrl = trim($sourceUrl);

        if ($sourceUrl === '') {
            throw new \RuntimeException('Push/Pull source_url is required.');
        }

        $validated = function_exists('wp_http_validate_url') ? wp_http_validate_url($sourceUrl) : false;

        if (is_string($validated) && $validated !== '') {
            return untrailingslashit($validated);
        }

        $parts = wp_parse_url($sourceUrl);

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new \RuntimeException('Push/Pull source_url must be a valid http or https URL.');
        }

        return untrailingslashit($sourceUrl);
    }

    private function baseUrl(string $sourceUrl): string
    {
        return untrailingslashit($sourceUrl) . '/wp-json/safe-migrate/v1';
    }

    private function ensureManifest(string $baseUrl, string $credential, int $remoteJobId, string $artifactDirectory, array &$state, int $jobId): array
    {
        $manifestPath = $artifactDirectory . '/manifest.json';

        if (! (bool) ($state['manifest_downloaded'] ?? false) || ! is_file($manifestPath)) {
            $manifestBody = $this->requestJson('GET', $baseUrl . '/remote/download/manifest/' . $remoteJobId, null, $credential);
            $this->writeDownloadedFile($manifestPath, $manifestBody);
            $state['manifest_downloaded'] = true;
            $state['manifest_checksum_sha256'] = (string) ($manifestBody['checksum_sha256'] ?? '');
            $state['stage'] = 'manifest_downloaded';
            $this->persistState($jobId, $state, 45, 'Manifest downloaded.', [], 'running', true);
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            throw new \RuntimeException('Downloaded manifest could not be decoded.');
        }

        return $manifest;
    }

    private function ensureFilesIndex(string $baseUrl, string $credential, int $remoteJobId, string $artifactDirectory, array &$state, int $jobId): void
    {
        $path = $artifactDirectory . '/files.json';

        if ((bool) ($state['files_index_downloaded'] ?? false) && is_file($path)) {
            return;
        }

        $filesIndexBody = $this->requestJson('GET', $baseUrl . '/remote/download/files-index/' . $remoteJobId, null, $credential);
        $this->writeDownloadedFile($path, $filesIndexBody);
        $state['files_index_downloaded'] = true;
        $state['files_index_checksum_sha256'] = (string) ($filesIndexBody['checksum_sha256'] ?? '');
        $state['stage'] = 'files_index_downloaded';
        $this->persistState($jobId, $state, 50, 'Files index downloaded.', [], 'running', true);
    }

    private function loadState(int $jobId, string $sourceUrl, string $artifactDirectory): array
    {
        $job = $this->jobs->find($jobId);
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $state = is_array($payload['push_pull_state'] ?? null) ? $payload['push_pull_state'] : [];

        $state['source_url'] = $sourceUrl;
        $state['artifact_directory'] = (string) ($state['artifact_directory'] ?? $artifactDirectory);
        $state['remote_session_id'] = (string) ($state['remote_session_id'] ?? '');
        $state['remote_session_token'] = (string) ($state['remote_session_token'] ?? '');
        $state['remote_export_job_id'] = (int) ($state['remote_export_job_id'] ?? 0);
        $state['remote_progress_percent'] = (int) ($state['remote_progress_percent'] ?? 0);
        $state['remote_preflight'] = is_array($state['remote_preflight'] ?? null) ? $state['remote_preflight'] : [];
        $state['remote_export'] = is_array($state['remote_export'] ?? null) ? $state['remote_export'] : [];
        $state['manifest_downloaded'] = (bool) ($state['manifest_downloaded'] ?? false);
        $state['manifest_checksum_sha256'] = (string) ($state['manifest_checksum_sha256'] ?? '');
        $state['files_index_downloaded'] = (bool) ($state['files_index_downloaded'] ?? false);
        $state['files_index_checksum_sha256'] = (string) ($state['files_index_checksum_sha256'] ?? '');
        $state['downloaded_chunks'] = array_values(array_unique(array_map('intval', (array) ($state['downloaded_chunks'] ?? []))));
        $state['downloaded_tables'] = array_values(array_unique(array_map('strval', (array) ($state['downloaded_tables'] ?? []))));
        $state['total_chunks'] = (int) ($state['total_chunks'] ?? 0);
        $state['total_tables'] = (int) ($state['total_tables'] ?? 0);
        $state['stage'] = (string) ($state['stage'] ?? 'initializing');

        return $state;
    }

    private function syncTransferCounts(array $state, array $manifest): array
    {
        $state['total_chunks'] = count((array) ($manifest['filesystem']['artifacts']['chunks'] ?? []));
        $state['total_tables'] = count((array) ($manifest['database']['segments']['tables'] ?? []));

        return $state;
    }

    private function publicProgress(array $state, ?string $stage = null): array
    {
        return [
            'stage' => $stage ?? (string) ($state['stage'] ?? ''),
            'artifact_directory' => (string) ($state['artifact_directory'] ?? ''),
            'remote_session_id' => (string) ($state['remote_session_id'] ?? ''),
            'remote_export_job_id' => (int) ($state['remote_export_job_id'] ?? 0),
            'remote_progress_percent' => (int) ($state['remote_progress_percent'] ?? 0),
            'downloaded_chunks' => count((array) ($state['downloaded_chunks'] ?? [])),
            'total_chunks' => (int) ($state['total_chunks'] ?? 0),
            'downloaded_tables' => count((array) ($state['downloaded_tables'] ?? [])),
            'total_tables' => (int) ($state['total_tables'] ?? 0),
        ];
    }

    private function persistState(
        int $jobId,
        array $state,
        int $percent,
        string $message = '',
        array $extraPayload = [],
        string $status = 'running',
        bool $log = false
    ): void {
        $job = $this->jobs->find($jobId);
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $payload['push_pull_state'] = $state;
        $payload['push_pull_progress'] = $this->publicProgress($state);
        $payload = array_replace_recursive($payload, $extraPayload);

        $this->jobs->update($jobId, [
            'status' => $status,
            'payload' => $payload,
            'progress_percent' => $percent,
        ]);

        if ($log && $message !== '') {
            $this->logger->info($jobId, $message, ['progress_percent' => $percent] + $payload['push_pull_progress']);
        }
    }

    private function persistTransferProgress(int $jobId, array $state): void
    {
        $chunkRatio = $state['total_chunks'] > 0
            ? count((array) $state['downloaded_chunks']) / max(1, $state['total_chunks'])
            : 1;
        $tableRatio = $state['total_tables'] > 0
            ? count((array) $state['downloaded_tables']) / max(1, $state['total_tables'])
            : 1;
        $percent = 50 + (int) round(($chunkRatio * 25) + ($tableRatio * 15));
        $this->persistState($jobId, $state, min(90, max(50, $percent)));
    }

    private function markChunkDownloaded(array $state, int $chunkIndex): array
    {
        if ($chunkIndex > 0 && ! in_array($chunkIndex, $state['downloaded_chunks'], true)) {
            $state['downloaded_chunks'][] = $chunkIndex;
            sort($state['downloaded_chunks']);
        }

        return $state;
    }

    private function markTableDownloaded(array $state, string $tableName): array
    {
        if ($tableName !== '' && ! in_array($tableName, $state['downloaded_tables'], true)) {
            $state['downloaded_tables'][] = $tableName;
            sort($state['downloaded_tables']);
        }

        return $state;
    }

    private function chunkReady(string $artifactDirectory, array $chunk): bool
    {
        $chunkIndex = (int) ($chunk['index'] ?? 0);
        $path = $artifactDirectory . '/chunks/chunk-' . str_pad((string) $chunkIndex, 3, '0', STR_PAD_LEFT) . '.zip';

        return $path !== '' && is_file($path) && $this->matchesChecksum($path, (string) ($chunk['checksum_sha256'] ?? ''));
    }

    private function tableReady(string $artifactDirectory, array $table): bool
    {
        $tableName = (string) ($table['table'] ?? '');

        if ($tableName === '') {
            return false;
        }

        $tableDirectory = $artifactDirectory . '/database/' . sanitize_title_with_dashes($tableName);
        $schemaPath = $tableDirectory . '/schema.sql';

        if (! is_file($schemaPath) || ! $this->matchesChecksum($schemaPath, (string) ($table['schema_checksum_sha256'] ?? ''))) {
            return false;
        }

        foreach ((array) ($table['parts'] ?? []) as $part) {
            $partPath = $tableDirectory . '/' . basename((string) ($part['path'] ?? ''));

            if (! is_file($partPath) || ! $this->matchesChecksum($partPath, (string) ($part['checksum_sha256'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    private function matchesChecksum(string $path, string $checksum): bool
    {
        if ($checksum === '') {
            return is_file($path);
        }

        $actual = hash_file('sha256', $path);

        return is_string($actual) && hash_equals($checksum, $actual);
    }
}

<?php

declare(strict_types=1);

namespace SafeMigrate\Support;

use SafeMigrate\Checkpoints\CheckpointRepository;
use SafeMigrate\Jobs\JobRepository;
use SafeMigrate\Logging\Logger;
use SafeMigrate\Product\FeaturePolicy;
use SafeMigrate\Product\SettingsService;

final class SupportBundleService
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly CheckpointRepository $checkpoints,
        private readonly Logger $logger,
        private readonly ?SettingsService $settingsService = null,
        private readonly ?FeaturePolicy $featurePolicy = null
    ) {
    }

    public function build(int $jobId, array $payload = []): array
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException('Job not found for support bundle.');
        }

        if ($payload !== []) {
            $job['payload'] = array_replace_recursive(
                is_array($job['payload'] ?? null) ? $job['payload'] : [],
                $payload
            );
        }

        $directory = $this->directory($jobId);
        $this->ensureDirectory($directory, 'support bundle');

        $bundle = [
            'generated_at' => current_time('mysql', true),
            'job' => $job,
            'support' => [
                'manifest_path' => (string) ($job['payload']['package_manifest_path'] ?? ''),
                'snapshot_path' => (string) ($job['payload']['snapshot_summary']['artifact_directory'] ?? ''),
                'restore_workspace_path' => (string) ($job['payload']['workspace']['base'] ?? ''),
                'failed_stage' => (string) ($job['payload']['failure']['stage'] ?? $job['payload']['current_stage'] ?? ''),
                'last_successful_checkpoint' => (string) ($job['payload']['support_bundle']['last_successful_checkpoint'] ?? ''),
            ],
            'compatibility' => [
                'detected_builders' => $this->compatibilityValue($job['payload'] ?? [], 'detected_builders'),
                'builder_warnings' => $this->compatibilityValue($job['payload'] ?? [], 'builder_warnings'),
            ],
            'environment' => [
                'home' => home_url('/'),
                'siteurl' => site_url('/'),
                'abspath' => ABSPATH,
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => SAFE_MIGRATE_VERSION,
            ],
            'checkpoints' => $this->checkpoints->forJob($jobId),
            'logs' => $this->logger->forJob($jobId),
        ];
        $redacted = false;

        if ($this->shouldRedactSensitive()) {
            $bundle = $this->redactRecursive($bundle);
            $redacted = true;
        }

        $path = trailingslashit($directory) . 'support-bundle.json';
        $encoded = wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Could not encode support bundle payload.');
        }

        if (file_put_contents($path, $encoded) === false) {
            throw new \RuntimeException(sprintf('Could not write support bundle %s.', $path));
        }

        return [
            'directory' => $directory,
            'path' => $path,
            'generated_at' => $bundle['generated_at'],
            'checkpoint_count' => count($bundle['checkpoints']),
            'log_count' => count($bundle['logs']),
            'redacted' => $redacted,
        ];
    }

    private function directory(int $jobId): string
    {
        return ArtifactPaths::supportJobDirectory($jobId);
    }

    private function shouldRedactSensitive(): bool
    {
        if ($this->settingsService === null || $this->featurePolicy === null) {
            return false;
        }

        if (! $this->featurePolicy->allows(FeaturePolicy::SUPPORT_BUNDLE_REDACTION)) {
            return false;
        }

        $settings = $this->settingsService->get();

        return (bool) ($settings['support_bundle']['redact_sensitive'] ?? false);
    }

    private function ensureDirectory(string $directory, string $label): void
    {
        wp_mkdir_p($directory);

        if (! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create %s directory %s.', $label, $directory));
        }
    }

    private function redactRecursive(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redactRecursive($childValue, (string) $childKey);
            }

            return $redacted;
        }

        if (! is_string($value)) {
            return $value;
        }

        if ($key !== '' && preg_match('/password|secret|token|license|key/i', $key) === 1) {
            return '[redacted]';
        }

        return preg_replace('/([A-Za-z0-9]{4})[A-Za-z0-9\-_]{8,}/', '$1[redacted]', $value) ?? $value;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function compatibilityValue(array $payload, string $key): array
    {
        $candidates = [
            $payload['report'] ?? [],
            $payload['restore_preview'] ?? [],
            $payload['verification'] ?? [],
            $payload['restore_simulation'] ?? [],
            $payload['export_summary'] ?? [],
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate[$key]) && is_array($candidate[$key])) {
                return $candidate[$key];
            }
        }

        return [];
    }
}

<?php

declare(strict_types=1);

namespace SafeMigrate\Diagnostics;

use SafeMigrate\Compatibility\BuilderDetector;
use SafeMigrate\Compatibility\BuilderRegistry;

final class PreflightRunner
{
    public function __construct(
        private readonly BuilderDetector $builderDetector,
        private readonly ?BuilderRegistry $builderRegistry = null
    )
    {
    }

    public function run(): array
    {
        $checks = [
            $this->phpVersionCheck(),
            $this->memoryLimitCheck(),
            $this->executionTimeCheck(),
            $this->wpContentWritableCheck(),
            $this->uploadsWritableCheck(),
            $this->diskSpaceCheck(),
            $this->restLoopbackCheck(),
            $this->builderDetectionCheck(),
        ];

        $blockers = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail'));
        $warnings = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'warn'));
        $healthScore = max(0, 100 - (count($blockers) * 30) - (count($warnings) * 10));

        return [
            'health_score' => $healthScore,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'blockers' => $blockers,
            'warnings' => $warnings,
            'checks' => $checks,
            'detected_builders' => $this->builderDetector->detect(),
            'builder_warnings' => ($this->builderRegistry ?? new BuilderRegistry())->warnings($this->builderDetector->detect()),
            'generated_at' => current_time('mysql', true),
        ];
    }

    private function phpVersionCheck(): array
    {
        $status = version_compare(PHP_VERSION, '8.2', '>=') ? 'pass' : 'fail';

        return $this->check('php_version', 'PHP version', $status, sprintf('Current PHP version: %s', PHP_VERSION));
    }

    private function memoryLimitCheck(): array
    {
        $raw = ini_get('memory_limit');
        $bytes = $this->toBytes($raw);
        $status = $bytes >= 268435456 ? 'pass' : 'warn';

        return $this->check('memory_limit', 'Memory limit', $status, sprintf('Configured memory limit: %s', (string) $raw));
    }

    private function executionTimeCheck(): array
    {
        $seconds = (int) ini_get('max_execution_time');
        $status = $seconds === 0 || $seconds >= 30 ? 'pass' : 'warn';

        return $this->check(
            'max_execution_time',
            'Execution time limit',
            $status,
            sprintf('Configured max_execution_time: %d seconds', $seconds)
        );
    }

    private function wpContentWritableCheck(): array
    {
        $status = is_writable(WP_CONTENT_DIR) ? 'pass' : 'fail';

        return $this->check('wp_content_writable', 'wp-content writable', $status, sprintf('Path checked: %s', WP_CONTENT_DIR));
    }

    private function uploadsWritableCheck(): array
    {
        $uploads = wp_get_upload_dir();
        $path = $uploads['basedir'] ?? (WP_CONTENT_DIR . '/uploads');
        $status = is_writable($path) ? 'pass' : 'fail';

        return $this->check('uploads_writable', 'Uploads writable', $status, sprintf('Path checked: %s', $path));
    }

    private function diskSpaceCheck(): array
    {
        $freeBytes = @disk_free_space(WP_CONTENT_DIR);
        $status = is_numeric($freeBytes) && $freeBytes >= 536870912 ? 'pass' : 'warn';
        $message = is_numeric($freeBytes)
            ? sprintf('Free disk space near wp-content: %s MB', number_format_i18n(((float) $freeBytes) / 1048576, 0))
            : 'Disk free space could not be determined.';

        return $this->check('disk_space', 'Disk space', $status, $message);
    }

    private function restLoopbackCheck(): array
    {
        $primaryUrl = rest_url('/');
        $response = $this->performLoopbackRequest($primaryUrl);

        if (is_wp_error($response) || $this->isUnexpectedLoopbackCode($response)) {
            foreach ($this->fallbackLoopbackUrls($primaryUrl) as $fallbackUrl) {
                $fallbackResponse = $this->performLoopbackRequest($fallbackUrl);

                if (! is_wp_error($fallbackResponse) && ! $this->isUnexpectedLoopbackCode($fallbackResponse)) {
                    return $this->check(
                        'rest_loopback',
                        'REST loopback',
                        'pass',
                        sprintf('Primary host failed, but loopback succeeded via %s.', $fallbackUrl)
                    );
                }
            }
        }

        if (is_wp_error($response)) {
            return $this->check(
                'rest_loopback',
                'REST loopback',
                'warn',
                sprintf('Loopback request failed: %s', $response->get_error_message())
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $status = $code >= 200 && $code < 400 ? 'pass' : 'warn';

        return $this->check('rest_loopback', 'REST loopback', $status, sprintf('Loopback response code: %d', $code));
    }

    private function check(string $key, string $label, string $status, string $message): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function builderDetectionCheck(): array
    {
        $builders = $this->builderDetector->detect();
        $warnings = ($this->builderRegistry ?? new BuilderRegistry())->warnings($builders);

        if ($builders === []) {
            return $this->check(
                'builder_detection',
                'Builder detection',
                'pass',
                'No supported builders detected.'
            );
        }

        $names = array_map(
            static fn (array $builder): string => sprintf('%s (%s)', $builder['name'], $builder['status']),
            $builders
        );

        return $this->check(
            'builder_detection',
            'Builder detection',
            $warnings === [] ? 'pass' : 'warn',
            'Detected builders: ' . implode(', ', $names) . ($warnings === [] ? '' : ' | ' . implode(' | ', $warnings))
        );
    }

    private function toBytes(string|false $value): int
    {
        if ($value === false || $value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $trimmed = trim($value);
        $unit = strtolower(substr($trimmed, -1));
        $number = (int) $trimmed;

        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function performLoopbackRequest(string $url): array|\WP_Error
    {
        return wp_remote_get(
            $url,
            [
                'timeout' => 10,
                'sslverify' => false,
            ]
        );
    }

    private function isUnexpectedLoopbackCode(array|\WP_Error $response): bool
    {
        if (is_wp_error($response)) {
            return true;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return $code < 200 || $code >= 400;
    }

    /**
     * @return array<int, string>
     */
    private function fallbackLoopbackUrls(string $url): array
    {
        $parts = wp_parse_url($url);
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = $parts['path'] ?? '/wp-json/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return [
            sprintf('%s://127.0.0.1%s%s%s', $scheme, $port, $path, $query),
            sprintf('%s://wordpress%s%s%s', $scheme, $port, $path, $query),
        ];
    }
}

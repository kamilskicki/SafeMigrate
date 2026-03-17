<?php

declare(strict_types=1);

namespace SafeMigrate\Support;

final class ArtifactPaths
{
    public static function exportJobDirectory(int $jobId): string
    {
        return trailingslashit(self::uploadsBaseDirectory()) . 'safe-migrate-export-job-' . $jobId;
    }

    public static function restoreJobDirectory(int $jobId): string
    {
        return trailingslashit(self::uploadsBaseDirectory()) . 'safe-migrate-restore-job-' . $jobId;
    }

    public static function supportJobDirectory(int $jobId): string
    {
        return trailingslashit(self::uploadsBaseDirectory()) . 'safe-migrate-support-job-' . $jobId;
    }

    /**
     * @return array<int, string>
     */
    public static function supportDirectories(): array
    {
        return self::jobDirectories([
            trailingslashit(self::uploadsBaseDirectory()) . 'safe-migrate-support-job-*',
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function exportDirectories(): array
    {
        return self::jobDirectories([
            trailingslashit(self::uploadsBaseDirectory()) . 'safe-migrate-export-job-*',
            trailingslashit(self::legacyBaseDirectory()) . 'exports/job-*',
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function restoreDirectories(): array
    {
        return self::jobDirectories([
            trailingslashit(self::uploadsBaseDirectory()) . 'safe-migrate-restore-job-*',
            trailingslashit(self::legacyBaseDirectory()) . 'restores/job-*',
        ]);
    }

    public static function legacyBaseDirectory(): string
    {
        return trailingslashit(self::uploadsBaseDirectory()) . 'safe-migrate';
    }

    private static function uploadsBaseDirectory(): string
    {
        $uploads = wp_get_upload_dir();

        return (string) ($uploads['basedir'] ?? (WP_CONTENT_DIR . '/uploads'));
    }

    /**
     * @param array<int, string> $patterns
     * @return array<int, string>
     */
    private static function jobDirectories(array $patterns): array
    {
        $directories = [];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                if (is_dir($path)) {
                    $directories[] = $path;
                }
            }
        }

        $directories = array_values(array_unique($directories));

        usort(
            $directories,
            static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left)
        );

        return $directories;
    }
}

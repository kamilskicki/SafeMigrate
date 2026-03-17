<?php

declare(strict_types=1);

namespace SafeMigrate\Support;

use SafeMigrate\Jobs\Capabilities;
use SafeMigrate\Jobs\JobService;
use SafeMigrate\Product\LicenseStateService;
use SafeMigrate\Product\SettingsService;
use SafeMigrate\Remote\TransferSessionService;
use SafeMigrate\Testing\FailureInjectionService;
use wpdb;

final class PluginCleanup
{
    public static function deactivate(): void
    {
        delete_option(JobService::LOCK_OPTION);
        delete_option(FailureInjectionService::OPTION);
    }

    public static function uninstall(wpdb $wpdb): void
    {
        self::deactivate();
        self::revokeCapabilities();
        self::deleteOptions($wpdb);
        self::dropTables($wpdb);
        self::deleteArtifacts();
    }

    private static function revokeCapabilities(): void
    {
        $role = get_role('administrator');

        if ($role === null) {
            return;
        }

        $role->remove_cap(Capabilities::MANAGE);
        $role->remove_cap(Capabilities::DESTRUCTIVE);
    }

    private static function deleteOptions(wpdb $wpdb): void
    {
        delete_option('safe_migrate_schema_version');
        delete_option(SettingsService::OPTION);
        delete_option(LicenseStateService::OPTION);
        delete_option(FailureInjectionService::OPTION);
        delete_option(JobService::LOCK_OPTION);

        if (! isset($wpdb->options)) {
            return;
        }

        $tokenLike = $wpdb->esc_like(TransferSessionService::TOKEN_OPTION_PREFIX) . '%';
        $sessionLike = $wpdb->esc_like(TransferSessionService::SESSION_OPTION_PREFIX) . '%';

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s',
                $tokenLike,
                $sessionLike
            )
        );
    }

    private static function dropTables(wpdb $wpdb): void
    {
        foreach ([
            $wpdb->prefix . 'safe_migrate_jobs',
            $wpdb->prefix . 'safe_migrate_checkpoints',
            $wpdb->prefix . 'safe_migrate_logs',
        ] as $table) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private static function deleteArtifacts(): void
    {
        foreach (array_merge(
            ArtifactPaths::exportDirectories(),
            ArtifactPaths::restoreDirectories(),
            ArtifactPaths::supportDirectories()
        ) as $directory) {
            self::deleteDirectory($directory);
        }

        $legacy = ArtifactPaths::legacyBaseDirectory();

        if (is_dir($legacy) && self::directoryIsEmpty($legacy)) {
            @rmdir($legacy);
        }
    }

    private static function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $pathname = $item->getPathname();

            if ($item->isDir()) {
                @rmdir($pathname);
                continue;
            }

            @unlink($pathname);
        }

        @rmdir($path);
    }

    private static function directoryIsEmpty(string $path): bool
    {
        $items = scandir($path);

        if (! is_array($items)) {
            return false;
        }

        return count(array_diff($items, ['.', '..'])) === 0;
    }
}

<?php

declare(strict_types=1);

namespace SafeMigrate\Compatibility;

final class OxygenAdapter extends AbstractBuilderAdapter
{
    public function slug(): string
    {
        return 'oxygen';
    }

    public function name(): string
    {
        return 'Oxygen';
    }

    public function family(): string
    {
        return 'builder';
    }

    public function matchPatterns(): array
    {
        return ['oxygen/'];
    }

    public function warnings(array $builder): array
    {
        return [
            'Oxygen detected. Safe Migrate will verify generated assets and Oxygen-specific tables after restore.',
        ];
    }

    public function verify(array $manifest, array $restoreSummary): array
    {
        global $wpdb;

        $issues = [];
        $oxygenUploads = WP_CONTENT_DIR . '/uploads/oxygen';

        if ($this->manifestIncludesOxygenUploads($manifest) && ! is_dir($oxygenUploads)) {
            $issues[] = 'Oxygen uploads directory is missing after restore.';
        }

        $table = $wpdb->prefix . 'oxygen_icons';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($this->manifestIncludesTable($manifest, $table) && $exists !== $table) {
            $issues[] = 'Oxygen icons table is missing after restore.';
        }

        return $issues;
    }

    private function manifestIncludesOxygenUploads(array $manifest): bool
    {
        $filesIndexPath = (string) ($manifest['artifacts']['files_index'] ?? '');

        if ($filesIndexPath === '' || ! is_file($filesIndexPath)) {
            return false;
        }

        $files = json_decode((string) file_get_contents($filesIndexPath), true);

        if (! is_array($files)) {
            return false;
        }

        foreach ($files as $file) {
            if (str_starts_with((string) ($file['path'] ?? ''), 'wp-content/uploads/oxygen/')) {
                return true;
            }
        }

        return false;
    }

    private function manifestIncludesTable(array $manifest, string $tableName): bool
    {
        foreach (($manifest['database']['segments']['tables'] ?? []) as $table) {
            if ((string) ($table['table'] ?? '') === $tableName) {
                return true;
            }
        }

        return false;
    }
}

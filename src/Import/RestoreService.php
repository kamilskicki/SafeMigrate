<?php

declare(strict_types=1);

namespace SafeMigrate\Import;

use SafeMigrate\Remap\RemapEngine;
use ZipArchive;

final class RestoreService
{
    public function __construct(private readonly RemapEngine $remapEngine)
    {
    }

    public function previewFilesystemRestore(array $manifest, array $workspace): array
    {
        $artifacts = $manifest['filesystem']['artifacts']['chunks'] ?? [];
        $restored = [];

        foreach ($artifacts as $chunk) {
            $path = (string) ($chunk['path'] ?? '');

            if ($path === '' || ! is_file($path)) {
                continue;
            }

            if (str_ends_with($path, '.zip')) {
                $this->extractZip($path, trailingslashit($workspace['files']));
            }

            $restored[] = [
                'index' => (int) ($chunk['index'] ?? 0),
                'path' => $path,
                'checksum_sha256' => (string) ($chunk['checksum_sha256'] ?? ''),
            ];
        }

        return [
            'restored_chunks' => count($restored),
            'chunks' => $restored,
        ];
    }

    public function applyFilesystemRestore(array $manifest, string $destination = ABSPATH): array
    {
        $artifacts = $manifest['filesystem']['artifacts']['chunks'] ?? [];
        $applied = [];

        foreach ($artifacts as $chunk) {
            $path = (string) ($chunk['path'] ?? '');

            if ($path === '' || ! is_file($path)) {
                continue;
            }

            if (str_ends_with($path, '.zip')) {
                $this->extractZip($path, trailingslashit($destination));
            }

            $applied[] = [
                'index' => (int) ($chunk['index'] ?? 0),
                'path' => $path,
                'checksum_sha256' => (string) ($chunk['checksum_sha256'] ?? ''),
            ];
        }

        return [
            'applied_chunks' => count($applied),
            'chunks' => $applied,
        ];
    }

    /**
     * @param array<string, string> $rules
     */
    public function previewDatabaseRestore(array $manifest, array $workspace, array $rules = []): array
    {
        $segments = $manifest['database']['segments']['tables'] ?? [];
        $staged = [];

        foreach ($segments as $table) {
            $schemaPath = (string) ($table['schema_path'] ?? '');
            $tableDirectory = trailingslashit($workspace['database']) . sanitize_title_with_dashes((string) ($table['table'] ?? 'table'));
            wp_mkdir_p($tableDirectory);

            if ($schemaPath !== '' && is_file($schemaPath)) {
                $schemaSql = (string) file_get_contents($schemaPath);
                file_put_contents($tableDirectory . '/schema.sql', $this->remapEngine->remapString($schemaSql, $rules));
            }

            foreach (($table['parts'] ?? []) as $part) {
                $partPath = (string) ($part['path'] ?? '');

                if ($partPath === '' || ! is_file($partPath)) {
                    continue;
                }

                $sql = (string) file_get_contents($partPath);
                $targetPath = $tableDirectory . '/' . basename($partPath);
                file_put_contents($targetPath, $this->remapEngine->remapString($sql, $rules));
                $staged[] = $targetPath;
            }
        }

        return [
            'staged_segments' => count($staged),
            'segments' => $staged,
        ];
    }

    public function applyDatabaseRestore(array $workspace): array
    {
        global $wpdb;

        $databaseDirectory = (string) ($workspace['database'] ?? '');

        if ($databaseDirectory === '' || ! is_dir($databaseDirectory)) {
            throw new \RuntimeException('Restore workspace database directory is missing.');
        }

        $applied = [];
        $directories = glob(trailingslashit($databaseDirectory) . '*', GLOB_ONLYDIR) ?: [];
        sort($directories);

        foreach ($directories as $directory) {
            $schemaPath = trailingslashit($directory) . 'schema.sql';

            if (is_file($schemaPath)) {
                $this->executeSqlStatements($wpdb, (string) file_get_contents($schemaPath));
            }

            $parts = glob(trailingslashit($directory) . 'part-*.sql') ?: [];
            sort($parts);

            foreach ($parts as $part) {
                $this->executeSqlStatements($wpdb, (string) file_get_contents($part));
                $applied[] = $part;
            }
        }

        return [
            'applied_segments' => count($applied),
            'segments' => $applied,
        ];
    }

    private function extractZip(string $path, string $destination): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new \RuntimeException(sprintf('Could not open chunk %s.', $path));
        }

        $zip->extractTo($destination);
        $zip->close();
    }

    private function executeSqlStatements(\wpdb $wpdb, string $sql): void
    {
        $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql) ?: []));

        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }

            $wpdb->query($statement);
        }
    }
}

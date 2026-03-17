<?php

declare(strict_types=1);

namespace SafeMigrate\Import;

final class PackageValidator
{
    /**
     * @param array<int, string> $allowedKinds
     */
    public function validate(array $package, array $allowedKinds = ['safe-migrate-export']): array
    {
        $manifest = $package['manifest'] ?? null;
        $rawManifest = $package['raw_manifest'] ?? $manifest;

        if (! is_array($manifest)) {
            throw new \RuntimeException('Package manifest is missing.');
        }

        $issues = [];
        $manifestPath = (string) ($package['manifest_path'] ?? '');
        $expectedChecksum = (string) ($rawManifest['package']['integrity']['manifest_checksum_sha256'] ?? '');
        $filesIndexPath = untrailingslashit((string) $package['artifact_directory']) . '/files.json';
        $expectedFilesIndexChecksum = (string) ($rawManifest['package']['integrity']['files_index_checksum_sha256'] ?? '');

        if (! in_array((string) ($rawManifest['package']['kind'] ?? ''), $allowedKinds, true)) {
            $issues[] = 'Unsupported package kind.';
        }

        if ($expectedChecksum !== '' && is_file($manifestPath)) {
            $actualChecksum = $this->canonicalManifestChecksum(is_array($rawManifest) ? $rawManifest : $manifest);

            if ($actualChecksum !== $expectedChecksum) {
                $issues[] = 'Manifest checksum mismatch.';
            }
        }

        if ($expectedFilesIndexChecksum !== '' && is_file($filesIndexPath)) {
            if (hash_file('sha256', $filesIndexPath) !== $expectedFilesIndexChecksum) {
                $issues[] = 'Files index checksum mismatch.';
            }
        }

        if (! isset($manifest['filesystem']['artifacts']['chunks']) && ! is_dir(untrailingslashit((string) $package['artifact_directory']) . '/chunks')) {
            $issues[] = 'Filesystem chunks directory is missing.';
        }

        if (! isset($manifest['database']['segments']['tables']) && ! is_dir(untrailingslashit((string) $package['artifact_directory']) . '/database')) {
            $issues[] = 'Database segments directory is missing.';
        }

        foreach (($manifest['filesystem']['artifacts']['chunks'] ?? []) as $chunk) {
            $path = (string) ($chunk['path'] ?? '');
            $checksum = (string) ($chunk['checksum_sha256'] ?? '');

            if ($path === '' || ! is_file($path)) {
                $issues[] = sprintf('Missing filesystem chunk: %s', $path);
                continue;
            }

            if ($checksum !== '' && hash_file('sha256', $path) !== $checksum) {
                $issues[] = sprintf('Filesystem chunk checksum mismatch: %s', basename($path));
            }
        }

        foreach (($manifest['database']['segments']['tables'] ?? []) as $table) {
            $schemaPath = (string) ($table['schema_path'] ?? '');
            $schemaChecksum = (string) ($table['schema_checksum_sha256'] ?? '');

            if ($schemaPath === '' || ! is_file($schemaPath)) {
                $issues[] = sprintf('Missing database schema file for table %s.', (string) ($table['table'] ?? 'unknown'));
                continue;
            }

            if ($schemaChecksum !== '' && hash_file('sha256', $schemaPath) !== $schemaChecksum) {
                $issues[] = sprintf('Schema checksum mismatch for table %s.', (string) ($table['table'] ?? 'unknown'));
            }

            foreach (($table['parts'] ?? []) as $part) {
                $partPath = (string) ($part['path'] ?? '');
                $partChecksum = (string) ($part['checksum_sha256'] ?? '');

                if ($partPath === '' || ! is_file($partPath)) {
                    $issues[] = sprintf('Missing database segment: %s', $partPath);
                    continue;
                }

                if ($partChecksum !== '' && hash_file('sha256', $partPath) !== $partChecksum) {
                    $issues[] = sprintf('Database segment checksum mismatch: %s', basename($partPath));
                }
            }
        }

        return [
            'status' => $issues === [] ? 'valid' : 'invalid',
            'issues' => $issues,
        ];
    }

    private function canonicalManifestChecksum(array $manifest): string
    {
        $canonical = $manifest;
        $canonical['package']['integrity']['manifest_checksum_sha256'] = '';

        return hash(
            'sha256',
            (string) wp_json_encode($canonical, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}

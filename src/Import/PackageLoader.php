<?php

declare(strict_types=1);

namespace SafeMigrate\Import;

final class PackageLoader
{
    public function load(string $artifactDirectory): array
    {
        $manifestPath = untrailingslashit($artifactDirectory) . '/manifest.json';

        if (! is_file($manifestPath)) {
            throw new \RuntimeException(sprintf('Manifest not found at %s.', $manifestPath));
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            throw new \RuntimeException('Manifest could not be decoded.');
        }

        return [
            'artifact_directory' => $artifactDirectory,
            'manifest_path' => $manifestPath,
            'raw_manifest' => $manifest,
            'manifest' => $this->normalizeManifestPaths($manifest, $artifactDirectory, $manifestPath),
        ];
    }

    private function normalizeManifestPaths(array $manifest, string $artifactDirectory, string $manifestPath): array
    {
        $currentBase = untrailingslashit($artifactDirectory);
        $originalBase = untrailingslashit((string) ($manifest['artifacts']['directory'] ?? dirname($manifestPath)));

        $manifest['artifacts']['directory'] = $currentBase;
        $manifest['artifacts']['manifest'] = $currentBase . '/manifest.json';
        $manifest['artifacts']['files_index'] = $currentBase . '/files.json';

        if (isset($manifest['filesystem']['artifacts']['directory'])) {
            $manifest['filesystem']['artifacts']['directory'] = $this->rebasePath(
                (string) $manifest['filesystem']['artifacts']['directory'],
                $originalBase,
                $currentBase
            );
        }

        foreach (($manifest['filesystem']['artifacts']['chunks'] ?? []) as $index => $chunk) {
            $manifest['filesystem']['artifacts']['chunks'][$index]['path'] = $this->rebasePath(
                (string) ($chunk['path'] ?? ''),
                $originalBase,
                $currentBase
            );
        }

        foreach (($manifest['database']['segments']['tables'] ?? []) as $tableIndex => $table) {
            $manifest['database']['segments']['tables'][$tableIndex]['schema_path'] = $this->rebasePath(
                (string) ($table['schema_path'] ?? ''),
                $originalBase,
                $currentBase
            );

            foreach (($table['parts'] ?? []) as $partIndex => $part) {
                $manifest['database']['segments']['tables'][$tableIndex]['parts'][$partIndex]['path'] = $this->rebasePath(
                    (string) ($part['path'] ?? ''),
                    $originalBase,
                    $currentBase
                );
            }
        }

        return $manifest;
    }

    private function rebasePath(string $path, string $originalBase, string $currentBase): string
    {
        if ($path === '') {
            return '';
        }

        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedOriginalBase = rtrim(str_replace('\\', '/', $originalBase), '/');
        $normalizedCurrentBase = rtrim(str_replace('\\', '/', $currentBase), '/');

        if ($normalizedOriginalBase !== '' && str_starts_with($normalizedPath, $normalizedOriginalBase . '/')) {
            return $normalizedCurrentBase . substr($normalizedPath, strlen($normalizedOriginalBase));
        }

        if (! str_starts_with($normalizedPath, '/')) {
            return $normalizedCurrentBase . '/' . ltrim($normalizedPath, '/');
        }

        return $normalizedPath;
    }
}

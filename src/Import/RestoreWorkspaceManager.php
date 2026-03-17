<?php

declare(strict_types=1);

namespace SafeMigrate\Import;

use SafeMigrate\Support\ArtifactPaths;

final class RestoreWorkspaceManager
{
    public function create(int $jobId): array
    {
        $base = ArtifactPaths::restoreJobDirectory($jobId);
        $files = $base . '/files';
        $database = $base . '/database';
        $meta = $base . '/meta';
        $snapshot = $base . '/snapshot';

        $this->ensureDirectory($files, 'restore workspace files');
        $this->ensureDirectory($database, 'restore workspace database');
        $this->ensureDirectory($meta, 'restore workspace metadata');
        $this->ensureDirectory($snapshot, 'restore workspace snapshot');

        return [
            'base' => $base,
            'files' => $files,
            'database' => $database,
            'meta' => $meta,
            'snapshot' => $snapshot,
        ];
    }

    public function writeCheckpoint(array $workspace, string $stage, array $payload): string
    {
        $path = trailingslashit($workspace['meta']) . sanitize_title_with_dashes($stage) . '.json';
        $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || $encoded === '') {
            throw new \RuntimeException(sprintf('Could not encode restore checkpoint %s.', $stage));
        }

        if (file_put_contents($path, $encoded) === false) {
            throw new \RuntimeException(sprintf('Could not write restore checkpoint %s.', $path));
        }

        return $path;
    }

    private function ensureDirectory(string $directory, string $label): void
    {
        wp_mkdir_p($directory);

        if (! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create %s directory %s.', $label, $directory));
        }
    }
}

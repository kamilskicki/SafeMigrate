<?php

declare(strict_types=1);

namespace SafeMigrate\Export;

use ZipArchive;

final class FilesystemChunkWriter
{
    public function write(array $manifest): array
    {
        $artifactDirectory = (string) ($manifest['artifacts']['directory'] ?? '');
        $filesIndexPath = (string) ($manifest['artifacts']['files_index'] ?? '');
        $chunksDirectory = $artifactDirectory . '/chunks';
        $this->ensureDirectory($chunksDirectory, 'filesystem chunks');

        if ($filesIndexPath === '' || ! is_file($filesIndexPath)) {
            throw new \RuntimeException(sprintf('Files index does not exist at %s.', $filesIndexPath));
        }

        $rawFiles = file_get_contents($filesIndexPath);

        if (! is_string($rawFiles)) {
            throw new \RuntimeException(sprintf('Could not read files index %s.', $filesIndexPath));
        }

        $files = json_decode($rawFiles, true);

        if (! is_array($files)) {
            throw new \RuntimeException(sprintf('Files index %s could not be decoded.', $filesIndexPath));
        }

        $grouped = [];

        foreach ($files as $file) {
            $chunkIndex = (int) ($file['chunk'] ?? 0);

            if ($chunkIndex < 1) {
                continue;
            }

            $grouped[$chunkIndex][] = $file;
        }

        ksort($grouped);

        $chunks = [];

        foreach ($grouped as $chunkIndex => $chunkFiles) {
            $chunks[] = class_exists(ZipArchive::class)
                ? $this->writeZipChunk($chunkIndex, $chunkFiles, $chunksDirectory)
                : $this->writeDirectoryChunk($chunkIndex, $chunkFiles, $chunksDirectory);
        }

        return [
            'directory' => $chunksDirectory,
            'format' => class_exists(ZipArchive::class) ? 'zip' : 'directory',
            'chunks' => $chunks,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $chunkFiles
     */
    private function writeZipChunk(int $chunkIndex, array $chunkFiles, string $chunksDirectory): array
    {
        $path = sprintf('%s/chunk-%03d.zip', $chunksDirectory, $chunkIndex);
        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new \RuntimeException(sprintf('Could not create zip chunk %s.', $path));
        }

        foreach ($chunkFiles as $file) {
            $absolute = ABSPATH . ltrim((string) $file['path'], '/');

            if (! is_file($absolute)) {
                continue;
            }

            $zip->addFile($absolute, (string) $file['path']);
        }

        $zip->close();
        $checksum = $this->checksum($path, sprintf('filesystem chunk %d', $chunkIndex));

        return [
            'index' => $chunkIndex,
            'path' => $path,
            'size' => is_file($path) ? filesize($path) : 0,
            'checksum_sha256' => $checksum,
            'file_count' => count($chunkFiles),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $chunkFiles
     */
    private function writeDirectoryChunk(int $chunkIndex, array $chunkFiles, string $chunksDirectory): array
    {
        $path = sprintf('%s/chunk-%03d', $chunksDirectory, $chunkIndex);
        $this->ensureDirectory($path, sprintf('filesystem chunk directory %d', $chunkIndex));

        foreach ($chunkFiles as $file) {
            $relative = ltrim((string) $file['path'], '/');
            $source = ABSPATH . $relative;
            $target = $path . '/' . $relative;

            if (! is_file($source)) {
                continue;
            }

            $this->ensureDirectory(dirname($target), sprintf('filesystem chunk directory for %s', $relative));

            if (! copy($source, $target)) {
                throw new \RuntimeException(sprintf('Could not copy filesystem file %s into chunk %d.', $relative, $chunkIndex));
            }
        }

        return [
            'index' => $chunkIndex,
            'path' => $path,
            'size' => 0,
            'checksum_sha256' => '',
            'file_count' => count($chunkFiles),
        ];
    }

    private function ensureDirectory(string $directory, string $label): void
    {
        if ($directory === '') {
            throw new \RuntimeException(sprintf('Path for %s is empty.', $label));
        }

        wp_mkdir_p($directory);

        if (! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create %s directory at %s.', $label, $directory));
        }
    }

    private function checksum(string $path, string $label): string
    {
        if (! is_file($path)) {
            throw new \RuntimeException(sprintf('Expected %s file does not exist at %s.', $label, $path));
        }

        $checksum = hash_file('sha256', $path);

        if (! is_string($checksum) || $checksum === '') {
            throw new \RuntimeException(sprintf('Could not checksum %s file %s.', $label, $path));
        }

        return $checksum;
    }
}

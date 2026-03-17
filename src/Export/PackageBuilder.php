<?php

declare(strict_types=1);

namespace SafeMigrate\Export;

final class PackageBuilder
{
    public function __construct(
        private readonly ExportPlanBuilder $exportPlanBuilder,
        private readonly DatabaseSegmentExporter $databaseSegmentExporter,
        private readonly FilesystemChunkWriter $filesystemChunkWriter
    ) {
    }

    public function build(int $jobId, string $packageKind = 'safe-migrate-export', ?string $artifactDirectory = null): array
    {
        $plan = $this->exportPlanBuilder->build(
            $jobId,
            [
                'artifact_directory' => $artifactDirectory,
            ]
        );

        $database = $this->databaseSegmentExporter->export($plan['manifest']);
        $filesystem = $this->filesystemChunkWriter->write($plan['manifest']);
        $summary = $this->finalizeManifest($plan['manifest'], $database, $filesystem, $packageKind);

        return [
            'summary' => $summary,
            'manifest' => $plan['manifest'],
            'database' => $database,
            'filesystem' => $filesystem,
        ];
    }

    private function finalizeManifest(array $manifest, array $database, array $filesystem, string $packageKind): array
    {
        $manifest['database']['segments'] = $database;
        $manifest['filesystem']['artifacts'] = $filesystem;
        $manifest['package'] = [
            'status' => 'ready',
            'generated_at' => current_time('mysql', true),
            'kind' => $packageKind,
            'integrity' => [
                'manifest_checksum_sha256' => '',
                'files_index_checksum_sha256' => '',
            ],
        ];

        $manifestPath = (string) $manifest['artifacts']['manifest'];
        $filesIndexPath = (string) $manifest['artifacts']['files_index'];
        $this->ensureArtifactDirectory((string) ($manifest['artifacts']['directory'] ?? ''));
        $manifest['package']['integrity']['files_index_checksum_sha256'] = $this->requiredChecksum($filesIndexPath, 'files index');
        $manifest['package']['integrity']['manifest_checksum_sha256'] = $this->canonicalManifestChecksum($manifest);
        $encoded = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Could not encode finalized manifest.');
        }

        if (file_put_contents($manifestPath, $encoded) === false) {
            throw new \RuntimeException(sprintf('Could not write finalized manifest to %s.', $manifestPath));
        }

        return [
            'artifact_directory' => (string) $manifest['artifacts']['directory'],
            'manifest_path' => $manifestPath,
            'files_index_path' => (string) $manifest['artifacts']['files_index'],
            'total_files' => (int) $manifest['filesystem']['total_files'],
            'total_bytes' => (int) $manifest['filesystem']['total_bytes'],
            'file_chunk_count' => count($filesystem['chunks'] ?? []),
            'database_tables' => (int) $manifest['database']['total_tables'],
            'database_bytes' => (int) $manifest['database']['total_bytes'],
            'database_segment_count' => (int) ($database['segment_count'] ?? 0),
            'filesystem_format' => (string) ($filesystem['format'] ?? 'unknown'),
            'manifest_checksum_sha256' => (string) $manifest['package']['integrity']['manifest_checksum_sha256'],
            'files_index_checksum_sha256' => (string) $manifest['package']['integrity']['files_index_checksum_sha256'],
            'package_kind' => $packageKind,
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

    private function ensureArtifactDirectory(string $directory): void
    {
        if ($directory === '') {
            throw new \RuntimeException('Artifact directory path is missing from manifest.');
        }

        wp_mkdir_p($directory);

        if (! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create artifact directory %s.', $directory));
        }
    }

    private function requiredChecksum(string $path, string $label): string
    {
        if ($path === '' || ! is_file($path)) {
            throw new \RuntimeException(sprintf('Expected %s file is missing at %s.', $label, $path));
        }

        $checksum = hash_file('sha256', $path);

        if (! is_string($checksum) || $checksum === '') {
            throw new \RuntimeException(sprintf('Could not checksum %s file %s.', $label, $path));
        }

        return $checksum;
    }
}

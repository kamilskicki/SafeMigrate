<?php

declare(strict_types=1);

namespace SafeMigrate\Export;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SafeMigrate\Compatibility\BuilderDetector;
use SafeMigrate\Product\FeaturePolicy;
use SafeMigrate\Product\SettingsService;
use SafeMigrate\Support\ArtifactPaths;
use SplFileInfo;
use wpdb;

final class ExportPlanBuilder
{
    private const CHUNK_SIZE_BYTES = 8388608;

    public function __construct(
        private readonly wpdb $wpdb,
        private readonly BuilderDetector $builderDetector,
        private readonly ?SettingsService $settingsService = null,
        private readonly ?FeaturePolicy $featurePolicy = null
    ) {
    }

    public function build(int $jobId, array $options = []): array
    {
        $artifactDirectory = isset($options['artifact_directory']) && is_string($options['artifact_directory']) && $options['artifact_directory'] !== ''
            ? $options['artifact_directory']
            : $this->artifactDirectory($jobId);
        $this->ensureDirectory($artifactDirectory);

        $scope = $this->scope();
        $files = $this->collectFiles($scope);
        $database = $this->collectDatabaseMetadata();
        ['files' => $files, 'chunks' => $chunks] = $this->assignChunks($files);
        $builders = $this->builderDetector->detect();
        $builderWarnings = $this->builderWarnings($builders);

        $manifest = [
            'schema_version' => 1,
            'generated_at' => current_time('mysql', true),
            'generator' => [
                'plugin_version' => SAFE_MIGRATE_VERSION,
                'job_id' => $jobId,
            ],
            'site' => [
                'home_url' => home_url('/'),
                'site_url' => site_url('/'),
                'abspath' => ABSPATH,
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'multisite' => is_multisite(),
                'table_prefix' => $this->wpdb->prefix,
                'detected_builders' => $builders,
            ],
            'scope' => [
                'included_roots' => [
                    'wp-content',
                    'wp-config.php',
                    '.htaccess',
                    'web.config',
                    'index.php',
                ],
                'excluded_patterns' => $scope['excluded_patterns'],
                'include_prefixes' => $scope['include_prefixes'],
            ],
            'compatibility' => [
                'builders' => $builders,
                'warnings' => $builderWarnings,
            ],
            'filesystem' => [
                'total_files' => count($files),
                'total_bytes' => array_sum(array_column($files, 'size')),
                'chunk_size_bytes' => self::CHUNK_SIZE_BYTES,
                'chunks' => $chunks,
                'files_index' => 'files.json',
            ],
            'database' => $database,
            'artifacts' => [
                'directory' => $artifactDirectory,
                'manifest' => $artifactDirectory . '/manifest.json',
                'files_index' => $artifactDirectory . '/files.json',
            ],
        ];

        $this->writeJsonFile(
            $artifactDirectory . '/manifest.json',
            $manifest,
            'export manifest'
        );

        $this->writeJsonFile(
            $artifactDirectory . '/files.json',
            $files,
            'export files index'
        );

        return [
            'summary' => [
                'artifact_directory' => $artifactDirectory,
                'manifest_path' => $artifactDirectory . '/manifest.json',
                'files_index_path' => $artifactDirectory . '/files.json',
                'total_files' => $manifest['filesystem']['total_files'],
                'total_bytes' => $manifest['filesystem']['total_bytes'],
                'chunk_count' => count($chunks),
                'database_tables' => $database['total_tables'],
                'database_bytes' => $database['total_bytes'],
                'detected_builders' => $builders,
                'builder_warnings' => $builderWarnings,
            ],
            'manifest' => $manifest,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<int, array<string, mixed>>
     */
    private function collectFiles(array $scope): array
    {
        $files = [];
        $roots = [WP_CONTENT_DIR];
        $optionalRootFiles = ['wp-config.php', '.htaccess', 'web.config', 'index.php'];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $relativePath = $this->relativePath($file->getPathname());

                if ($this->shouldExclude($relativePath, $scope)) {
                    continue;
                }

                $files[] = $this->fileEntry($file, $relativePath);
            }
        }

        foreach ($optionalRootFiles as $rootFile) {
            $path = ABSPATH . $rootFile;

            if (! is_file($path)) {
                continue;
            }

            $files[] = $this->fileEntry(new SplFileInfo($path), $this->relativePath($path));
        }

        usort(
            $files,
            static fn (array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path'])
        );

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectDatabaseMetadata(): array
    {
        $rows = $this->wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        $tables = [];
        $totalBytes = 0;

        if (! is_array($rows)) {
            return [
                'total_tables' => 0,
                'total_bytes' => 0,
                'tables' => [],
            ];
        }

        foreach ($rows as $row) {
            $bytes = (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0);
            $totalBytes += $bytes;
            $tables[] = [
                'name' => $row['Name'] ?? '',
                'engine' => $row['Engine'] ?? '',
                'rows' => (int) ($row['Rows'] ?? 0),
                'collation' => $row['Collation'] ?? '',
                'bytes' => $bytes,
            ];
        }

        return [
            'total_tables' => count($tables),
            'total_bytes' => $totalBytes,
            'excluded_tables' => [
                $this->wpdb->prefix . 'safe_migrate_jobs',
                $this->wpdb->prefix . 'safe_migrate_checkpoints',
                $this->wpdb->prefix . 'safe_migrate_logs',
            ],
            'tables' => $tables,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{files: array<int, array<string, mixed>>, chunks: array<int, array<string, mixed>>}
     */
    private function assignChunks(array $files): array
    {
        $chunks = [];
        $assignedFiles = [];
        $currentChunk = [
            'index' => 1,
            'file_count' => 0,
            'bytes' => 0,
            'first_path' => null,
            'last_path' => null,
        ];

        foreach ($files as $file) {
            $size = (int) $file['size'];
            $path = (string) $file['path'];

            if ($currentChunk['file_count'] > 0 && ($currentChunk['bytes'] + $size) > self::CHUNK_SIZE_BYTES) {
                $chunks[] = $currentChunk;
                $currentChunk = [
                    'index' => count($chunks) + 1,
                    'file_count' => 0,
                    'bytes' => 0,
                    'first_path' => null,
                    'last_path' => null,
                ];
            }

            $file['chunk'] = $currentChunk['index'];
            $assignedFiles[] = $file;
            $currentChunk['file_count']++;
            $currentChunk['bytes'] += $size;
            $currentChunk['first_path'] ??= $path;
            $currentChunk['last_path'] = $path;
        }

        if ($currentChunk['file_count'] > 0) {
            $chunks[] = $currentChunk;
        }

        return [
            'files' => $assignedFiles,
            'chunks' => $chunks,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function shouldExclude(string $relativePath, array $scope): bool
    {
        $normalized = trim(str_replace('\\', '/', $relativePath), '/');
        $includePrefixes = array_values(array_filter(
            array_map(
                static fn (mixed $prefix): string => trim(str_replace('\\', '/', (string) $prefix), '/'),
                (array) ($scope['include_prefixes'] ?? [])
            ),
            static fn (string $prefix): bool => $prefix !== ''
        ));

        if ($includePrefixes !== []) {
            $included = false;

            foreach ($includePrefixes as $prefix) {
                if ($normalized === $prefix || str_starts_with($normalized, $prefix . '/')) {
                    $included = true;
                    break;
                }
            }

            if (! $included) {
                return true;
            }
        }

        foreach ((array) ($scope['excluded_patterns'] ?? []) as $prefix) {
            $normalizedPrefix = trim(str_replace('\\', '/', (string) $prefix), '/');

            if ($normalizedPrefix !== '' && ($normalized === $normalizedPrefix || str_starts_with($normalized, $normalizedPrefix . '/'))) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $absolutePath): string
    {
        return ltrim(str_replace('\\', '/', str_replace(ABSPATH, '', $absolutePath)), '/');
    }

    private function fileEntry(SplFileInfo $file, string $relativePath): array
    {
        return [
            'path' => $relativePath,
            'size' => $file->getSize(),
            'modified_at' => gmdate('c', (int) $file->getMTime()),
            'fingerprint' => sha1($relativePath . '|' . $file->getSize() . '|' . $file->getMTime()),
        ];
    }

    private function artifactDirectory(int $jobId): string
    {
        return ArtifactPaths::exportJobDirectory($jobId);
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory === '') {
            throw new \RuntimeException('Artifact directory path is empty.');
        }

        wp_mkdir_p($directory);

        if (! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create artifact directory %s.', $directory));
        }
    }

    private function writeJsonFile(string $path, array $payload, string $label): void
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);
        $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || $encoded === '') {
            throw new \RuntimeException(sprintf('Could not encode %s.', $label));
        }

        if (file_put_contents($path, $encoded) === false) {
            throw new \RuntimeException(sprintf('Could not write %s to %s.', $label, $path));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(): array
    {
        $scope = [
            'excluded_patterns' => [
                'wp-content/cache',
                'wp-content/upgrade',
                'wp-content/uploads/safe-migrate',
                '.git',
                'node_modules',
            ],
            'include_prefixes' => [],
        ];

        if ($this->settingsService === null || $this->featurePolicy === null) {
            return $scope;
        }

        if (! $this->featurePolicy->allows(FeaturePolicy::EXCLUDE_INCLUDE_RULES)) {
            return $scope;
        }

        $settings = $this->settingsService->get();
        $customExcludes = array_values(array_filter(
            array_map('strval', (array) ($settings['migration']['exclude_patterns'] ?? [])),
            static fn (string $pattern): bool => $pattern !== ''
        ));
        $customIncludes = array_values(array_filter(
            array_map('strval', (array) ($settings['migration']['include_prefixes'] ?? [])),
            static fn (string $pattern): bool => $pattern !== ''
        ));

        if ($customExcludes !== []) {
            $scope['excluded_patterns'] = $customExcludes;
        }

        $scope['include_prefixes'] = $customIncludes;

        return $scope;
    }

    /**
     * @param array<int, array<string, mixed>> $builders
     * @return array<int, string>
     */
    private function builderWarnings(array $builders): array
    {
        $warnings = [];

        foreach ($builders as $builder) {
            foreach ((array) ($builder['warnings'] ?? []) as $warning) {
                $warnings[] = (string) $warning;
            }
        }

        return array_values(array_unique($warnings));
    }
}

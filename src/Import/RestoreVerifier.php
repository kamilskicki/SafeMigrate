<?php

declare(strict_types=1);

namespace SafeMigrate\Import;

use SafeMigrate\Compatibility\BuilderRegistry;
use SafeMigrate\Import\PackageValidator;

final class RestoreVerifier
{
    public function __construct(
        private readonly PackageValidator $packageValidator,
        private readonly ?BuilderRegistry $builderRegistry = null
    )
    {
    }

    public function verify(array $package, array $restoreSummary): array
    {
        global $wpdb;

        $manifest = $package['manifest'];
        $validation = $this->packageValidator->validate(
            $package,
            [(string) ($manifest['package']['kind'] ?? 'safe-migrate-export')]
        );
        $issues = $validation['issues'];

        foreach ($this->expectedPaths($package) as $relativePath) {
            if (! file_exists(ABSPATH . ltrim($relativePath, '/'))) {
                $issues[] = sprintf('Missing restored path: %s', $relativePath);
            }
        }

        foreach (($manifest['database']['segments']['tables'] ?? []) as $table) {
            $tableName = (string) ($table['table'] ?? '');

            if ($tableName === '') {
                continue;
            }

            $exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $tableName)
            );

            if ($exists !== $tableName) {
                $issues[] = sprintf('Missing restored database table: %s', $tableName);
            }
        }

        $home = (string) get_option('home', '');
        $siteUrl = (string) get_option('siteurl', '');

        if (! $this->isValidUrl($home)) {
            $issues[] = 'Invalid home URL after restore.';
        }

        if (! $this->isValidUrl($siteUrl)) {
            $issues[] = 'Invalid siteurl after restore.';
        }

        $expectedChunkCount = count($manifest['filesystem']['artifacts']['chunks'] ?? []);
        $appliedChunkCount = (int) ($restoreSummary['filesystem']['applied_chunks'] ?? 0);

        if ($expectedChunkCount !== $appliedChunkCount) {
            $issues[] = 'Applied filesystem chunk count does not match manifest.';
        }

        $builderIssues = ($this->builderRegistry ?? new BuilderRegistry())->verify($manifest, $restoreSummary);
        array_push($issues, ...$builderIssues);
        $builderWarnings = ($this->builderRegistry ?? new BuilderRegistry())->warnings(
            ($this->builderRegistry ?? new BuilderRegistry())->detectedFromManifest($manifest)
        );

        return [
            'status' => $issues === [] ? 'passed' : 'failed',
            'issues' => $issues,
            'applied_chunk_count' => $appliedChunkCount,
            'expected_chunk_count' => $expectedChunkCount,
            'home' => $home,
            'siteurl' => $siteUrl,
            'builder_warnings' => $builderWarnings,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function expectedPaths(array $package): array
    {
        $filesIndexPath = (string) ($package['manifest']['artifacts']['files_index'] ?? '');

        if ($filesIndexPath === '' || ! is_file($filesIndexPath)) {
            return [];
        }

        $files = json_decode((string) file_get_contents($filesIndexPath), true);

        if (! is_array($files)) {
            return [];
        }

        return array_map(
            static fn (array $file): string => (string) ($file['path'] ?? ''),
            $files
        );
    }

    private function isValidUrl(string $value): bool
    {
        return $value !== '' && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}

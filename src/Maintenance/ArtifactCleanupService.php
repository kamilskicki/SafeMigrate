<?php

declare(strict_types=1);

namespace SafeMigrate\Maintenance;

use SafeMigrate\Product\FeaturePolicy;
use SafeMigrate\Product\SettingsService;
use SafeMigrate\Support\ArtifactPaths;

final class ArtifactCleanupService
{
    public function __construct(
        private readonly ?SettingsService $settingsService = null,
        private readonly ?FeaturePolicy $featurePolicy = null
    ) {
    }

    public function cleanup(int $retain = 3): array
    {
        $retention = $this->retention($retain);

        return [
            'retention' => $retention,
            'exports' => $this->cleanupDirectories(ArtifactPaths::exportDirectories(), $retention['exports']),
            'restores' => $this->cleanupDirectories(ArtifactPaths::restoreDirectories(), $retention['restores']),
        ];
    }

    /**
     * @param array<int, string> $entries
     * @return array<int, string>
     */
    private function cleanupDirectories(array $entries, int $retain): array
    {
        if ($entries === []) {
            return [];
        }

        usort(
            $entries,
            static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left)
        );

        $deleted = [];
        $removable = array_slice($entries, $retain);

        foreach ($removable as $entry) {
            $this->deleteDirectory($entry);
            $deleted[] = $entry;
        }

        return $deleted;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    /**
     * @return array{exports: int, restores: int}
     */
    private function retention(int $fallback): array
    {
        $retention = [
            'exports' => max(1, $fallback),
            'restores' => max(1, $fallback),
        ];

        if ($this->settingsService === null || $this->featurePolicy === null) {
            return $retention;
        }

        if (! $this->featurePolicy->allows(FeaturePolicy::ADVANCED_RETENTION)) {
            return $retention;
        }

        $settings = $this->settingsService->get();

        return [
            'exports' => max(1, (int) ($settings['cleanup']['retain_exports'] ?? $fallback)),
            'restores' => max(1, (int) ($settings['cleanup']['retain_restores'] ?? $fallback)),
        ];
    }
}

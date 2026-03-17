<?php

declare(strict_types=1);

namespace SafeMigrate\Compatibility;

final class ElementorAdapter extends AbstractBuilderAdapter
{
    public function slug(): string
    {
        return 'elementor';
    }

    public function name(): string
    {
        return 'Elementor';
    }

    public function family(): string
    {
        return 'builder';
    }

    public function matchPatterns(): array
    {
        return ['elementor/'];
    }

    public function warnings(array $builder): array
    {
        return [
            'Elementor detected. Safe Migrate will verify generated CSS and template artifacts after restore.',
        ];
    }

    public function normalizeRules(array $manifest, array $rules): array
    {
        $sourceSite = untrailingslashit((string) ($manifest['site']['site_url'] ?? ''));
        $targetSite = untrailingslashit(site_url('/'));

        if ($sourceSite !== '' && $sourceSite !== $targetSite) {
            $rules[$sourceSite] = $targetSite;
            $rules[$sourceSite . '/'] = $targetSite . '/';
        }

        return $rules;
    }

    public function verify(array $manifest, array $restoreSummary): array
    {
        $issues = [];
        $hasElementorAssets = $this->manifestHasPathPrefix($manifest, 'wp-content/uploads/elementor/');

        if ($hasElementorAssets && ! is_dir(WP_CONTENT_DIR . '/uploads/elementor')) {
            $issues[] = 'Elementor assets directory is missing after restore.';
        }

        return $issues;
    }

    private function manifestHasPathPrefix(array $manifest, string $prefix): bool
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
            if (str_starts_with((string) ($file['path'] ?? ''), $prefix)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace SafeMigrate\Compatibility;

final class GutenbergFamilyAdapter extends AbstractBuilderAdapter
{
    public function slug(): string
    {
        return 'gutenberg-family';
    }

    public function name(): string
    {
        return 'Gutenberg Family';
    }

    public function family(): string
    {
        return 'block-editor';
    }

    public function matchPatterns(): array
    {
        return ['gutenberg/', 'ultimate-addons-for-gutenberg', 'kadence-blocks'];
    }

    public function warnings(array $builder): array
    {
        return [
            'Block-editor builder data detected. Safe Migrate will apply generic JSON and serialized remap verification for block payloads.',
        ];
    }

    public function normalizeRules(array $manifest, array $rules): array
    {
        $sourceHome = untrailingslashit((string) ($manifest['site']['home_url'] ?? ''));
        $targetHome = untrailingslashit(home_url('/'));

        if ($sourceHome !== '' && $sourceHome !== $targetHome) {
            $rules[str_replace('/', '\/', $sourceHome)] = str_replace('/', '\/', $targetHome);
        }

        return $rules;
    }

    public function verify(array $manifest, array $restoreSummary): array
    {
        $issues = [];

        if ($this->manifestHasPath($manifest, 'wp-content/themes')) {
            $themeJson = ABSPATH . 'wp-content/themes/' . get_stylesheet() . '/theme.json';

            if (file_exists($themeJson) && json_decode((string) file_get_contents($themeJson), true) === null) {
                $issues[] = 'theme.json could not be decoded after restore.';
            }
        }

        return $issues;
    }

    private function manifestHasPath(array $manifest, string $prefix): bool
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

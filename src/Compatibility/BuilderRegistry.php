<?php

declare(strict_types=1);

namespace SafeMigrate\Compatibility;

final class BuilderRegistry
{
    /**
     * @var array<int, BuilderAdapter>
     */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            new ElementorAdapter(),
            new GutenbergFamilyAdapter(),
            new OxygenAdapter(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function detect(): array
    {
        $installedPlugins = $this->installedPluginFiles();
        $activePlugins = array_values((array) get_option('active_plugins', []));
        $networkActivePlugins = array_keys((array) get_site_option('active_sitewide_plugins', []));
        $builders = [];

        foreach ($this->catalog() as $entry) {
            $matches = array_values(
                array_filter(
                    $installedPlugins,
                    static fn (string $pluginFile): bool => str_contains($pluginFile, (string) $entry['match'])
                )
            );

            if ($matches === []) {
                continue;
            }

            $adapter = $this->adapterForSlug((string) $entry['slug']);
            $active = count(array_intersect($matches, array_merge($activePlugins, $networkActivePlugins))) > 0;
            $builder = [
                'slug' => (string) $entry['slug'],
                'name' => (string) $entry['name'],
                'family' => (string) $entry['family'],
                'status' => $active ? 'active' : 'installed',
                'support' => $adapter?->supportLevel() ?? 'fallback',
                'warnings' => $adapter?->warnings($entry) ?? [
                    sprintf('%s is detected but uses generic fallback compatibility in Safe Migrate 1.0.', (string) $entry['name']),
                ],
            ];

            $builders[] = $builder;
        }

        usort(
            $builders,
            static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name'])
        );

        return $builders;
    }

    /**
     * @param array<int, array<string, mixed>> $builders
     * @return array<int, string>
     */
    public function warnings(array $builders): array
    {
        $warnings = [];

        foreach ($builders as $builder) {
            foreach ((array) ($builder['warnings'] ?? []) as $warning) {
                $warnings[] = (string) $warning;
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    public function normalizeRules(array $manifest, array $rules): array
    {
        foreach ($this->detectedFromManifest($manifest) as $builder) {
            $adapter = $this->adapterForSlug((string) ($builder['slug'] ?? ''));

            if ($adapter === null) {
                continue;
            }

            $rules = $adapter->normalizeRules($manifest, $rules);
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $restoreSummary
     * @return array<int, string>
     */
    public function verify(array $manifest, array $restoreSummary): array
    {
        $issues = [];

        foreach ($this->detectedFromManifest($manifest) as $builder) {
            $adapter = $this->adapterForSlug((string) ($builder['slug'] ?? ''));

            if ($adapter === null) {
                continue;
            }

            array_push($issues, ...$adapter->verify($manifest, $restoreSummary));
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    public function detectedFromManifest(array $manifest): array
    {
        $builders = $manifest['site']['detected_builders'] ?? [];

        return is_array($builders) ? array_values(array_filter($builders, 'is_array')) : [];
    }

    /**
     * @return array<int, string>
     */
    private function installedPluginFiles(): array
    {
        if (! defined('WP_PLUGIN_DIR') || ! is_dir(WP_PLUGIN_DIR)) {
            return [];
        }

        $pluginFiles = [];
        $rootFiles = glob(WP_PLUGIN_DIR . '/*.php') ?: [];

        foreach ($rootFiles as $file) {
            if (is_file($file)) {
                $pluginFiles[] = wp_basename($file);
            }
        }

        $pluginDirectories = glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($pluginDirectories as $directory) {
            $basename = wp_basename($directory);
            $files = glob($directory . '/*.php') ?: [];

            foreach ($files as $file) {
                if (is_file($file)) {
                    $pluginFiles[] = $basename . '/' . wp_basename($file);
                }
            }
        }

        return array_values(array_unique($pluginFiles));
    }

    private function adapterForSlug(string $slug): ?BuilderAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->slug() === $slug) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function catalog(): array
    {
        return [
            ['slug' => 'beaver-builder', 'name' => 'Beaver Builder', 'family' => 'builder', 'match' => 'beaver-builder'],
            ['slug' => 'breakdance', 'name' => 'Breakdance', 'family' => 'builder', 'match' => 'breakdance/'],
            ['slug' => 'brizy', 'name' => 'Brizy', 'family' => 'builder', 'match' => 'brizy'],
            ['slug' => 'divi', 'name' => 'Divi Builder', 'family' => 'builder', 'match' => 'divi-builder/'],
            ['slug' => 'elementor', 'name' => 'Elementor', 'family' => 'builder', 'match' => 'elementor/'],
            ['slug' => 'gutenberg-family', 'name' => 'Gutenberg Plugin', 'family' => 'block-editor', 'match' => 'gutenberg/'],
            ['slug' => 'gutenberg-family', 'name' => 'Kadence Blocks', 'family' => 'block-builder', 'match' => 'kadence-blocks'],
            ['slug' => 'oxygen', 'name' => 'Oxygen', 'family' => 'builder', 'match' => 'oxygen/'],
            ['slug' => 'pagelayer', 'name' => 'Pagelayer', 'family' => 'builder', 'match' => 'pagelayer'],
            ['slug' => 'seedprod', 'name' => 'SeedProd', 'family' => 'builder', 'match' => 'coming-soon'],
            ['slug' => 'siteorigin-panels', 'name' => 'SiteOrigin Page Builder', 'family' => 'builder', 'match' => 'siteorigin-panels'],
            ['slug' => 'gutenberg-family', 'name' => 'Spectra', 'family' => 'block-builder', 'match' => 'ultimate-addons-for-gutenberg'],
            ['slug' => 'visual-composer', 'name' => 'Visual Composer', 'family' => 'builder', 'match' => 'visualcomposer'],
            ['slug' => 'wpbakery', 'name' => 'WPBakery Page Builder', 'family' => 'builder', 'match' => 'js_composer'],
        ];
    }
}

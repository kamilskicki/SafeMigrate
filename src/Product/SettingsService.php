<?php

declare(strict_types=1);

namespace SafeMigrate\Product;

final class SettingsService
{
    public const OPTION = 'safe_migrate_settings';

    public function __construct(private readonly ?FeaturePolicy $featurePolicy = null)
    {
    }

    public function get(): array
    {
        $value = get_option(self::OPTION, []);

        if (! is_array($value)) {
            $value = [];
        }

        return $this->normalize(array_replace_recursive($this->defaults(), $value));
    }

    public function update(array $settings): array
    {
        $merged = $this->normalize(array_replace_recursive($this->get(), $settings));
        update_option(self::OPTION, $merged, false);

        return $this->get();
    }

    private function normalize(array $settings): array
    {
        $settings = array_replace_recursive($this->defaults(), $settings);
        $settings['cleanup']['retain_exports'] = max(1, (int) ($settings['cleanup']['retain_exports'] ?? 3));
        $settings['cleanup']['retain_restores'] = max(1, (int) ($settings['cleanup']['retain_restores'] ?? 3));

        if (! $this->allows(FeaturePolicy::ADVANCED_RETENTION)) {
            $settings['cleanup']['retain_exports'] = 3;
            $settings['cleanup']['retain_restores'] = 3;
        }

        $settings['migration']['exclude_patterns'] = $this->stringList($settings['migration']['exclude_patterns'] ?? []);
        $settings['migration']['include_prefixes'] = $this->stringList($settings['migration']['include_prefixes'] ?? []);

        if (! $this->allows(FeaturePolicy::EXCLUDE_INCLUDE_RULES)) {
            $settings['migration']['exclude_patterns'] = [];
            $settings['migration']['include_prefixes'] = [];
        }

        $settings['support_bundle']['redact_sensitive'] = (bool) ($settings['support_bundle']['redact_sensitive'] ?? false);

        if (! $this->allows(FeaturePolicy::SUPPORT_BUNDLE_REDACTION)) {
            $settings['support_bundle']['redact_sensitive'] = false;
        }

        $settings['profiles'] = $this->profiles($settings['profiles'] ?? []);

        if (! $this->allows(FeaturePolicy::SAVED_PROFILES)) {
            $settings['profiles'] = [];
        }

        return $settings;
    }

    private function defaults(): array
    {
        return [
            'cleanup' => [
                'retain_exports' => 3,
                'retain_restores' => 3,
            ],
            'support_bundle' => [
                'redact_sensitive' => false,
            ],
            'migration' => [
                'exclude_patterns' => [],
                'include_prefixes' => [],
            ],
            'profiles' => [],
        ];
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        $list = array_values(array_filter(
            array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($values) ? $values : []
            ),
            static fn (string $value): bool => $value !== ''
        ));

        return array_values(array_unique($list));
    }

    /**
     * @param mixed $profiles
     * @return array<int, array{name: string, artifact_directory: string}>
     */
    private function profiles(mixed $profiles): array
    {
        if (! is_array($profiles)) {
            return [];
        }

        $normalized = [];

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $name = trim((string) ($profile['name'] ?? ''));
            $artifactDirectory = trim((string) ($profile['artifact_directory'] ?? ''));

            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'artifact_directory' => $artifactDirectory,
            ];
        }

        return $normalized;
    }

    private function allows(string $feature): bool
    {
        return $this->featurePolicy === null || $this->featurePolicy->allows($feature);
    }
}

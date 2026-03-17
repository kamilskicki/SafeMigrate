<?php

declare(strict_types=1);

namespace SafeMigrate\Product {
    if (! class_exists(WordPressOptionStore::class, false)) {
        final class WordPressOptionStore
        {
            /**
             * @var array<string, mixed>
             */
            public static array $options = [];

            public static function reset(): void
            {
                self::$options = [];
            }
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\get_option')) {
        function get_option(string $option, mixed $default = false): mixed
        {
            return WordPressOptionStore::$options[$option] ?? $default;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\update_option')) {
        function update_option(string $option, mixed $value, bool $autoload = false): bool
        {
            WordPressOptionStore::$options[$option] = $value;

            return true;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\home_url')) {
        function home_url(string $path = '/'): string
        {
            return 'https://local.test' . $path;
        }
    }
}

namespace SafeMigrate\Tests\Integration\Product {
    use PHPUnit\Framework\TestCase;
    use SafeMigrate\Product\FeaturePolicy;
    use SafeMigrate\Product\LicenseStateService;
    use SafeMigrate\Product\SettingsService;
    use SafeMigrate\Product\WordPressOptionStore;

    final class SettingsServiceTest extends TestCase
    {
        protected function setUp(): void
        {
            WordPressOptionStore::reset();
        }

        public function testCoreModeRejectsProOnlySettings(): void
        {
            $license = new LicenseStateService();
            $policy = new FeaturePolicy($license);
            $settings = new SettingsService($policy);

            $updated = $settings->update([
                'cleanup' => [
                    'retain_exports' => 10,
                    'retain_restores' => 8,
                ],
                'migration' => [
                    'exclude_patterns' => ['cache', 'node_modules'],
                    'include_prefixes' => ['wp-content/uploads'],
                ],
                'support_bundle' => [
                    'redact_sensitive' => true,
                ],
                'profiles' => [
                    ['name' => 'Saved', 'artifact_directory' => '/tmp/export-1'],
                ],
            ]);

            self::assertSame(3, $updated['cleanup']['retain_exports']);
            self::assertSame(3, $updated['cleanup']['retain_restores']);
            self::assertSame([], $updated['migration']['exclude_patterns']);
            self::assertSame([], $updated['migration']['include_prefixes']);
            self::assertFalse($updated['support_bundle']['redact_sensitive']);
            self::assertSame([], $updated['profiles']);
        }

        public function testProModeSanitizesAndPersistsAdvancedSettings(): void
        {
            $license = new LicenseStateService();
            $license->update([
                'status' => 'active',
                'tier' => 'pro',
            ]);

            $policy = new FeaturePolicy($license);
            $settings = new SettingsService($policy);

            $updated = $settings->update([
                'cleanup' => [
                    'retain_exports' => 0,
                    'retain_restores' => 12,
                ],
                'migration' => [
                    'exclude_patterns' => [' cache ', '', 'cache'],
                    'include_prefixes' => [' wp-content/uploads ', 'wp-content/uploads'],
                ],
                'support_bundle' => [
                    'redact_sensitive' => true,
                ],
                'profiles' => [
                    ['name' => ' Default ', 'artifact_directory' => ' /tmp/export-1 '],
                    ['name' => '', 'artifact_directory' => '/tmp/export-2'],
                ],
            ]);

            self::assertSame(1, $updated['cleanup']['retain_exports']);
            self::assertSame(12, $updated['cleanup']['retain_restores']);
            self::assertSame(['cache'], $updated['migration']['exclude_patterns']);
            self::assertSame(['wp-content/uploads'], $updated['migration']['include_prefixes']);
            self::assertTrue($updated['support_bundle']['redact_sensitive']);
            self::assertSame(
                [['name' => 'Default', 'artifact_directory' => '/tmp/export-1']],
                $updated['profiles']
            );
        }
    }
}

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

namespace SafeMigrate\Tests\Unit\Product {
    use PHPUnit\Framework\TestCase;
    use SafeMigrate\Product\LicenseStateService;
    use SafeMigrate\Product\WordPressOptionStore;

    final class LicenseStateServiceTest extends TestCase
    {
        protected function setUp(): void
        {
            WordPressOptionStore::reset();
        }

        public function testSanitizesUnknownTierAndStatus(): void
        {
            $service = new LicenseStateService();
            $state = $service->update([
                'status' => 'enabled',
                'tier' => 'enterprise',
                'license_key' => ' key ',
                'site' => ' https://example.test ',
            ]);

            self::assertSame('inactive', $state['status']);
            self::assertSame('core', $state['tier']);
            self::assertSame('key', $state['license_key']);
            self::assertSame('https://example.test', $state['site']);
        }

        public function testReportsProActiveOnlyForActiveProOrAgencyTiers(): void
        {
            $service = new LicenseStateService();
            $service->update([
                'status' => 'active',
                'tier' => 'agency',
            ]);

            self::assertTrue($service->isProActive());

            $service->update([
                'status' => 'inactive',
                'tier' => 'agency',
            ]);

            self::assertFalse($service->isProActive());
        }
    }
}

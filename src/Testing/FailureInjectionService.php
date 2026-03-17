<?php

declare(strict_types=1);

namespace SafeMigrate\Testing;

final class FailureInjectionService
{
    public const OPTION = 'safe_migrate_failure_injection';

    public function current(): array
    {
        $value = get_option(self::OPTION, []);

        if (! is_array($value)) {
            $value = [];
        }

        return array_replace(
            [
                'stage' => '',
                'message' => '',
                'once' => true,
                'enabled' => false,
            ],
            $value
        );
    }

    public function configure(array $config): array
    {
        $current = $this->current();
        $next = array_replace($current, $config);
        $next['enabled'] = $this->isAvailable() && (bool) ($next['enabled'] ?? true) && (string) ($next['stage'] ?? '') !== '';
        update_option(self::OPTION, $next, false);

        return $this->current();
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }

    public function isAvailable(): bool
    {
        if (defined('SAFE_MIGRATE_ENABLE_TESTING') && SAFE_MIGRATE_ENABLE_TESTING) {
            return true;
        }

        $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

        if ($environment !== 'production') {
            return true;
        }

        $home = (string) home_url('/');

        return str_contains($home, 'localhost')
            || str_contains($home, '127.0.0.1')
            || str_contains($home, '.test')
            || str_contains($home, '.local');
    }

    public function throwIfConfigured(string $stage): void
    {
        $config = $this->current();

        if (! $this->isAvailable() || ! (bool) ($config['enabled'] ?? false) || (string) ($config['stage'] ?? '') !== $stage) {
            return;
        }

        if ((bool) ($config['once'] ?? true)) {
            $this->clear();
        }

        $message = (string) ($config['message'] ?? '');

        throw new \RuntimeException($message !== '' ? $message : sprintf('Injected failure at stage: %s', $stage));
    }
}

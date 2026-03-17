<?php

declare(strict_types=1);

namespace SafeMigrate\Product;

final class LicenseStateService
{
    public const OPTION = 'safe_migrate_license_state';

    public function get(): array
    {
        $value = get_option(self::OPTION, []);

        if (! is_array($value)) {
            $value = [];
        }

        return $this->normalize(array_replace($this->defaults(), $value));
    }

    public function update(array $state): array
    {
        $merged = $this->normalize(array_replace($this->get(), $state));
        update_option(self::OPTION, $merged, false);

        return $merged;
    }

    public function isProActive(): bool
    {
        $state = $this->get();

        return ($state['status'] ?? 'inactive') === 'active'
            && in_array((string) ($state['tier'] ?? 'core'), ['pro', 'agency'], true);
    }

    private function defaults(): array
    {
        return [
            'status' => 'inactive',
            'tier' => 'core',
            'license_key' => '',
            'expires_at' => '',
            'site' => home_url('/'),
        ];
    }

    private function normalize(array $state): array
    {
        $tier = strtolower(trim((string) ($state['tier'] ?? 'core')));
        $status = strtolower(trim((string) ($state['status'] ?? 'inactive')));

        if (! in_array($tier, ['core', 'pro', 'agency'], true)) {
            $tier = 'core';
        }

        if (! in_array($status, ['inactive', 'active'], true)) {
            $status = 'inactive';
        }

        return [
            'status' => $status,
            'tier' => $tier,
            'license_key' => trim((string) ($state['license_key'] ?? '')),
            'expires_at' => trim((string) ($state['expires_at'] ?? '')),
            'site' => trim((string) ($state['site'] ?? home_url('/'))),
        ];
    }
}

<?php

declare(strict_types=1);

namespace SafeMigrate\Jobs;

use SafeMigrate\Contracts\RegistersHooks;

final class Capabilities implements RegistersHooks
{
    public const MANAGE = 'manage_safe_migrate';
    public const DESTRUCTIVE = 'manage_safe_migrate_destructive';

    public function register(): void
    {
        add_action(
            'admin_init',
            function (): void {
                $this->grant();
            }
        );
    }

    public function grant(): void
    {
        $role = get_role('administrator');

        if ($role === null) {
            return;
        }

        $role->add_cap(self::MANAGE);
        $role->add_cap(self::DESTRUCTIVE);
    }

    public function revoke(): void
    {
        $role = get_role('administrator');

        if ($role === null) {
            return;
        }

        $role->remove_cap(self::MANAGE);
        $role->remove_cap(self::DESTRUCTIVE);
    }
}

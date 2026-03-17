<?php

declare(strict_types=1);

namespace SafeMigrate\Import;

final class RestoreStages
{
    public const PACKAGE_VALIDATED = 'package_validated';
    public const SNAPSHOT_CREATED = 'snapshot_created';
    public const WORKSPACE_PREPARED = 'workspace_prepared';
    public const FILESYSTEM_APPLIED = 'filesystem_applied';
    public const DATABASE_APPLIED = 'database_applied';
    public const REMAP_APPLIED = 'remap_applied';
    public const VERIFICATION_PASSED = 'verification_passed';
    public const SWITCH_OVER_COMPLETED = 'switch_over_completed';
    public const ROLLBACK_COMPLETED = 'rollback_completed';

    /**
     * @return array<int, string>
     */
    public static function ordered(): array
    {
        return [
            self::PACKAGE_VALIDATED,
            self::SNAPSHOT_CREATED,
            self::WORKSPACE_PREPARED,
            self::FILESYSTEM_APPLIED,
            self::DATABASE_APPLIED,
            self::REMAP_APPLIED,
            self::VERIFICATION_PASSED,
            self::SWITCH_OVER_COMPLETED,
        ];
    }
}

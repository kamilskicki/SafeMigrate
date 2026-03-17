<?php

declare(strict_types=1);

namespace SafeMigrate\Api;

final class ApiErrorClassifier
{
    /**
     * @return array{status: int, safe_migrate_code: string}
     */
    public function classify(string $message): array
    {
        if (str_contains($message, 'confirm_destructive=true')) {
            return ['status' => 422, 'safe_migrate_code' => 'destructive_confirmation_required'];
        }

        if (str_contains($message, 'Another destructive restore operation')) {
            return ['status' => 409, 'safe_migrate_code' => 'lock_conflict'];
        }

        if (str_contains($message, 'rollback_unavailable')) {
            return ['status' => 422, 'safe_migrate_code' => 'rollback_unavailable'];
        }

        if (
            str_contains($message, 'transfer token')
            || str_contains($message, 'transfer session')
            || str_contains($message, 'Remote export')
            || str_contains($message, 'Remote request failed')
            || str_contains($message, 'Remote package validation failed')
        ) {
            return ['status' => 422, 'safe_migrate_code' => 'remote_transfer_failure'];
        }

        if (
            str_contains($message, 'validation failed')
            || str_contains($message, 'not valid')
            || str_contains($message, 'Checksum mismatch')
        ) {
            return ['status' => 422, 'safe_migrate_code' => 'package_validation_failure'];
        }

        if (str_contains($message, 'verification failed')) {
            return ['status' => 422, 'safe_migrate_code' => 'verification_failure'];
        }

        if (str_contains($message, 'Job not found') || str_contains($message, 'Source restore job not found')) {
            return ['status' => 404, 'safe_migrate_code' => 'job_not_found'];
        }

        return ['status' => 500, 'safe_migrate_code' => 'unknown_error'];
    }
}

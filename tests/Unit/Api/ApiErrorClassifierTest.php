<?php

declare(strict_types=1);

namespace SafeMigrate\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use SafeMigrate\Api\ApiErrorClassifier;

final class ApiErrorClassifierTest extends TestCase
{
    public function testClassifiesDestructiveConfirmation(): void
    {
        $classifier = new ApiErrorClassifier();

        self::assertSame(
            ['status' => 422, 'safe_migrate_code' => 'destructive_confirmation_required'],
            $classifier->classify('Destructive restore requires confirm_destructive=true.')
        );
    }

    public function testClassifiesRemoteTransferFailure(): void
    {
        $classifier = new ApiErrorClassifier();

        self::assertSame(
            ['status' => 422, 'safe_migrate_code' => 'remote_transfer_failure'],
            $classifier->classify('Remote package validation failed: checksum mismatch')
        );
    }

    public function testFallsBackToUnknownError(): void
    {
        $classifier = new ApiErrorClassifier();

        self::assertSame(
            ['status' => 500, 'safe_migrate_code' => 'unknown_error'],
            $classifier->classify('Unexpected failure')
        );
    }
}

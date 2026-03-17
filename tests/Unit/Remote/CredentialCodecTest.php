<?php

declare(strict_types=1);

namespace SafeMigrate\Tests\Unit\Remote;

use PHPUnit\Framework\TestCase;
use SafeMigrate\Remote\CredentialCodec;

final class CredentialCodecTest extends TestCase
{
    public function testRoundTripsCredential(): void
    {
        $codec = new CredentialCodec('test-signing-key');
        $encoded = $codec->encode('abc123', 'secret456');

        self::assertSame(
            ['id' => 'abc123', 'secret' => 'secret456'],
            $codec->decode($encoded)
        );
    }

    public function testRejectsTamperedCredential(): void
    {
        $codec = new CredentialCodec('test-signing-key');
        $encoded = $codec->encode('abc123', 'secret456') . 'tampered';

        self::assertNull($codec->decode($encoded));
    }
}

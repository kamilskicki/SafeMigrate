<?php

declare(strict_types=1);

namespace SafeMigrate\Remote;

final class CredentialCodec
{
    public function __construct(private readonly string $signingKey)
    {
    }

    public function encode(string $id, string $secret): string
    {
        return sprintf('%s.%s.%s', $id, $secret, $this->signature($id, $secret));
    }

    /**
     * @return array{id: string, secret: string}|null
     */
    public function decode(string $credential): ?array
    {
        $parts = explode('.', $credential, 3);

        if (count($parts) !== 3) {
            return null;
        }

        [$id, $secret, $signature] = $parts;

        if ($id === '' || $secret === '' || $signature === '') {
            return null;
        }

        if (! hash_equals($this->signature($id, $secret), $signature)) {
            return null;
        }

        return [
            'id' => $id,
            'secret' => $secret,
        ];
    }

    private function signature(string $id, string $secret): string
    {
        return hash_hmac('sha256', $id . '|' . $secret, $this->signingKey);
    }
}

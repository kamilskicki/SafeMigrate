<?php

declare(strict_types=1);

namespace SafeMigrate\Remote;

final class TransferSessionService
{
    public const TOKEN_OPTION_PREFIX = 'safe_migrate_transfer_token_';
    public const SESSION_OPTION_PREFIX = 'safe_migrate_transfer_session_';
    private const TOKEN_TTL = 1800;
    private const SESSION_TTL = 7200;

    public function __construct(private readonly CredentialCodec $codec)
    {
    }

    public function issueTransferToken(int $userId): array
    {
        $this->purgeExpiredCredentials();

        $id = wp_generate_password(20, false, false);
        $secret = wp_generate_password(40, false, false);
        $issuedAt = time();
        $expiresAt = $issuedAt + self::TOKEN_TTL;

        $record = [
            'id' => $id,
            'secret_hash' => hash('sha256', $secret),
            'created_by' => $userId,
            'created_at' => gmdate('c', $issuedAt),
            'expires_at' => gmdate('c', $expiresAt),
            'consumed_at' => '',
            'status' => 'active',
        ];

        update_option($this->tokenOptionName($id), $record, false);

        return [
            'token' => $this->codec->encode($id, $secret),
            'token_id' => $id,
            'expires_at' => $record['expires_at'],
            'status' => 'active',
        ];
    }

    public function createSessionFromTransferToken(string $token, array $target = []): array
    {
        $this->purgeExpiredCredentials();

        $parsed = $this->codec->decode($token);

        if ($parsed === null) {
            throw new \RuntimeException('Invalid transfer token.');
        }

        $tokenRecord = get_option($this->tokenOptionName($parsed['id']), []);

        if (! is_array($tokenRecord) || $tokenRecord === []) {
            throw new \RuntimeException('Transfer token not found.');
        }

        if ((string) ($tokenRecord['status'] ?? '') !== 'active' || (string) ($tokenRecord['consumed_at'] ?? '') !== '') {
            throw new \RuntimeException('Transfer token has already been used.');
        }

        if (! hash_equals((string) ($tokenRecord['secret_hash'] ?? ''), hash('sha256', $parsed['secret']))) {
            throw new \RuntimeException('Transfer token is invalid.');
        }

        if ($this->isExpired((string) ($tokenRecord['expires_at'] ?? ''))) {
            $tokenRecord['status'] = 'expired';
            update_option($this->tokenOptionName($parsed['id']), $tokenRecord, false);
            throw new \RuntimeException('Transfer token expired.');
        }

        $sessionId = wp_generate_password(24, false, false);
        $sessionSecret = wp_generate_password(48, false, false);
        $issuedAt = time();
        $expiresAt = $issuedAt + self::SESSION_TTL;
        $sessionRecord = [
            'id' => $sessionId,
            'secret_hash' => hash('sha256', $sessionSecret),
            'token_id' => $parsed['id'],
            'created_by' => (int) ($tokenRecord['created_by'] ?? 0),
            'created_at' => gmdate('c', $issuedAt),
            'expires_at' => gmdate('c', $expiresAt),
            'last_used_at' => '',
            'status' => 'active',
            'target' => $this->sanitizeTarget($target),
            'export_job_id' => 0,
            'artifact_directory' => '',
        ];

        update_option($this->sessionOptionName($sessionId), $sessionRecord, false);

        $tokenRecord['status'] = 'consumed';
        $tokenRecord['consumed_at'] = gmdate('c');
        update_option($this->tokenOptionName($parsed['id']), $tokenRecord, false);

        return [
            'credential' => $this->codec->encode($sessionId, $sessionSecret),
            'session_id' => $sessionId,
            'expires_at' => $sessionRecord['expires_at'],
            'status' => 'active',
        ];
    }

    public function authenticateSession(string $credential): array
    {
        $this->purgeExpiredCredentials();

        if ($credential === '') {
            throw new \RuntimeException('Invalid transfer session.');
        }

        $parsed = $this->codec->decode($credential);

        if ($parsed === null) {
            throw new \RuntimeException('Invalid transfer session.');
        }

        $session = get_option($this->sessionOptionName($parsed['id']), []);

        if (! is_array($session) || $session === []) {
            throw new \RuntimeException('Transfer session not found.');
        }

        if ((string) ($session['status'] ?? '') !== 'active') {
            throw new \RuntimeException('Transfer session is not active.');
        }

        if ($this->isExpired((string) ($session['expires_at'] ?? ''))) {
            $session['status'] = 'expired';
            update_option($this->sessionOptionName($parsed['id']), $session, false);
            throw new \RuntimeException('Transfer session expired.');
        }

        if (! hash_equals((string) ($session['secret_hash'] ?? ''), hash('sha256', $parsed['secret']))) {
            throw new \RuntimeException('Transfer session is invalid.');
        }

        $session['last_used_at'] = gmdate('c');
        update_option($this->sessionOptionName($parsed['id']), $session, false);

        return $session;
    }

    public function attachExportJob(string $sessionId, int $jobId, string $artifactDirectory = ''): void
    {
        $session = get_option($this->sessionOptionName($sessionId), []);

        if (! is_array($session) || $session === []) {
            return;
        }

        $session['export_job_id'] = $jobId;
        $session['artifact_directory'] = $artifactDirectory;
        $session['last_used_at'] = gmdate('c');
        update_option($this->sessionOptionName($sessionId), $session, false);
    }

    public function exportJobId(array $session): int
    {
        return (int) ($session['export_job_id'] ?? 0);
    }

    public function createdBy(array $session): int
    {
        return max(1, (int) ($session['created_by'] ?? 1));
    }

    public function deleteAll(): void
    {
        global $wpdb;

        if (! isset($wpdb->options)) {
            return;
        }

        $tokenLike = $wpdb->esc_like(self::TOKEN_OPTION_PREFIX) . '%';
        $sessionLike = $wpdb->esc_like(self::SESSION_OPTION_PREFIX) . '%';

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s',
                $tokenLike,
                $sessionLike
            )
        );
    }

    private function tokenOptionName(string $id): string
    {
        return self::TOKEN_OPTION_PREFIX . $id;
    }

    private function sessionOptionName(string $id): string
    {
        return self::SESSION_OPTION_PREFIX . $id;
    }

    private function isExpired(string $iso8601): bool
    {
        if ($iso8601 === '') {
            return true;
        }

        $timestamp = strtotime($iso8601);

        return $timestamp === false || $timestamp < time();
    }

    private function purgeExpiredCredentials(): void
    {
        global $wpdb;

        if (! isset($wpdb->options)) {
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT option_name, option_value FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s',
                $wpdb->esc_like(self::TOKEN_OPTION_PREFIX) . '%',
                $wpdb->esc_like(self::SESSION_OPTION_PREFIX) . '%'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $value = maybe_unserialize($row['option_value'] ?? '');

            if (! is_array($value) || ! $this->isExpired((string) ($value['expires_at'] ?? ''))) {
                continue;
            }

            delete_option((string) ($row['option_name'] ?? ''));
        }
    }

    /**
     * @return array{home: string, siteurl: string, abspath: string}
     */
    private function sanitizeTarget(array $target): array
    {
        return [
            'home' => trim((string) ($target['home'] ?? '')),
            'siteurl' => trim((string) ($target['siteurl'] ?? '')),
            'abspath' => trim((string) ($target['abspath'] ?? '')),
        ];
    }
}

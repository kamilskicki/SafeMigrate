<?php

declare(strict_types=1);

namespace SafeMigrate\Remote {
    if (! defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    final class TransferSessionOptionStore
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

    final class TransferSessionWpdbStub
    {
        public string $options = 'wp_options';

        public function esc_like(string $text): string
        {
            return rtrim($text, '%');
        }

        public function prepare(string $query, mixed ...$args): string
        {
            return $query . '|' . implode('|', array_map(static fn (mixed $arg): string => (string) $arg, $args));
        }

        /**
         * @return array<int, array{option_name: string, option_value: mixed}>
         */
        public function get_results(mixed $query, mixed $output = null): array
        {
            $rows = [];

            foreach (TransferSessionOptionStore::$options as $name => $value) {
                if (! str_starts_with($name, TransferSessionService::TOKEN_OPTION_PREFIX)
                    && ! str_starts_with($name, TransferSessionService::SESSION_OPTION_PREFIX)) {
                    continue;
                }

                $rows[] = [
                    'option_name' => $name,
                    'option_value' => $value,
                ];
            }

            return $rows;
        }

        public function query(mixed $query): int
        {
            foreach (array_keys(TransferSessionOptionStore::$options) as $name) {
                if (str_starts_with($name, TransferSessionService::TOKEN_OPTION_PREFIX)
                    || str_starts_with($name, TransferSessionService::SESSION_OPTION_PREFIX)) {
                    unset(TransferSessionOptionStore::$options[$name]);
                }
            }

            return 1;
        }
    }

    function get_option(string $option, mixed $default = false): mixed
    {
        return TransferSessionOptionStore::$options[$option] ?? $default;
    }

    function update_option(string $option, mixed $value, bool $autoload = false): bool
    {
        TransferSessionOptionStore::$options[$option] = $value;

        return true;
    }

    function delete_option(string $option): bool
    {
        unset(TransferSessionOptionStore::$options[$option]);

        return true;
    }

    function maybe_unserialize(mixed $value): mixed
    {
        return $value;
    }

    function wp_generate_password(int $length = 12, bool $specialChars = true, bool $extraSpecialChars = false): string
    {
        static $counter = 0;
        $counter++;

        return substr(str_repeat('abcdefghijklmnopqrstuvwxyz', 3), 0, max(1, $length - 2)) . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
    }
}

namespace SafeMigrate\Tests\Unit\Remote {
    use PHPUnit\Framework\TestCase;
    use SafeMigrate\Remote\CredentialCodec;
    use SafeMigrate\Remote\TransferSessionOptionStore;
    use SafeMigrate\Remote\TransferSessionService;
    use SafeMigrate\Remote\TransferSessionWpdbStub;

    final class TransferSessionServiceTest extends TestCase
    {
        protected function setUp(): void
        {
            TransferSessionOptionStore::reset();
            $GLOBALS['wpdb'] = new TransferSessionWpdbStub();
        }

        public function testIssueTransferTokenPurgesExpiredCredentials(): void
        {
            TransferSessionOptionStore::$options[TransferSessionService::TOKEN_OPTION_PREFIX . 'expired'] = [
                'expires_at' => gmdate('c', time() - 3600),
            ];

            $service = new TransferSessionService(new CredentialCodec('test-signing-key'));
            $token = $service->issueTransferToken(7);

            self::assertArrayHasKey('token', $token);
            self::assertArrayNotHasKey(TransferSessionService::TOKEN_OPTION_PREFIX . 'expired', TransferSessionOptionStore::$options);
            self::assertArrayHasKey(TransferSessionService::TOKEN_OPTION_PREFIX . $token['token_id'], TransferSessionOptionStore::$options);
        }

        public function testCreatesAndAuthenticatesSessionFromToken(): void
        {
            $service = new TransferSessionService(new CredentialCodec('test-signing-key'));
            $token = $service->issueTransferToken(11);
            $session = $service->createSessionFromTransferToken($token['token'], [
                'home' => ' https://source.test ',
                'siteurl' => ' https://source.test/wp ',
                'abspath' => ' /var/www/html ',
            ]);
            $authenticated = $service->authenticateSession($session['credential']);

            self::assertSame(11, $authenticated['created_by']);
            self::assertSame('https://source.test', $authenticated['target']['home']);
            self::assertSame('https://source.test/wp', $authenticated['target']['siteurl']);
            self::assertSame('/var/www/html', $authenticated['target']['abspath']);
            self::assertSame('consumed', TransferSessionOptionStore::$options[TransferSessionService::TOKEN_OPTION_PREFIX . $token['token_id']]['status']);
        }

        public function testRejectsBlankCredential(): void
        {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid transfer session.');

            $service = new TransferSessionService(new CredentialCodec('test-signing-key'));
            $service->authenticateSession('');
        }
    }
}

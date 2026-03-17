<?php

declare(strict_types=1);

namespace SafeMigrate\Import {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }

    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}

namespace SafeMigrate\Tests\Unit\Import {
    use PHPUnit\Framework\TestCase;
    use SafeMigrate\Import\PackageValidator;

    final class PackageValidatorTest extends TestCase
    {
        private string $artifactDirectory;

        protected function setUp(): void
        {
            $this->artifactDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'safe-migrate-package-' . bin2hex(random_bytes(4));
            mkdir($this->artifactDirectory . DIRECTORY_SEPARATOR . 'chunks', 0777, true);
            mkdir($this->artifactDirectory . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'wp_posts', 0777, true);
        }

        protected function tearDown(): void
        {
            if (! is_dir($this->artifactDirectory)) {
                return;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->artifactDirectory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }

            rmdir($this->artifactDirectory);
        }

        public function testValidatesCompletePackage(): void
        {
            $chunkPath = $this->artifactDirectory . DIRECTORY_SEPARATOR . 'chunks' . DIRECTORY_SEPARATOR . 'chunk-001.zip';
            $schemaPath = $this->artifactDirectory . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'wp_posts' . DIRECTORY_SEPARATOR . 'schema.sql';
            $partPath = $this->artifactDirectory . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'wp_posts' . DIRECTORY_SEPARATOR . 'part-001.sql';
            $filesIndexPath = $this->artifactDirectory . DIRECTORY_SEPARATOR . 'files.json';

            file_put_contents($chunkPath, 'chunk-data');
            file_put_contents($schemaPath, 'CREATE TABLE wp_posts (...);');
            file_put_contents($partPath, 'INSERT INTO wp_posts VALUES (1);');
            file_put_contents($filesIndexPath, '{"files":[]}');

            $rawManifest = [
                'package' => [
                    'kind' => 'safe-migrate-export',
                    'integrity' => [
                        'manifest_checksum_sha256' => '',
                        'files_index_checksum_sha256' => hash_file('sha256', $filesIndexPath),
                    ],
                ],
                'filesystem' => [
                    'artifacts' => [
                        'chunks' => [
                            [
                                'path' => $chunkPath,
                                'checksum_sha256' => hash_file('sha256', $chunkPath),
                            ],
                        ],
                    ],
                ],
                'database' => [
                    'segments' => [
                        'tables' => [
                            [
                                'table' => 'wp_posts',
                                'schema_path' => $schemaPath,
                                'schema_checksum_sha256' => hash_file('sha256', $schemaPath),
                                'parts' => [
                                    [
                                        'path' => $partPath,
                                        'checksum_sha256' => hash_file('sha256', $partPath),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
            $rawManifest['package']['integrity']['manifest_checksum_sha256'] = $this->manifestChecksum($rawManifest);

            $validator = new PackageValidator();
            $result = $validator->validate([
                'artifact_directory' => $this->artifactDirectory,
                'manifest_path' => $this->artifactDirectory . DIRECTORY_SEPARATOR . 'manifest.json',
                'raw_manifest' => $rawManifest,
                'manifest' => $rawManifest,
            ]);

            self::assertSame('valid', $result['status']);
            self::assertSame([], $result['issues']);
        }

        public function testReportsChecksumMismatch(): void
        {
            $chunkPath = $this->artifactDirectory . DIRECTORY_SEPARATOR . 'chunks' . DIRECTORY_SEPARATOR . 'chunk-001.zip';
            file_put_contents($chunkPath, 'chunk-data');

            $validator = new PackageValidator();
            $result = $validator->validate([
                'artifact_directory' => $this->artifactDirectory,
                'manifest_path' => $this->artifactDirectory . DIRECTORY_SEPARATOR . 'manifest.json',
                'raw_manifest' => [
                    'package' => [
                        'kind' => 'safe-migrate-export',
                        'integrity' => [
                            'manifest_checksum_sha256' => '',
                            'files_index_checksum_sha256' => '',
                        ],
                    ],
                    'filesystem' => [
                        'artifacts' => [
                            'chunks' => [
                                [
                                    'path' => $chunkPath,
                                    'checksum_sha256' => str_repeat('0', 64),
                                ],
                            ],
                        ],
                    ],
                    'database' => [
                        'segments' => [
                            'tables' => [],
                        ],
                    ],
                ],
                'manifest' => [
                    'filesystem' => [
                        'artifacts' => [
                            'chunks' => [
                                [
                                    'path' => $chunkPath,
                                    'checksum_sha256' => str_repeat('0', 64),
                                ],
                            ],
                        ],
                    ],
                    'database' => [
                        'segments' => [
                            'tables' => [],
                        ],
                    ],
                ],
            ]);

            self::assertSame('invalid', $result['status']);
            self::assertStringContainsString('Filesystem chunk checksum mismatch', $result['issues'][0]);
        }

        private function manifestChecksum(array $manifest): string
        {
            $manifest['package']['integrity']['manifest_checksum_sha256'] = '';

            return hash('sha256', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}

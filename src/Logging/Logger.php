<?php

declare(strict_types=1);

namespace SafeMigrate\Logging;

use wpdb;

final class Logger
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function info(?int $jobId, string $message, array $context = []): void
    {
        $this->log($jobId, 'info', $message, $context);
    }

    public function error(?int $jobId, string $message, array $context = []): void
    {
        $this->log($jobId, 'error', $message, $context);
    }

    public function log(?int $jobId, string $level, string $message, array $context = []): void
    {
        $this->wpdb->insert(
            $this->tableName(),
            [
                'job_id' => $jobId,
                'level' => $level,
                'message' => $message,
                'context_json' => wp_json_encode($context, JSON_UNESCAPED_SLASHES),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forJob(int $jobId, int $limit = 200): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->tableName() . ' WHERE job_id = %d ORDER BY id ASC LIMIT %d',
                $jobId,
                $limit
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['job_id'] = $row['job_id'] !== null ? (int) $row['job_id'] : null;
                $row['context'] = $row['context_json'] !== null ? json_decode((string) $row['context_json'], true) : [];

                return $row;
            },
            $rows
        );
    }

    private function tableName(): string
    {
        return $this->wpdb->prefix . 'safe_migrate_logs';
    }
}

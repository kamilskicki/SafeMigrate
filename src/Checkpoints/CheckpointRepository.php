<?php

declare(strict_types=1);

namespace SafeMigrate\Checkpoints;

use wpdb;

final class CheckpointRepository
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function create(int $jobId, string $stage, array $state): int
    {
        $this->wpdb->insert(
            $this->tableName(),
            [
                'job_id' => $jobId,
                'stage' => $stage,
                'state_json' => wp_json_encode($state, JSON_UNESCAPED_SLASHES),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s']
        );

        return (int) $this->wpdb->insert_id;
    }

    public function latestForJob(int $jobId): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->tableName() . ' WHERE job_id = %d ORDER BY id DESC LIMIT 1',
                $jobId
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forJob(int $jobId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->tableName() . ' WHERE job_id = %d ORDER BY id ASC',
                $jobId
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['job_id'] = (int) $row['job_id'];
        $row['state'] = $row['state_json'] !== null ? json_decode((string) $row['state_json'], true) : [];

        return $row;
    }

    private function tableName(): string
    {
        return $this->wpdb->prefix . 'safe_migrate_checkpoints';
    }
}

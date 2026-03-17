<?php

declare(strict_types=1);

namespace SafeMigrate\Jobs;

use wpdb;

final class JobRepository
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function create(string $type, string $status, array $payload = [], int $progressPercent = 0): int
    {
        $now = current_time('mysql', true);

        $this->wpdb->insert(
            $this->tableName(),
            [
                'type' => $type,
                'status' => $status,
                'payload_json' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
                'progress_percent' => $progressPercent,
                'started_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        return (int) $this->wpdb->insert_id;
    }

    public function update(int $jobId, array $data): void
    {
        $mapped = [];
        $formats = [];

        if (array_key_exists('status', $data)) {
            $mapped['status'] = (string) $data['status'];
            $formats[] = '%s';
        }

        if (array_key_exists('payload', $data)) {
            $mapped['payload_json'] = wp_json_encode($data['payload'], JSON_UNESCAPED_SLASHES);
            $formats[] = '%s';
        }

        if (array_key_exists('progress_percent', $data)) {
            $mapped['progress_percent'] = (int) $data['progress_percent'];
            $formats[] = '%d';
        }

        if (array_key_exists('finished_at', $data)) {
            $mapped['finished_at'] = (string) $data['finished_at'];
            $formats[] = '%s';
        }

        $mapped['updated_at'] = current_time('mysql', true);
        $formats[] = '%s';

        $this->wpdb->update(
            $this->tableName(),
            $mapped,
            ['id' => $jobId],
            $formats,
            ['%d']
        );
    }

    public function find(int $jobId): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->tableName() . ' WHERE id = %d',
                $jobId
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array<int, string> $types
     * @param array<int, string> $statuses
     */
    public function findLatestMatching(array $types, array $statuses = [], int $limit = 1): array
    {
        if ($types === []) {
            return [];
        }

        $typePlaceholders = implode(', ', array_fill(0, count($types), '%s'));
        $sql = 'SELECT * FROM ' . $this->tableName() . ' WHERE type IN (' . $typePlaceholders . ')';
        $params = $types;

        if ($statuses !== []) {
            $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '%s'));
            $sql .= ' AND status IN (' . $statusPlaceholders . ')';
            array_push($params, ...$statuses);
        }

        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = $limit;

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 10): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->tableName() . ' ORDER BY id DESC LIMIT %d',
                $limit
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
        $row['progress_percent'] = (int) $row['progress_percent'];
        $row['payload'] = $row['payload_json'] !== null ? json_decode((string) $row['payload_json'], true) : [];

        return $row;
    }

    private function tableName(): string
    {
        return $this->wpdb->prefix . 'safe_migrate_jobs';
    }
}

<?php

declare(strict_types=1);

namespace SafeMigrate\Export;

use wpdb;

final class DatabaseSegmentExporter
{
    private const ROWS_PER_SEGMENT = 1000;

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function export(array $manifest): array
    {
        $artifactDirectory = (string) ($manifest['artifacts']['directory'] ?? '');
        $databaseDirectory = $artifactDirectory . '/database';
        $this->ensureDirectory($databaseDirectory, 'database export');

        $segments = [];
        $totalSegments = 0;
        $excludedTables = array_values((array) ($manifest['database']['excluded_tables'] ?? []));

        foreach (($manifest['database']['tables'] ?? []) as $tableMeta) {
            $table = (string) ($tableMeta['name'] ?? '');

            if ($table === '' || in_array($table, $excludedTables, true)) {
                continue;
            }

            $tableSummary = $this->exportTable($table, $databaseDirectory);
            $segments[] = $tableSummary;
            $totalSegments += (int) $tableSummary['segment_count'];
        }

        return [
            'directory' => $databaseDirectory,
            'rows_per_segment' => self::ROWS_PER_SEGMENT,
            'segment_count' => $totalSegments,
            'excluded_tables' => $excludedTables,
            'tables' => $segments,
        ];
    }

    private function exportTable(string $table, string $databaseDirectory): array
    {
        $safeTable = sanitize_title_with_dashes($table);
        $tableDirectory = $databaseDirectory . '/' . $safeTable;
        $this->ensureDirectory($tableDirectory, sprintf('table export for %s', $table));

        $createStatement = $this->wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_A);
        $schemaSql = isset($createStatement['Create Table'])
            ? "DROP TABLE IF EXISTS `{$table}`;\n" . $createStatement['Create Table'] . ";\n"
            : '';

        $schemaPath = $tableDirectory . '/schema.sql';
        $this->writeFile($schemaPath, $schemaSql, sprintf('schema for table %s', $table));

        $primaryKeyColumns = $this->primaryKeyColumns($table);
        $summary = $primaryKeyColumns === []
            ? $this->exportTableByOffset($table, $tableDirectory)
            : $this->exportTableByPrimaryKey($table, $tableDirectory, $primaryKeyColumns);

        return [
            'table' => $table,
            'directory' => $tableDirectory,
            'rows' => $summary['rows'],
            'segment_count' => $summary['segment_count'],
            'schema_path' => $schemaPath,
            'schema_checksum_sha256' => $this->checksum($schemaPath, sprintf('schema for table %s', $table)),
            'parts' => $summary['parts'],
        ];
    }

    /**
     * @return array{rows: int, segment_count: int, parts: array<int, array<string, mixed>>}
     */
    private function exportTableByOffset(string $table, string $tableDirectory): array
    {
        $offset = 0;
        $rowCount = 0;
        $segmentCount = 0;
        $parts = [];

        while (true) {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->identifier($table)} LIMIT %d OFFSET %d",
                    self::ROWS_PER_SEGMENT,
                    $offset
                ),
                ARRAY_A
            );

            if (! is_array($rows) || $rows === []) {
                break;
            }

            $segmentCount++;
            $rowCount += count($rows);
            $parts[] = $this->writePart($table, $tableDirectory, $segmentCount, $rows);
            $offset += self::ROWS_PER_SEGMENT;
        }

        return [
            'rows' => $rowCount,
            'segment_count' => $segmentCount,
            'parts' => $parts,
        ];
    }

    /**
     * @param array<int, string> $primaryKeyColumns
     * @return array{rows: int, segment_count: int, parts: array<int, array<string, mixed>>}
     */
    private function exportTableByPrimaryKey(string $table, string $tableDirectory, array $primaryKeyColumns): array
    {
        $cursor = null;
        $rowCount = 0;
        $segmentCount = 0;
        $parts = [];

        while (true) {
            $rows = $this->fetchPrimaryKeyBatch($table, $primaryKeyColumns, $cursor);

            if (! is_array($rows) || $rows === []) {
                break;
            }

            $segmentCount++;
            $rowCount += count($rows);
            $parts[] = $this->writePart($table, $tableDirectory, $segmentCount, $rows);

            $lastRow = $rows[array_key_last($rows)];
            $cursor = [];

            foreach ($primaryKeyColumns as $column) {
                $cursor[] = $lastRow[$column];
            }

            if (count($rows) < self::ROWS_PER_SEGMENT) {
                break;
            }
        }

        return [
            'rows' => $rowCount,
            'segment_count' => $segmentCount,
            'parts' => $parts,
        ];
    }

    /**
     * @param array<int, string> $primaryKeyColumns
     * @param array<int, mixed>|null $cursor
     * @return array<int, array<string, mixed>>
     */
    private function fetchPrimaryKeyBatch(string $table, array $primaryKeyColumns, ?array $cursor): array
    {
        $orderBy = implode(', ', array_map(
            fn (string $column): string => $this->identifier($column) . ' ASC',
            $primaryKeyColumns
        ));

        if ($cursor === null) {
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->identifier($table)} ORDER BY {$orderBy} LIMIT %d",
                self::ROWS_PER_SEGMENT
            );
        } else {
            $columnTuple = '(' . implode(', ', array_map($this->identifier(...), $primaryKeyColumns)) . ')';
            $placeholders = '(' . implode(', ', array_fill(0, count($primaryKeyColumns), '%s')) . ')';
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->identifier($table)} WHERE {$columnTuple} > {$placeholders} ORDER BY {$orderBy} LIMIT %d",
                [...$cursor, self::ROWS_PER_SEGMENT]
            );
        }

        $rows = $this->wpdb->get_results($query, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, string>
     */
    private function primaryKeyColumns(string $table): array
    {
        $rows = $this->wpdb->get_results("SHOW KEYS FROM {$this->identifier($table)} WHERE Key_name = 'PRIMARY'", ARRAY_A);

        if (! is_array($rows) || $rows === []) {
            return [];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => ((int) ($left['Seq_in_index'] ?? 0)) <=> ((int) ($right['Seq_in_index'] ?? 0))
        );

        return array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['Column_name'] ?? ''),
            $rows
        )));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function writePart(string $table, string $tableDirectory, int $segmentCount, array $rows): array
    {
        $segmentSql = $this->buildInsertSql($table, $rows);
        $partPath = sprintf('%s/part-%04d.sql', $tableDirectory, $segmentCount);
        $this->ensureDirectory($tableDirectory, sprintf('table export for %s', $table));
        $this->writeFile($partPath, $segmentSql, sprintf('database segment %d for %s', $segmentCount, $table));

        return [
            'path' => $partPath,
            'rows' => count($rows),
            'checksum_sha256' => $this->checksum($partPath, sprintf('database segment %d for %s', $segmentCount, $table)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildInsertSql(string $table, array $rows): string
    {
        $columns = array_keys($rows[0]);
        $quotedColumns = array_map(
            static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
            $columns
        );

        $values = [];

        foreach ($rows as $row) {
            $encoded = array_map(fn ($value): string => $this->encodeValue($value), $row);
            $values[] = '(' . implode(', ', $encoded) . ')';
        }

        return sprintf(
            "INSERT INTO `%s` (%s) VALUES\n%s;\n",
            $table,
            implode(', ', $quotedColumns),
            implode(",\n", $values)
        );
    }

    private function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $string = (string) $value;

        if (method_exists($this->wpdb, '_real_escape')) {
            return "'" . $this->wpdb->_real_escape($string) . "'";
        }

        return "'" . addslashes($string) . "'";
    }

    private function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function ensureDirectory(string $directory, string $label): void
    {
        if ($directory === '') {
            throw new \RuntimeException(sprintf('Path for %s is empty.', $label));
        }

        wp_mkdir_p($directory);

        if (! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create %s directory at %s.', $label, $directory));
        }
    }

    private function writeFile(string $path, string $contents, string $label): void
    {
        $this->ensureDirectory(dirname($path), $label);

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf('Could not write %s to %s.', $label, $path));
        }
    }

    private function checksum(string $path, string $label): string
    {
        if (! is_file($path)) {
            throw new \RuntimeException(sprintf('Expected %s file does not exist at %s.', $label, $path));
        }

        $checksum = hash_file('sha256', $path);

        if (! is_string($checksum) || $checksum === '') {
            throw new \RuntimeException(sprintf('Could not checksum %s file %s.', $label, $path));
        }

        return $checksum;
    }
}

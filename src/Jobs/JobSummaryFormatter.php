<?php

declare(strict_types=1);

namespace SafeMigrate\Jobs;

final class JobSummaryFormatter
{
    public static function summarizeResult(array $result, string $key): array
    {
        return [
            'job_id' => (int) ($result['job']['id'] ?? 0),
            'status' => (string) ($result['job']['status'] ?? 'unknown'),
            $key => self::summarizePayload($key, $result[$key] ?? []),
        ];
    }

    public static function summarizeJob(array $job): array
    {
        $type = (string) ($job['type'] ?? 'unknown');
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $key = self::payloadKeyForType($type);

        return [
            'id' => (int) ($job['id'] ?? 0),
            'type' => $type,
            'status' => (string) ($job['status'] ?? 'unknown'),
            'progress_percent' => (int) ($job['progress_percent'] ?? 0),
            'started_at' => (string) ($job['started_at'] ?? ''),
            'updated_at' => (string) ($job['updated_at'] ?? ''),
            'finished_at' => (string) ($job['finished_at'] ?? ''),
            'current_stage' => (string) ($payload['current_stage'] ?? $payload['push_pull_progress']['stage'] ?? ''),
            'summary' => $key === null ? [] : self::summarizePayload($key, self::payloadValue($payload, $type, $key)),
        ];
    }

    public static function summarizePayload(string $key, mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        return match ($key) {
            'preflight' => [
                'status' => (string) ($payload['status'] ?? 'unknown'),
                'health_score' => (int) ($payload['health_score'] ?? 0),
                'blocker_count' => count((array) ($payload['blockers'] ?? [])),
                'warning_count' => count((array) ($payload['warnings'] ?? [])),
            ],
            'plan' => [
                'artifact_directory' => (string) ($payload['artifact_directory'] ?? ''),
                'manifest_path' => (string) ($payload['manifest_path'] ?? ''),
                'total_files' => (int) ($payload['total_files'] ?? 0),
                'total_bytes' => (int) ($payload['total_bytes'] ?? 0),
                'chunk_count' => (int) ($payload['chunk_count'] ?? 0),
                'database_tables' => (int) ($payload['database_tables'] ?? 0),
                'database_bytes' => (int) ($payload['database_bytes'] ?? 0),
            ],
            'export' => [
                'artifact_directory' => (string) ($payload['artifact_directory'] ?? ''),
                'manifest_path' => (string) ($payload['manifest_path'] ?? ''),
                'total_files' => (int) ($payload['total_files'] ?? 0),
                'total_bytes' => (int) ($payload['total_bytes'] ?? 0),
                'file_chunk_count' => (int) ($payload['file_chunk_count'] ?? 0),
                'database_tables' => (int) ($payload['database_tables'] ?? 0),
                'database_segment_count' => (int) ($payload['database_segment_count'] ?? 0),
                'filesystem_format' => (string) ($payload['filesystem_format'] ?? ''),
                'package_kind' => (string) ($payload['package_kind'] ?? ''),
            ],
            'validation' => [
                'status' => (string) ($payload['status'] ?? 'unknown'),
                'issue_count' => count((array) ($payload['issues'] ?? [])),
                'issues' => array_values((array) ($payload['issues'] ?? [])),
            ],
            'preview' => [
                'artifact_directory' => (string) ($payload['artifact_directory'] ?? ''),
                'workspace_base' => (string) ($payload['workspace']['base'] ?? ''),
                'filesystem_chunks' => (int) ($payload['filesystem_chunks'] ?? 0),
                'database_segments' => (int) ($payload['database_segments'] ?? 0),
                'remap_rule_count' => count((array) ($payload['remap_rules'] ?? [])),
                'status' => (string) ($payload['status'] ?? 'unknown'),
            ],
            'restore' => [
                'artifact_directory' => (string) ($payload['artifact_directory'] ?? ''),
                'snapshot_artifact_directory' => (string) ($payload['snapshot_artifact_directory'] ?? ''),
                'workspace_base' => (string) ($payload['workspace']['base'] ?? ''),
                'current_stage' => (string) ($payload['current_stage'] ?? ''),
                'status' => (string) ($payload['status'] ?? 'unknown'),
            ],
            'rollback' => [
                'source_job_id' => (int) ($payload['source_job_id'] ?? 0),
                'snapshot_artifact_directory' => (string) ($payload['snapshot_artifact_directory'] ?? ''),
                'workspace_base' => (string) ($payload['workspace']['base'] ?? ''),
                'applied_chunks' => (int) ($payload['filesystem']['applied_chunks'] ?? 0),
                'applied_segments' => (int) ($payload['database']['applied_segments'] ?? 0),
                'verification_status' => (string) ($payload['verification']['status'] ?? 'unknown'),
                'status' => (string) ($payload['status'] ?? 'unknown'),
            ],
            'push_pull' => [
                'source_url' => (string) ($payload['source_url'] ?? ''),
                'artifact_directory' => (string) ($payload['artifact_directory'] ?? ''),
                'remote_export_job_id' => (int) ($payload['remote_export_job_id'] ?? 0),
                'validation_status' => (string) ($payload['validation']['status'] ?? 'unknown'),
                'remote_total_files' => (int) ($payload['remote_export']['total_files'] ?? 0),
                'remote_chunk_count' => (int) ($payload['remote_export']['file_chunk_count'] ?? 0),
                'remote_database_segments' => (int) ($payload['remote_export']['database_segment_count'] ?? 0),
                'stage' => (string) ($payload['transfer_progress']['stage'] ?? ''),
                'downloaded_chunks' => (int) ($payload['transfer_progress']['downloaded_chunks'] ?? 0),
                'total_chunks' => (int) ($payload['transfer_progress']['total_chunks'] ?? 0),
                'downloaded_tables' => (int) ($payload['transfer_progress']['downloaded_tables'] ?? 0),
                'total_tables' => (int) ($payload['transfer_progress']['total_tables'] ?? 0),
            ],
            'support_bundle' => [
                'directory' => (string) ($payload['directory'] ?? ''),
                'path' => (string) ($payload['path'] ?? ''),
                'generated_at' => (string) ($payload['generated_at'] ?? ''),
                'checkpoint_count' => (int) ($payload['checkpoint_count'] ?? 0),
                'log_count' => (int) ($payload['log_count'] ?? 0),
                'redacted' => (bool) ($payload['redacted'] ?? false),
            ],
            'cleanup' => [
                'retention' => $payload['retention'] ?? [],
                'removed_exports' => count((array) ($payload['exports'] ?? [])),
                'removed_restores' => count((array) ($payload['restores'] ?? [])),
            ],
            default => $payload,
        };
    }

    private static function payloadKeyForType(string $type): ?string
    {
        return match ($type) {
            'preflight' => 'preflight',
            'export_plan' => 'plan',
            'export' => 'export',
            'validate_package' => 'validation',
            'restore_preview' => 'preview',
            'restore_execute' => 'restore',
            'restore_rollback' => 'rollback',
            'push_pull' => 'push_pull',
            'cleanup_artifacts' => 'cleanup',
            'restore_simulation' => 'preview',
            default => null,
        };
    }

    private static function payloadValue(array $payload, string $type, string $key): array
    {
        return match ($type) {
            'preflight' => (array) ($payload['report'] ?? []),
            'export_plan' => (array) ($payload['export_plan_summary'] ?? []),
            'export' => (array) ($payload['export_summary'] ?? []),
            'validate_package' => (array) ($payload['validation'] ?? []),
            'restore_preview' => (array) ($payload['restore_preview'] ?? []),
            'restore_execute' => [
                'artifact_directory' => (string) ($payload['artifact_directory'] ?? ''),
                'snapshot_artifact_directory' => (string) ($payload['snapshot_summary']['artifact_directory'] ?? ''),
                'workspace' => (array) ($payload['workspace'] ?? []),
                'current_stage' => (string) ($payload['current_stage'] ?? ''),
                'status' => (string) ($payload['current_stage'] ?? ($payload['failure']['stage'] ?? 'unknown')),
            ],
            'restore_rollback' => (array) ($payload['rollback_summary'] ?? []),
            'push_pull' => (array) ($payload['push_pull'] ?? []),
            'cleanup_artifacts' => (array) ($payload['cleanup'] ?? []),
            'restore_simulation' => (array) ($payload['restore_simulation'] ?? []),
            default => [],
        };
    }
}

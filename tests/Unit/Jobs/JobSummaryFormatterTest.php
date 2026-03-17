<?php

declare(strict_types=1);

namespace SafeMigrate\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use SafeMigrate\Jobs\JobSummaryFormatter;

final class JobSummaryFormatterTest extends TestCase
{
    public function testSummarizesPushPullPayloadWithTransferProgress(): void
    {
        $summary = JobSummaryFormatter::summarizePayload('push_pull', [
            'source_url' => 'https://source.example',
            'artifact_directory' => '/tmp/job-100',
            'remote_export_job_id' => 55,
            'validation' => ['status' => 'valid'],
            'remote_export' => [
                'total_files' => 300,
                'file_chunk_count' => 7,
                'database_segment_count' => 12,
            ],
            'transfer_progress' => [
                'stage' => 'artifact_download',
                'downloaded_chunks' => 4,
                'total_chunks' => 7,
                'downloaded_tables' => 3,
                'total_tables' => 9,
            ],
        ]);

        self::assertSame('https://source.example', $summary['source_url']);
        self::assertSame('/tmp/job-100', $summary['artifact_directory']);
        self::assertSame(55, $summary['remote_export_job_id']);
        self::assertSame('valid', $summary['validation_status']);
        self::assertSame(300, $summary['remote_total_files']);
        self::assertSame(7, $summary['remote_chunk_count']);
        self::assertSame(12, $summary['remote_database_segments']);
        self::assertSame('artifact_download', $summary['stage']);
        self::assertSame(4, $summary['downloaded_chunks']);
        self::assertSame(7, $summary['total_chunks']);
        self::assertSame(3, $summary['downloaded_tables']);
        self::assertSame(9, $summary['total_tables']);
    }

    public function testSummarizesPushPullJobMetadata(): void
    {
        $job = JobSummaryFormatter::summarizeJob([
            'id' => 42,
            'type' => 'push_pull',
            'status' => 'running',
            'progress_percent' => 67,
            'started_at' => '2026-03-16 14:00:00',
            'updated_at' => '2026-03-16 14:05:00',
            'finished_at' => null,
            'payload' => [
                'push_pull_progress' => [
                    'stage' => 'filesystem_downloaded',
                ],
                'push_pull' => [
                    'artifact_directory' => '/tmp/job-42',
                    'validation' => ['status' => 'unknown'],
                    'transfer_progress' => [
                        'stage' => 'filesystem_downloaded',
                        'downloaded_chunks' => 10,
                        'total_chunks' => 12,
                        'downloaded_tables' => 0,
                        'total_tables' => 9,
                    ],
                ],
            ],
        ]);

        self::assertSame(42, $job['id']);
        self::assertSame('push_pull', $job['type']);
        self::assertSame('running', $job['status']);
        self::assertSame(67, $job['progress_percent']);
        self::assertSame('filesystem_downloaded', $job['current_stage']);
        self::assertSame('/tmp/job-42', $job['summary']['artifact_directory']);
        self::assertSame(10, $job['summary']['downloaded_chunks']);
        self::assertSame(12, $job['summary']['total_chunks']);
    }
}

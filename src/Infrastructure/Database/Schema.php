<?php

declare(strict_types=1);

namespace SafeMigrate\Infrastructure\Database;

use wpdb;

final class Schema
{
    private const SCHEMA_VERSION = '0.1.0';

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $this->wpdb->get_charset_collate();
        $jobs = $this->wpdb->prefix . 'safe_migrate_jobs';
        $checkpoints = $this->wpdb->prefix . 'safe_migrate_checkpoints';
        $logs = $this->wpdb->prefix . 'safe_migrate_logs';

        dbDelta(
            "CREATE TABLE {$jobs} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                type varchar(50) NOT NULL,
                status varchar(30) NOT NULL,
                payload_json longtext NULL,
                progress_percent tinyint unsigned NOT NULL DEFAULT 0,
                started_at datetime NULL,
                updated_at datetime NOT NULL,
                finished_at datetime NULL,
                PRIMARY KEY  (id),
                KEY status (status),
                KEY updated_at (updated_at)
            ) {$charsetCollate};"
        );

        dbDelta(
            "CREATE TABLE {$checkpoints} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                job_id bigint unsigned NOT NULL,
                stage varchar(100) NOT NULL,
                state_json longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY job_id (job_id),
                KEY stage (stage)
            ) {$charsetCollate};"
        );

        dbDelta(
            "CREATE TABLE {$logs} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                job_id bigint unsigned NULL,
                level varchar(20) NOT NULL,
                message text NOT NULL,
                context_json longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY job_id (job_id),
                KEY level (level)
            ) {$charsetCollate};"
        );

        update_option('safe_migrate_schema_version', self::SCHEMA_VERSION, false);
    }
}

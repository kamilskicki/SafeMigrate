<?php

declare(strict_types=1);

namespace SafeMigrate\Admin;

use SafeMigrate\Contracts\RegistersHooks;
use SafeMigrate\Jobs\Capabilities;
use SafeMigrate\Jobs\JobRepository;
use SafeMigrate\Product\FeaturePolicy;
use SafeMigrate\Product\LicenseStateService;
use SafeMigrate\Product\SettingsService;
use SafeMigrate\Support\ArtifactPaths;
use SafeMigrate\Testing\FailureInjectionService;

final class AdminPage implements RegistersHooks
{
    private const MENU_SLUG = 'safe-migrate';

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly SettingsService $settingsService,
        private readonly LicenseStateService $licenseStateService,
        private readonly FeaturePolicy $featurePolicy,
        private readonly FailureInjectionService $failureInjectionService
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Safe Migrate', 'safe-migrate'),
            __('Safe Migrate', 'safe-migrate'),
            Capabilities::MANAGE,
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-migrate',
            58
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style('safe-migrate-admin', SAFE_MIGRATE_URL . 'assets/admin.css', [], SAFE_MIGRATE_VERSION);
        wp_enqueue_script('safe-migrate-admin', SAFE_MIGRATE_URL . 'assets/admin.js', [], SAFE_MIGRATE_VERSION, true);

        wp_add_inline_script('safe-migrate-admin', 'window.SafeMigrateAdmin=' . wp_json_encode([
            'baseUrl' => untrailingslashit(rest_url('safe-migrate/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'defaults' => [
                'artifactDirectory' => $this->latestArtifactDirectory('exports'),
                'rollbackJobId' => $this->latestRollbackJobId(),
                'resumeJobId' => $this->latestResumableJobId(),
                'supportBundleJobId' => $this->latestSupportBundleJobId(),
                'trackedJobId' => $this->latestTrackedJobId(),
            ],
            'state' => [
                'license' => $this->licenseStateService->get(),
                'settings' => $this->settingsService->get(),
                'featurePolicy' => ['isPro' => $this->featurePolicy->isPro()],
                'failureInjection' => $this->failureInjectionService->current(),
                'failureInjectionAvailable' => $this->failureInjectionService->isAvailable(),
            ],
        ]) . ';', 'before');
    }

    public function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE)) {
            wp_die(esc_html__('You are not allowed to access Safe Migrate.', 'safe-migrate'));
        }

        $jobs = $this->jobs->latest(10);
        $license = $this->licenseStateService->get();
        $settings = $this->settingsService->get();
        ?>
        <div class="wrap safe-migrate-admin">
            <div class="safe-migrate-hero">
                <div>
                    <h1><?php echo esc_html__('Safe Migrate 1.0', 'safe-migrate'); ?></h1>
                    <p class="safe-migrate-lead">
                        <?php echo esc_html__('Move a WordPress site with preview, checkpoints, snapshot rollback, push/pull transfer, and builder-aware safety checks. Safe Migrate is built for zero-fear local and site-to-site migrations.', 'safe-migrate'); ?>
                    </p>
                </div>
                <div class="safe-migrate-badges">
                    <span class="safe-migrate-badge"><?php echo esc_html(SAFE_MIGRATE_VERSION); ?></span>
                    <span class="safe-migrate-badge"><?php echo esc_html($this->featurePolicy->isPro() ? 'Pro active' : 'Core mode'); ?></span>
                    <span class="safe-migrate-badge"><?php echo esc_html($license['status'] ?? 'inactive'); ?></span>
                </div>
            </div>

            <div class="safe-migrate-grid safe-migrate-grid--wide">
                <section class="safe-migrate-card">
                    <h2><?php echo esc_html__('Operator Flow', 'safe-migrate'); ?></h2>
                    <ol class="safe-migrate-steps">
                        <li><?php echo esc_html__('Go / No-Go Preflight', 'safe-migrate'); ?></li>
                        <li><?php echo esc_html__('Build or Pull Package', 'safe-migrate'); ?></li>
                        <li><?php echo esc_html__('Validate Package', 'safe-migrate'); ?></li>
                        <li><?php echo esc_html__('Preview Restore', 'safe-migrate'); ?></li>
                        <li><?php echo esc_html__('Execute Restore', 'safe-migrate'); ?></li>
                        <li><?php echo esc_html__('Rollback / Resume', 'safe-migrate'); ?></li>
                        <li><?php echo esc_html__('Support Bundle / Cleanup', 'safe-migrate'); ?></li>
                    </ol>
                    <p class="description"><?php echo esc_html__('Every destructive restore is snapshot-backed. Push/Pull reuses the same package, validation, preview, and rollback contract as local exports.', 'safe-migrate'); ?></p>
                </section>

                <section class="safe-migrate-card">
                    <h2><?php echo esc_html__('License State', 'safe-migrate'); ?></h2>
                    <div class="safe-migrate-summary-grid">
                        <div class="safe-migrate-stat"><strong><?php echo esc_html((string) ($license['tier'] ?? 'core')); ?></strong><span><?php echo esc_html__('tier', 'safe-migrate'); ?></span></div>
                        <div class="safe-migrate-stat"><strong><?php echo esc_html((string) ($license['status'] ?? 'inactive')); ?></strong><span><?php echo esc_html__('status', 'safe-migrate'); ?></span></div>
                    </div>
                    <div class="safe-migrate-form" data-safe-migrate-form-runner data-endpoint="/license" data-renderer="license">
                        <label><span><?php echo esc_html__('License key', 'safe-migrate'); ?></span><input type="text" data-payload-key="license_key" value="<?php echo esc_attr((string) ($license['license_key'] ?? '')); ?>" /></label>
                        <label><span><?php echo esc_html__('Tier', 'safe-migrate'); ?></span><select data-payload-key="tier"><option value="core">core</option><option value="pro" <?php selected(($license['tier'] ?? '') === 'pro'); ?>>pro</option><option value="agency" <?php selected(($license['tier'] ?? '') === 'agency'); ?>>agency</option></select></label>
                        <label><span><?php echo esc_html__('Status', 'safe-migrate'); ?></span><select data-payload-key="status"><option value="inactive">inactive</option><option value="active" <?php selected(($license['status'] ?? '') === 'active'); ?>>active</option></select></label>
                        <button type="button" class="button button-secondary"><?php echo esc_html__('Save License State', 'safe-migrate'); ?></button>
                        <p class="description" data-role="status"><?php echo esc_html__('Direct-distribution license state lives locally in this 1.0 release. Migration, rollback, preview, and push/pull stay in the free core workflow.', 'safe-migrate'); ?></p>
                        <div class="safe-migrate-report" data-role="report"></div>
                    </div>
                </section>

                <section class="safe-migrate-card">
                    <h2><?php echo esc_html__('Settings', 'safe-migrate'); ?></h2>
                    <div class="safe-migrate-form" data-safe-migrate-form-runner data-endpoint="/settings" data-renderer="settings">
                        <label><span><?php echo esc_html__('Retain exports', 'safe-migrate'); ?></span><input type="number" data-payload-key="cleanup.retain_exports" value="<?php echo esc_attr((string) ($settings['cleanup']['retain_exports'] ?? 3)); ?>" /></label>
                        <label><span><?php echo esc_html__('Retain restores', 'safe-migrate'); ?></span><input type="number" data-payload-key="cleanup.retain_restores" value="<?php echo esc_attr((string) ($settings['cleanup']['retain_restores'] ?? 3)); ?>" /></label>
                        <label><span><?php echo esc_html__('Exclude patterns', 'safe-migrate'); ?></span><textarea rows="4" data-payload-key="migration.exclude_patterns"><?php echo esc_textarea(implode("\n", (array) ($settings['migration']['exclude_patterns'] ?? []))); ?></textarea></label>
                        <label><span><?php echo esc_html__('Include prefixes', 'safe-migrate'); ?></span><textarea rows="4" data-payload-key="migration.include_prefixes"><?php echo esc_textarea(implode("\n", (array) ($settings['migration']['include_prefixes'] ?? []))); ?></textarea></label>
                        <label class="safe-migrate-checkbox"><input type="checkbox" data-payload-key="support_bundle.redact_sensitive" value="1" <?php checked((bool) ($settings['support_bundle']['redact_sensitive'] ?? false)); ?> /><span><?php echo esc_html__('Redact support bundle secrets (Pro)', 'safe-migrate'); ?></span></label>
                        <button type="button" class="button button-secondary"><?php echo esc_html__('Save Settings', 'safe-migrate'); ?></button>
                        <p class="description" data-role="status"><?php echo esc_html__('Core keeps default cleanup and migration scope. Pro unlocks custom retention, include/exclude rules, and redaction.', 'safe-migrate'); ?></p>
                        <div class="safe-migrate-report" data-role="report"></div>
                    </div>
                </section>
            </div>

            <div class="safe-migrate-grid">
                <?php $this->renderActionCard('Go / No-Go Preflight', '/preflight', 'preflight', __('Run Preflight', 'safe-migrate'), __('Checks loopback health, writable paths, builder risk signals, and whether this site is ready to move or restore.', 'safe-migrate')); ?>
                <?php $this->renderActionCard('Build Export', '/export-plan', 'export-plan', __('Build Export Plan', 'safe-migrate'), __('Builds manifest, scope, chunk plan, and DB inventory before packaging.', 'safe-migrate')); ?>
                <?php $this->renderActionCard('Build Export Package', '/export', 'export', __('Run Export', 'safe-migrate'), __('Creates a local export package with checksums, segmented SQL, and file chunks.', 'safe-migrate')); ?>
                <?php $this->renderTransferTokenCard(); ?>
                <?php $this->renderPushPullCard(); ?>
                <?php $this->renderArtifactCard('Validate Package', '/validate-package', 'validation', __('Validate Package', 'safe-migrate')); ?>
                <?php $this->renderArtifactCard('Preview Restore', '/restore-preview', 'preview', __('Build Restore Preview', 'safe-migrate')); ?>
                <?php $this->renderArtifactCard('Execute Restore', '/restore-execute', 'restore-execute', __('Execute Restore', 'safe-migrate'), true); ?>
                <?php $this->renderJobCard('Rollback', '/restore-rollback', 'rollback', __('Run Rollback', 'safe-migrate'), (string) $this->latestRollbackJobId()); ?>
                <?php $this->renderJobCard('Resume', '/resume-job', 'resume', __('Resume Job', 'safe-migrate'), (string) $this->latestResumableJobId()); ?>
                <?php $this->renderJobCard('Support Bundle', '/support-bundle', 'support-bundle', __('Export Support Bundle', 'safe-migrate'), (string) $this->latestSupportBundleJobId()); ?>
                <?php $this->renderActionCard('Cleanup', '/cleanup-artifacts', 'cleanup', __('Run Cleanup', 'safe-migrate'), __('Removes older exports and restore workspaces based on current retention policy.', 'safe-migrate')); ?>
                <?php if ($this->shouldRenderTestingUi()): ?>
                    <?php $this->renderFailureCard(); ?>
                <?php endif; ?>
                <?php $this->renderJobMonitorCard(); ?>
                <section class="safe-migrate-card">
                    <h2><?php echo esc_html__('Recent Jobs', 'safe-migrate'); ?></h2>
                    <?php if ($jobs === []): ?>
                        <p><?php echo esc_html__('No jobs recorded yet.', 'safe-migrate'); ?></p>
                    <?php else: ?>
                        <table class="widefat striped" data-safe-migrate-jobs-table>
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('ID', 'safe-migrate'); ?></th>
                                    <th><?php echo esc_html__('Type', 'safe-migrate'); ?></th>
                                    <th><?php echo esc_html__('Status', 'safe-migrate'); ?></th>
                                    <th><?php echo esc_html__('Stage', 'safe-migrate'); ?></th>
                                    <th><?php echo esc_html__('Progress', 'safe-migrate'); ?></th>
                                    <th><?php echo esc_html__('Updated', 'safe-migrate'); ?></th>
                                </tr>
                            </thead>
                            <tbody data-role="recent-jobs">
                                <?php foreach ($jobs as $job): ?>
                                    <tr>
                                        <td><?php echo esc_html((string) $job['id']); ?></td>
                                        <td><?php echo esc_html((string) $job['type']); ?></td>
                                        <td><?php echo esc_html((string) $job['status']); ?></td>
                                        <td><?php echo esc_html((string) ($job['payload']['current_stage'] ?? $job['payload']['failure']['stage'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) $job['progress_percent']); ?>%</td>
                                        <td><?php echo esc_html((string) $job['updated_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php
    }

    private function renderTransferTokenCard(): void
    {
        ?>
        <section class="safe-migrate-card" data-safe-migrate-runner data-endpoint="/transfer-token" data-renderer="transfer-token">
            <h2><?php echo esc_html__('Source Transfer Token', 'safe-migrate'); ?></h2>
            <p><?php echo esc_html__('Generate a one-time token on the source site, then paste it into the Push / Pull card on the destination site.', 'safe-migrate'); ?></p>
            <button type="button" class="button button-secondary"><?php echo esc_html__('Generate Transfer Token', 'safe-migrate'); ?></button>
            <p class="description" data-role="status"><?php echo esc_html__('Ready.', 'safe-migrate'); ?></p>
            <div class="safe-migrate-report" data-role="report"></div>
        </section>
        <?php
    }

    private function renderPushPullCard(): void
    {
        ?>
        <section class="safe-migrate-card" data-safe-migrate-form-runner data-endpoint="/push-pull" data-renderer="push-pull">
            <h2><?php echo esc_html__('Push / Pull Migration', 'safe-migrate'); ?></h2>
            <p><?php echo esc_html__('Connect to a source site, run remote preflight, build a remote export, and pull the package into this site for local validate / preview / restore.', 'safe-migrate'); ?></p>
            <label><span><?php echo esc_html__('Source site URL', 'safe-migrate'); ?></span><input type="text" class="code" data-payload-key="source_url" placeholder="https://source-site.example" /></label>
            <label><span><?php echo esc_html__('Transfer token', 'safe-migrate'); ?></span><input type="text" class="code" data-payload-key="transfer_token" placeholder="paste the source token here" /></label>
            <button type="button" class="button button-primary"><?php echo esc_html__('Pull Package From Source', 'safe-migrate'); ?></button>
            <p class="description" data-role="status"><?php echo esc_html__('Ready.', 'safe-migrate'); ?></p>
            <div class="safe-migrate-report" data-role="report"></div>
        </section>
        <?php
    }

    private function renderActionCard(string $title, string $endpoint, string $renderer, string $buttonLabel, string $description): void
    {
        ?>
        <section class="safe-migrate-card" data-safe-migrate-runner data-endpoint="<?php echo esc_attr($endpoint); ?>" data-renderer="<?php echo esc_attr($renderer); ?>">
            <h2><?php echo esc_html($title); ?></h2>
            <p><?php echo esc_html($description); ?></p>
            <button type="button" class="button button-primary"><?php echo esc_html($buttonLabel); ?></button>
            <p class="description" data-role="status"><?php echo esc_html__('Ready.', 'safe-migrate'); ?></p>
            <div class="safe-migrate-report" data-role="report"></div>
        </section>
        <?php
    }

    private function renderArtifactCard(string $title, string $endpoint, string $renderer, string $buttonLabel, bool $destructive = false): void
    {
        ?>
        <section class="safe-migrate-card" data-safe-migrate-form-runner data-endpoint="<?php echo esc_attr($endpoint); ?>" data-renderer="<?php echo esc_attr($renderer); ?>">
            <h2><?php echo esc_html($title); ?></h2>
            <label><span><?php echo esc_html__('Artifact directory', 'safe-migrate'); ?></span><input type="text" class="code" data-payload-key="artifact_directory" value="<?php echo esc_attr($this->latestArtifactDirectory('exports')); ?>" /></label>
            <?php if ($destructive): ?>
                <input type="hidden" data-payload-key="confirm_destructive" value="1" />
            <?php endif; ?>
            <button type="button" class="button <?php echo esc_attr($destructive ? 'button-primary' : 'button-secondary'); ?>"><?php echo esc_html($buttonLabel); ?></button>
            <p class="description" data-role="status"><?php echo esc_html__('Ready.', 'safe-migrate'); ?></p>
            <div class="safe-migrate-report" data-role="report"></div>
        </section>
        <?php
    }

    private function renderJobCard(string $title, string $endpoint, string $renderer, string $buttonLabel, string $jobId): void
    {
        ?>
        <section class="safe-migrate-card" data-safe-migrate-form-runner data-endpoint="<?php echo esc_attr($endpoint); ?>" data-renderer="<?php echo esc_attr($renderer); ?>">
            <h2><?php echo esc_html($title); ?></h2>
            <label><span><?php echo esc_html__('Job ID', 'safe-migrate'); ?></span><input type="number" data-payload-key="job_id" value="<?php echo esc_attr($jobId); ?>" /></label>
            <button type="button" class="button button-secondary"><?php echo esc_html($buttonLabel); ?></button>
            <p class="description" data-role="status"><?php echo esc_html__('Ready.', 'safe-migrate'); ?></p>
            <div class="safe-migrate-report" data-role="report"></div>
        </section>
        <?php
    }

    private function renderFailureCard(): void
    {
        $failure = $this->failureInjectionService->current();
        $stage = (string) ($failure['stage'] ?? '');
        ?>
        <section class="safe-migrate-card" data-safe-migrate-form-runner data-endpoint="/failure-injection" data-renderer="failure-injection">
            <h2><?php echo esc_html__('Failure Injection', 'safe-migrate'); ?></h2>
            <p><?php echo esc_html__('Testing-only hook to force failure after snapshot, filesystem, database, or verification and prove rollback semantics.', 'safe-migrate'); ?></p>
            <label><span><?php echo esc_html__('Stage', 'safe-migrate'); ?></span><select data-payload-key="stage"><option value="">off</option><option value="after_snapshot" <?php selected($stage === 'after_snapshot'); ?>>after_snapshot</option><option value="after_filesystem" <?php selected($stage === 'after_filesystem'); ?>>after_filesystem</option><option value="after_database" <?php selected($stage === 'after_database'); ?>>after_database</option><option value="verification" <?php selected($stage === 'verification'); ?>>verification</option></select></label>
            <label><span><?php echo esc_html__('Message', 'safe-migrate'); ?></span><input type="text" data-payload-key="message" value="<?php echo esc_attr((string) ($failure['message'] ?? '')); ?>" /></label>
            <label class="safe-migrate-checkbox"><input type="checkbox" data-payload-key="enabled" value="1" <?php checked((bool) ($failure['enabled'] ?? false)); ?> /><span><?php echo esc_html__('Enable injection', 'safe-migrate'); ?></span></label>
            <label class="safe-migrate-checkbox"><input type="checkbox" data-payload-key="once" value="1" <?php checked((bool) ($failure['once'] ?? true)); ?> /><span><?php echo esc_html__('Clear after one trigger', 'safe-migrate'); ?></span></label>
            <div class="safe-migrate-actions">
                <button type="button" class="button button-secondary"><?php echo esc_html__('Save Failure Hook', 'safe-migrate'); ?></button>
                <button type="button" class="button" data-safe-migrate-delete="/failure-injection"><?php echo esc_html__('Clear', 'safe-migrate'); ?></button>
            </div>
            <p class="description" data-role="status"><?php echo esc_html__('Available only outside production or when SAFE_MIGRATE_ENABLE_TESTING is enabled.', 'safe-migrate'); ?></p>
            <div class="safe-migrate-report" data-role="report"></div>
        </section>
        <?php
    }

    private function renderJobMonitorCard(): void
    {
        ?>
        <section class="safe-migrate-card" data-safe-migrate-job-monitor>
            <h2><?php echo esc_html__('Live Job Monitor', 'safe-migrate'); ?></h2>
            <p><?php echo esc_html__('Track the latest push/pull or restore job. Running jobs auto-refresh so you can see stage and progress without reloading the page.', 'safe-migrate'); ?></p>
            <label><span><?php echo esc_html__('Job ID', 'safe-migrate'); ?></span><input type="number" data-job-monitor-id value="<?php echo esc_attr((string) $this->latestTrackedJobId()); ?>" /></label>
            <button type="button" class="button button-secondary" data-safe-migrate-refresh-job><?php echo esc_html__('Refresh Job', 'safe-migrate'); ?></button>
            <p class="description" data-role="status"><?php echo esc_html__('Ready.', 'safe-migrate'); ?></p>
            <div class="safe-migrate-report" data-role="report"></div>
        </section>
        <?php
    }

    private function latestArtifactDirectory(string $group): string
    {
        $directories = $group === 'exports'
            ? ArtifactPaths::exportDirectories()
            : ArtifactPaths::restoreDirectories();

        if ($directories === []) {
            return '';
        }

        return (string) $directories[0];
    }

    /**
     * @param array<int, string> $statuses
     */
    private function latestRestoreJobId(array $statuses): int
    {
        $matches = $this->jobs->findLatestMatching(['restore_execute'], $statuses, 1);

        return $matches === [] ? 0 : (int) ($matches[0]['id'] ?? 0);
    }

    private function latestRollbackJobId(): int
    {
        $matches = $this->jobs->findLatestMatching(['restore_execute'], ['failed', 'rollback_failed', 'rolled_back'], 10);

        foreach ($matches as $job) {
            if ((string) ($job['payload']['snapshot_summary']['artifact_directory'] ?? '') !== '') {
                return (int) ($job['id'] ?? 0);
            }
        }

        return 0;
    }

    private function latestSupportBundleJobId(): int
    {
        $matches = $this->jobs->findLatestMatching(
            ['restore_execute', 'restore_rollback', 'restore_preview', 'validate_package', 'push_pull'],
            ['completed', 'failed', 'rollback_failed', 'rolled_back', 'needs_attention'],
            10
        );

        return $matches === [] ? 0 : (int) ($matches[0]['id'] ?? 0);
    }

    private function latestResumableJobId(): int
    {
        $matches = $this->jobs->findLatestMatching(
            ['restore_execute', 'push_pull'],
            ['running', 'failed', 'needs_attention'],
            5
        );

        return $matches === [] ? 0 : (int) ($matches[0]['id'] ?? 0);
    }

    private function latestTrackedJobId(): int
    {
        $matches = $this->jobs->findLatestMatching(
            ['push_pull', 'restore_execute', 'restore_rollback', 'export', 'validate_package', 'restore_preview'],
            ['running', 'failed', 'completed', 'rolled_back', 'rollback_failed', 'needs_attention'],
            1
        );

        return $matches === [] ? 0 : (int) ($matches[0]['id'] ?? 0);
    }

    private function shouldRenderTestingUi(): bool
    {
        return $this->failureInjectionService->isAvailable()
            && defined('SAFE_MIGRATE_SHOW_TESTING_UI')
            && SAFE_MIGRATE_SHOW_TESTING_UI;
    }
}

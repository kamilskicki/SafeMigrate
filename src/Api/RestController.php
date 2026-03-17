<?php

declare(strict_types=1);

namespace SafeMigrate\Api;

use SafeMigrate\Contracts\RegistersHooks;
use SafeMigrate\Jobs\Capabilities;
use SafeMigrate\Jobs\JobRepository;
use SafeMigrate\Jobs\JobSummaryFormatter;
use SafeMigrate\Jobs\JobService;
use SafeMigrate\Product\FeaturePolicy;
use SafeMigrate\Product\LicenseStateService;
use SafeMigrate\Product\SettingsService;
use SafeMigrate\Testing\FailureInjectionService;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestController implements RegistersHooks
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobService $jobService,
        private readonly SettingsService $settingsService,
        private readonly LicenseStateService $licenseStateService,
        private readonly FeaturePolicy $featurePolicy,
        private readonly FailureInjectionService $failureInjectionService,
        private readonly ApiErrorClassifier $errorClassifier
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $postManage = ['methods' => 'POST', 'permission_callback' => [$this, 'canManage']];
        $postDestructive = ['methods' => 'POST', 'permission_callback' => [$this, 'canRunDestructive']];

        register_rest_route('safe-migrate/v1', '/preflight', $postManage + ['callback' => [$this, 'runPreflight']]);
        register_rest_route('safe-migrate/v1', '/export-plan', $postManage + ['callback' => [$this, 'buildExportPlan']]);
        register_rest_route('safe-migrate/v1', '/export', $postManage + ['callback' => [$this, 'runExport']]);
        register_rest_route('safe-migrate/v1', '/validate-package', $postManage + ['callback' => [$this, 'validatePackage']]);
        register_rest_route('safe-migrate/v1', '/simulate-restore', $postManage + ['callback' => [$this, 'simulateRestore']]);
        register_rest_route('safe-migrate/v1', '/restore-preview', $postManage + ['callback' => [$this, 'previewRestoreWorkspace']]);
        register_rest_route('safe-migrate/v1', '/cleanup-artifacts', $postManage + ['callback' => [$this, 'cleanupArtifacts']]);
        register_rest_route('safe-migrate/v1', '/restore-execute', $postDestructive + ['callback' => [$this, 'runRestoreExecute']]);
        register_rest_route('safe-migrate/v1', '/restore-rollback', $postDestructive + ['callback' => [$this, 'runRestoreRollback']]);
        register_rest_route('safe-migrate/v1', '/resume-job', $postDestructive + ['callback' => [$this, 'resumeJob']]);
        register_rest_route('safe-migrate/v1', '/support-bundle', $postManage + ['callback' => [$this, 'exportSupportBundleByBody']]);
        register_rest_route('safe-migrate/v1', '/jobs/(?P<id>\d+)/support-bundle', $postManage + ['callback' => [$this, 'exportSupportBundle']]);
        register_rest_route('safe-migrate/v1', '/jobs/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getJob'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route('safe-migrate/v1', '/jobs', [
            'methods' => 'GET',
            'callback' => [$this, 'listJobs'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route('safe-migrate/v1', '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getSettings'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateSettings'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route('safe-migrate/v1', '/license', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getLicense'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateLicense'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route('safe-migrate/v1', '/failure-injection', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getFailureInjection'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateFailureInjection'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'clearFailureInjection'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can(Capabilities::MANAGE);
    }

    public function canRunDestructive(): bool
    {
        return current_user_can(Capabilities::DESTRUCTIVE);
    }

    public function runPreflight(): WP_REST_Response|WP_Error { return $this->handle(fn (): array => $this->jobService->runPreflight(get_current_user_id()), 'safe_migrate_preflight_failed'); }
    public function buildExportPlan(): WP_REST_Response|WP_Error { return $this->handle(fn (): array => $this->jobService->buildExportPlan(get_current_user_id()), 'safe_migrate_export_plan_failed'); }
    public function runExport(): WP_REST_Response|WP_Error { return $this->handle(fn (): array => $this->jobService->runExport(get_current_user_id()), 'safe_migrate_export_failed'); }
    public function cleanupArtifacts(): WP_REST_Response|WP_Error { return $this->handle(fn (): array => $this->jobService->cleanupArtifacts(get_current_user_id()), 'safe_migrate_cleanup_artifacts_failed'); }

    public function getJob(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $job = $this->jobs->find((int) $request['id']);

        if ($job === null) {
            return new WP_Error('safe_migrate_job_not_found', __('Job not found.', 'safe-migrate'), ['status' => 404]);
        }

        $response = ['job' => JobSummaryFormatter::summarizeJob($job)];

        if ((bool) $request->get_param('include_payload')) {
            $response['job_detail'] = $job;
        }

        return new WP_REST_Response($response, 200);
    }

    public function listJobs(WP_REST_Request $request): WP_REST_Response
    {
        $limit = max(1, min(25, (int) $request->get_param('limit')));
        $jobs = array_map(
            static fn (array $job): array => JobSummaryFormatter::summarizeJob($job),
            $this->jobs->latest($limit)
        );

        return new WP_REST_Response(['jobs' => $jobs], 200);
    }

    public function validatePackage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => $this->jobService->validatePackage(get_current_user_id(), (string) $request->get_param('artifact_directory')),
            'safe_migrate_validate_package_failed'
        );
    }

    public function simulateRestore(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => $this->jobService->simulateRestore(get_current_user_id(), (string) $request->get_param('artifact_directory')),
            'safe_migrate_simulate_restore_failed'
        );
    }

    public function previewRestoreWorkspace(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => $this->jobService->previewRestoreWorkspace(get_current_user_id(), (string) $request->get_param('artifact_directory')),
            'safe_migrate_restore_preview_failed'
        );
    }

    public function runRestoreExecute(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => $this->jobService->runRestoreExecute(
                get_current_user_id(),
                (string) $request->get_param('artifact_directory'),
                (bool) $request->get_param('confirm_destructive')
            ),
            'safe_migrate_restore_execute_failed'
        );
    }

    public function runRestoreRollback(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => $this->jobService->runRestoreRollback(get_current_user_id(), (int) $request->get_param('job_id')),
            'safe_migrate_restore_rollback_failed'
        );
    }

    public function resumeJob(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => $this->jobService->resumeJob(get_current_user_id(), (int) $request->get_param('job_id')),
            'safe_migrate_resume_job_failed'
        );
    }

    public function exportSupportBundle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => $this->jobService->exportSupportBundle(get_current_user_id(), (int) $request['id']),
            'safe_migrate_support_bundle_failed'
        );
    }

    public function exportSupportBundleByBody(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->handle(
            fn (): array => $this->jobService->exportSupportBundle(get_current_user_id(), (int) $request->get_param('job_id')),
            'safe_migrate_support_bundle_failed'
        );
    }

    public function getSettings(): WP_REST_Response
    {
        return new WP_REST_Response([
            'settings' => $this->settingsService->get(),
            'feature_policy' => ['is_pro' => $this->featurePolicy->isPro()],
        ], 200);
    }

    public function updateSettings(WP_REST_Request $request): WP_REST_Response
    {
        $payload = is_array($request->get_json_params()) ? $request->get_json_params() : [];

        return new WP_REST_Response([
            'settings' => $this->settingsService->update($payload),
            'feature_policy' => ['is_pro' => $this->featurePolicy->isPro()],
        ], 200);
    }

    public function getLicense(): WP_REST_Response
    {
        return new WP_REST_Response([
            'license' => $this->licenseStateService->get(),
            'feature_policy' => ['is_pro' => $this->featurePolicy->isPro()],
        ], 200);
    }

    public function updateLicense(WP_REST_Request $request): WP_REST_Response
    {
        $payload = is_array($request->get_json_params()) ? $request->get_json_params() : [];

        return new WP_REST_Response([
            'license' => $this->licenseStateService->update($payload),
            'feature_policy' => ['is_pro' => $this->featurePolicy->isPro()],
        ], 200);
    }

    public function getFailureInjection(): WP_REST_Response
    {
        return new WP_REST_Response([
            'available' => $this->failureInjectionService->isAvailable(),
            'failure_injection' => $this->failureInjectionService->current(),
        ], 200);
    }

    public function updateFailureInjection(WP_REST_Request $request): WP_REST_Response
    {
        $payload = is_array($request->get_json_params()) ? $request->get_json_params() : [];

        return new WP_REST_Response([
            'available' => $this->failureInjectionService->isAvailable(),
            'failure_injection' => $this->failureInjectionService->configure($payload),
        ], 200);
    }

    public function clearFailureInjection(): WP_REST_Response
    {
        $this->failureInjectionService->clear();

        return new WP_REST_Response([
            'available' => $this->failureInjectionService->isAvailable(),
            'failure_injection' => $this->failureInjectionService->current(),
        ], 200);
    }

    private function handle(callable $operation, string $defaultCode): WP_REST_Response|WP_Error
    {
        try {
            return new WP_REST_Response($operation(), 200);
        } catch (Throwable $throwable) {
            return $this->errorResponse($defaultCode, $throwable);
        }
    }

    private function errorResponse(string $defaultCode, Throwable $throwable): WP_Error
    {
        $message = $throwable->getMessage();
        $classified = $this->errorClassifier->classify($message);

        return new WP_Error($defaultCode, $message, [
            'status' => $classified['status'],
            'safe_migrate_code' => $classified['safe_migrate_code'],
        ]);
    }
}

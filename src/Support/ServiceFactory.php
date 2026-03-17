<?php

declare(strict_types=1);

namespace SafeMigrate\Support;

use SafeMigrate\Api\ApiErrorClassifier;
use SafeMigrate\Checkpoints\CheckpointRepository;
use SafeMigrate\Compatibility\BuilderDetector;
use SafeMigrate\Compatibility\BuilderRegistry;
use SafeMigrate\Diagnostics\PreflightRunner;
use SafeMigrate\Export\DatabaseSegmentExporter;
use SafeMigrate\Export\ExportPlanBuilder;
use SafeMigrate\Export\FilesystemChunkWriter;
use SafeMigrate\Export\PackageBuilder;
use SafeMigrate\Import\PackageLoader;
use SafeMigrate\Import\PackageValidator;
use SafeMigrate\Import\RestoreService;
use SafeMigrate\Import\RestoreVerifier;
use SafeMigrate\Import\RestoreWorkspaceManager;
use SafeMigrate\Jobs\JobRepository;
use SafeMigrate\Jobs\JobService;
use SafeMigrate\Logging\Logger;
use SafeMigrate\Maintenance\ArtifactCleanupService;
use SafeMigrate\Product\FeaturePolicy;
use SafeMigrate\Product\LicenseStateService;
use SafeMigrate\Remote\CredentialCodec;
use SafeMigrate\Remote\RemoteMigrationService;
use SafeMigrate\Remote\TransferSessionService;
use SafeMigrate\Product\SettingsService;
use SafeMigrate\Remap\RemapEngine;
use SafeMigrate\Testing\FailureInjectionService;
use wpdb;

final class ServiceFactory
{
    private ?BuilderDetector $builderDetector = null;
    private ?BuilderRegistry $builderRegistry = null;
    private ?JobRepository $jobRepository = null;
    private ?CheckpointRepository $checkpointRepository = null;
    private ?Logger $logger = null;
    private ?PreflightRunner $preflightRunner = null;
    private ?ExportPlanBuilder $exportPlanBuilder = null;
    private ?PackageBuilder $packageBuilder = null;
    private ?PackageLoader $packageLoader = null;
    private ?PackageValidator $packageValidator = null;
    private ?RestoreService $restoreService = null;
    private ?RestoreVerifier $restoreVerifier = null;
    private ?RestoreWorkspaceManager $restoreWorkspaceManager = null;
    private ?RemapEngine $remapEngine = null;
    private ?ArtifactCleanupService $artifactCleanupService = null;
    private ?SupportBundleService $supportBundleService = null;
    private ?LicenseStateService $licenseStateService = null;
    private ?SettingsService $settingsService = null;
    private ?FeaturePolicy $featurePolicy = null;
    private ?FailureInjectionService $failureInjectionService = null;
    private ?ApiErrorClassifier $apiErrorClassifier = null;
    private ?CredentialCodec $credentialCodec = null;
    private ?TransferSessionService $transferSessionService = null;
    private ?RemoteMigrationService $remoteMigrationService = null;
    private ?JobService $jobService = null;

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function jobRepository(): JobRepository
    {
        return $this->jobRepository ??= new JobRepository($this->wpdb);
    }

    public function checkpointRepository(): CheckpointRepository
    {
        return $this->checkpointRepository ??= new CheckpointRepository($this->wpdb);
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->wpdb);
    }

    public function settingsService(): SettingsService
    {
        return $this->settingsService ??= new SettingsService($this->featurePolicy());
    }

    public function licenseStateService(): LicenseStateService
    {
        return $this->licenseStateService ??= new LicenseStateService();
    }

    public function featurePolicy(): FeaturePolicy
    {
        return $this->featurePolicy ??= new FeaturePolicy($this->licenseStateService());
    }

    public function failureInjectionService(): FailureInjectionService
    {
        return $this->failureInjectionService ??= new FailureInjectionService();
    }

    public function apiErrorClassifier(): ApiErrorClassifier
    {
        return $this->apiErrorClassifier ??= new ApiErrorClassifier();
    }

    public function credentialCodec(): CredentialCodec
    {
        return $this->credentialCodec ??= new CredentialCodec((string) wp_salt('safe-migrate-transfer'));
    }

    public function transferSessionService(): TransferSessionService
    {
        return $this->transferSessionService ??= new TransferSessionService($this->credentialCodec());
    }

    public function builderRegistry(): BuilderRegistry
    {
        return $this->builderRegistry ??= new BuilderRegistry();
    }

    public function builderDetector(): BuilderDetector
    {
        return $this->builderDetector ??= new BuilderDetector($this->builderRegistry());
    }

    public function preflightRunner(): PreflightRunner
    {
        return $this->preflightRunner ??= new PreflightRunner($this->builderDetector(), $this->builderRegistry());
    }

    public function exportPlanBuilder(): ExportPlanBuilder
    {
        return $this->exportPlanBuilder ??= new ExportPlanBuilder(
            $this->wpdb,
            $this->builderDetector(),
            $this->settingsService(),
            $this->featurePolicy()
        );
    }

    public function packageBuilder(): PackageBuilder
    {
        return $this->packageBuilder ??= new PackageBuilder(
            $this->exportPlanBuilder(),
            new DatabaseSegmentExporter($this->wpdb),
            new FilesystemChunkWriter()
        );
    }

    public function packageLoader(): PackageLoader
    {
        return $this->packageLoader ??= new PackageLoader();
    }

    public function packageValidator(): PackageValidator
    {
        return $this->packageValidator ??= new PackageValidator();
    }

    public function remapEngine(): RemapEngine
    {
        return $this->remapEngine ??= new RemapEngine();
    }

    public function restoreService(): RestoreService
    {
        return $this->restoreService ??= new RestoreService($this->remapEngine());
    }

    public function restoreVerifier(): RestoreVerifier
    {
        return $this->restoreVerifier ??= new RestoreVerifier($this->packageValidator(), $this->builderRegistry());
    }

    public function restoreWorkspaceManager(): RestoreWorkspaceManager
    {
        return $this->restoreWorkspaceManager ??= new RestoreWorkspaceManager();
    }

    public function artifactCleanupService(): ArtifactCleanupService
    {
        return $this->artifactCleanupService ??= new ArtifactCleanupService(
            $this->settingsService(),
            $this->featurePolicy()
        );
    }

    public function supportBundleService(): SupportBundleService
    {
        return $this->supportBundleService ??= new SupportBundleService(
            $this->jobRepository(),
            $this->checkpointRepository(),
            $this->logger(),
            $this->settingsService(),
            $this->featurePolicy()
        );
    }

    public function remoteMigrationService(): RemoteMigrationService
    {
        return $this->remoteMigrationService ??= new RemoteMigrationService(
            $this->jobRepository(),
            $this->logger(),
            $this->packageLoader(),
            $this->packageValidator()
        );
    }

    public function jobService(): JobService
    {
        return $this->jobService ??= new JobService(
            $this->jobRepository(),
            $this->checkpointRepository(),
            $this->logger(),
            $this->preflightRunner(),
            $this->exportPlanBuilder(),
            $this->packageBuilder(),
            $this->packageLoader(),
            $this->packageValidator(),
            $this->restoreService(),
            $this->restoreVerifier(),
            $this->restoreWorkspaceManager(),
            $this->remapEngine(),
            $this->artifactCleanupService(),
            $this->supportBundleService(),
            $this->builderRegistry(),
            $this->settingsService(),
            $this->featurePolicy(),
            $this->failureInjectionService(),
            $this->transferSessionService(),
            $this->remoteMigrationService()
        );
    }
}

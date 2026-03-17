<?php

declare(strict_types=1);

namespace SafeMigrate;

use SafeMigrate\Admin\AdminPage;
use SafeMigrate\Api\ApiErrorClassifier;
use SafeMigrate\Api\RestController;
use SafeMigrate\Cli\CliRegistrar;
use SafeMigrate\Contracts\RegistersHooks;
use SafeMigrate\Infrastructure\Database\Schema;
use SafeMigrate\Jobs\Capabilities;
use SafeMigrate\Remote\TransferRestController;
use SafeMigrate\Support\PluginCleanup;
use SafeMigrate\Support\ServiceFactory;

final class Plugin
{
    private static ?self $instance = null;

    /**
     * @var array<RegistersHooks>
     */
    private array $components = [];

    private bool $booted = false;

    private Schema $schema;

    private Capabilities $capabilities;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;

        $this->schema = new Schema($wpdb);
        $this->capabilities = new Capabilities();
        $services = new ServiceFactory($wpdb);
        $jobRepository = $services->jobRepository();
        $jobService = $services->jobService();

        $this->components = [
            $this->capabilities,
            new AdminPage(
                $jobRepository,
                $services->settingsService(),
                $services->licenseStateService(),
                $services->featurePolicy(),
                $services->failureInjectionService()
            ),
            new RestController(
                $jobRepository,
                $jobService,
                $services->settingsService(),
                $services->licenseStateService(),
                $services->featurePolicy(),
                $services->failureInjectionService(),
                $services->apiErrorClassifier()
            ),
            new TransferRestController(
                $services->transferSessionService(),
                $services->remoteMigrationService(),
                $services->preflightRunner(),
                $jobRepository,
                $jobService,
                $services->apiErrorClassifier()
            ),
            new CliRegistrar($jobService, $jobRepository, $services->failureInjectionService()),
        ];
    }

    public function activate(): void
    {
        $this->schema->install();
        $this->capabilities->grant();
    }

    public function deactivate(): void
    {
        $this->capabilities->revoke();
        PluginCleanup::deactivate();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->components as $component) {
            $component->register();
        }

        $this->booted = true;
    }
}

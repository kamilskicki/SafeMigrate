<?php

declare(strict_types=1);

namespace SafeMigrate\Cli;

use SafeMigrate\Contracts\RegistersHooks;
use SafeMigrate\Jobs\JobRepository;
use SafeMigrate\Jobs\JobService;
use SafeMigrate\Testing\FailureInjectionService;

final class CliRegistrar implements RegistersHooks
{
    public function __construct(
        private readonly JobService $jobService,
        private readonly JobRepository $jobs,
        private readonly FailureInjectionService $failureInjectionService
    ) {
    }

    public function register(): void
    {
        if (! defined('WP_CLI') || ! \WP_CLI) {
            return;
        }

        \WP_CLI::add_command('safe-migrate', new SafeMigrateCommand($this->jobService, $this->jobs, $this->failureInjectionService));
    }
}

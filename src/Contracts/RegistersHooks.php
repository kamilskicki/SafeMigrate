<?php

declare(strict_types=1);

namespace SafeMigrate\Contracts;

interface RegistersHooks
{
    public function register(): void;
}

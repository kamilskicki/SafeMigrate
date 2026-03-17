<?php

declare(strict_types=1);

namespace SafeMigrate\Compatibility;

abstract class AbstractBuilderAdapter implements BuilderAdapter
{
    public function supportLevel(): string
    {
        return 'core';
    }

    public function warnings(array $builder): array
    {
        return [];
    }

    public function normalizeRules(array $manifest, array $rules): array
    {
        return $rules;
    }

    public function verify(array $manifest, array $restoreSummary): array
    {
        return [];
    }
}

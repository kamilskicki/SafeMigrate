<?php

declare(strict_types=1);

namespace SafeMigrate\Compatibility;

interface BuilderAdapter
{
    public function slug(): string;

    public function name(): string;

    public function family(): string;

    public function supportLevel(): string;

    /**
     * @return array<int, string>
     */
    public function matchPatterns(): array;

    /**
     * @param array<string, mixed> $builder
     * @return array<int, string>
     */
    public function warnings(array $builder): array;

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    public function normalizeRules(array $manifest, array $rules): array;

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $restoreSummary
     * @return array<int, string>
     */
    public function verify(array $manifest, array $restoreSummary): array;
}

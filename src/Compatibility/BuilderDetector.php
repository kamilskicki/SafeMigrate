<?php

declare(strict_types=1);

namespace SafeMigrate\Compatibility;

final class BuilderDetector
{
    public function __construct(private readonly ?BuilderRegistry $registry = null)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function detect(): array
    {
        return ($this->registry ?? new BuilderRegistry())->detect();
    }
}

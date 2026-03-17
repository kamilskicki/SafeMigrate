<?php

declare(strict_types=1);

namespace SafeMigrate\Product;

final class FeaturePolicy
{
    public const ADVANCED_RETENTION = 'advanced_retention';
    public const EXCLUDE_INCLUDE_RULES = 'exclude_include_rules';
    public const SAVED_PROFILES = 'saved_profiles';
    public const SUPPORT_BUNDLE_REDACTION = 'support_bundle_redaction';
    public const ADVANCED_COMPATIBILITY_REPORTING = 'advanced_compatibility_reporting';

    public function __construct(private readonly LicenseStateService $licenseState)
    {
    }

    public function isPro(): bool
    {
        return $this->licenseState->isProActive();
    }

    public function allows(string $feature): bool
    {
        return match ($feature) {
            self::ADVANCED_RETENTION,
            self::EXCLUDE_INCLUDE_RULES,
            self::SAVED_PROFILES,
            self::SUPPORT_BUNDLE_REDACTION,
            self::ADVANCED_COMPATIBILITY_REPORTING => $this->isPro(),
            default => false,
        };
    }
}

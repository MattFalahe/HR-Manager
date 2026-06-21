<?php

namespace HrManager\Support;

/**
 * Activity tier hierarchy. Higher number = higher rank = stricter activity
 * expectations. Mapping is documented per the v1.2.0 roadmap conversation:
 *
 *   L-1  Applicant       - probationary, no activity threshold
 *   L0   Member          - regular member, generous threshold
 *   L1   Junior Officer  - role-holder, moderate threshold
 *   L2   Senior Officer  - senior leadership, tight threshold
 *   L3   Director        - corp survival depends on activity, tightest threshold
 *
 * Settings (hr_manager_settings) keys hold per-tier default threshold days.
 * Per-mapping overrides live on hr_manager_role_tier_mappings.threshold_days.
 *
 * When a player has multiple roles mapped to different tiers, **highest tier
 * wins** (TierService::resolveForUser implements this).
 */
final class TierLevel
{
    public const APPLICANT       = -1;
    public const MEMBER          = 0;
    public const JUNIOR_OFFICER  = 1;
    public const SENIOR_OFFICER  = 2;
    public const DIRECTOR        = 3;

    /**
     * Ordered low -> high. Index also drives sort order in pickers.
     */
    public const ALL = [
        self::APPLICANT,
        self::MEMBER,
        self::JUNIOR_OFFICER,
        self::SENIOR_OFFICER,
        self::DIRECTOR,
    ];

    /**
     * Stable English slugs used in settings keys + config keys. Never
     * translated — these are storage identifiers, not display labels.
     */
    public const SLUGS = [
        self::APPLICANT       => 'applicant',
        self::MEMBER          => 'member',
        self::JUNIOR_OFFICER  => 'junior_officer',
        self::SENIOR_OFFICER  => 'senior_officer',
        self::DIRECTOR        => 'director',
    ];

    /**
     * Defaults (in days). Director threshold is the tightest per Matt's
     * "corp will not survive without active directors" framing. Applicant
     * deliberately has NO threshold — recruits aren't expected to be active
     * during the application window.
     */
    public const DEFAULT_THRESHOLD_DAYS = [
        self::APPLICANT       => null, // no threshold
        self::MEMBER          => 90,
        self::JUNIOR_OFFICER  => 30,
        self::SENIOR_OFFICER  => 14,
        self::DIRECTOR        => 14,
    ];

    /**
     * Visual hint per tier — drives the badge color on the player profile and
     * the tier selector. Same suite-wide color language: indigo for officers,
     * red for director (criticality), neutral for member, grey for applicant.
     */
    public const BADGE_CLASS = [
        self::APPLICANT       => 'badge-applicant',
        self::MEMBER          => 'badge-member',
        self::JUNIOR_OFFICER  => 'badge-junior-officer',
        self::SENIOR_OFFICER  => 'badge-senior-officer',
        self::DIRECTOR        => 'badge-director',
    ];

    public static function isValid(int $level): bool
    {
        return in_array($level, self::ALL, true);
    }

    public static function slug(int $level): ?string
    {
        return self::SLUGS[$level] ?? null;
    }

    /**
     * Display label resolved via lang file. Falls back to slug if no lang
     * key exists yet.
     */
    public static function label(int $level): string
    {
        $slug = self::slug($level);
        if (!$slug) {
            return "L{$level}";
        }
        $key = "hr-manager::tiers.{$slug}";
        $translated = trans($key);
        return $translated === $key ? ucwords(str_replace('_', ' ', $slug)) : $translated;
    }

    public static function shortLabel(int $level): string
    {
        return "L{$level}";
    }

    public static function badgeClass(int $level): string
    {
        return self::BADGE_CLASS[$level] ?? 'badge-secondary';
    }

    /**
     * Settings key (in hr_manager_settings) for this tier's default threshold.
     * Format: tier_threshold_<slug> (e.g. tier_threshold_director).
     */
    public static function thresholdSettingKey(int $level): ?string
    {
        $slug = self::slug($level);
        return $slug ? "tier_threshold_{$slug}" : null;
    }

    /**
     * The default threshold (days) for a tier. Applicants get null = no threshold.
     */
    public static function defaultThresholdDays(int $level): ?int
    {
        return self::DEFAULT_THRESHOLD_DAYS[$level] ?? null;
    }
}

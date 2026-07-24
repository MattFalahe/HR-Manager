<?php

namespace HrManager\Services;

use HrManager\Models\RoleTierMapping;
use HrManager\Models\Setting;
use HrManager\Support\TierLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a SeAT user's effective activity tier given their Discord roles
 * (looked up via the SeAT Connector framework when installed) crossed with
 * the operator-configured tier mappings.
 *
 * Two independent rules apply when a user is mapped to multiple roles:
 *   - LEVEL: the HIGHEST tier wins (a Director who is also a Member is a
 *     Director). This drives the tier badge + the inactive-director alert.
 *   - THRESHOLD: the STRICTEST inactivity window wins — the fewest days across
 *     every role the user holds, not just the top tier's. So a Director (14d)
 *     who also holds an IT role capped at 7d is held to 7d. This mirrors how
 *     real access works: the tightest expectation any of your roles imposes is
 *     the one you're accountable to. Matches Matt's 2026-05-25 / 2026-07-11
 *     design conversations.
 *
 * Degrades gracefully:
 *   - No SeAT Connector framework installed -> returns null (caller shows
 *     "tier not resolved; install warlof/seat-connector for auto-tiering")
 *   - User has no mapped roles -> returns null (caller shows "no tier set")
 */
class TierService
{
    /**
     * @return array{level:int, mapping:RoleTierMapping, threshold_days:?int, threshold_mapping:RoleTierMapping, all_mappings:\Illuminate\Support\Collection}|null
     */
    public function resolveTier(int $userId, ?int $corporationId): ?array
    {
        $discordRoleIds = $this->discordRoleIdsForUser($userId);
        if (empty($discordRoleIds)) {
            return null;
        }

        $mappings = RoleTierMapping::forCorporation($corporationId)
            ->whereIn('discord_role_id', $discordRoleIds)
            ->get();

        if ($mappings->isEmpty()) {
            return null;
        }

        // LEVEL — highest tier wins. Within the same tier, prefer corp-specific
        // over global (corporation_id NOT NULL beats NULL) as the representative
        // mapping for the badge.
        $top = $mappings
            ->sortBy([
                ['tier_level', 'desc'],
                ['corporation_id', 'desc'],
            ])
            ->first();

        // THRESHOLD — strictest (fewest days) across ALL mappings. Each
        // mapping's effective threshold is its per-mapping override, else its
        // tier's default. A mapping with no threshold (e.g. Applicant, or a
        // tier whose default is blank) imposes no restriction and is skipped,
        // so it never relaxes a stricter sibling.
        $effective = $mappings->map(function ($m) {
            $days = $m->threshold_days ?? $this->defaultThresholdDays($m->tier_level);
            return ($days !== null && $days > 0)
                ? ['days' => (int) $days, 'mapping' => $m]
                : null;
        })->filter()->values();

        $threshold = null;
        $thresholdMapping = $top;
        if ($effective->isNotEmpty()) {
            // Fewest days wins; on a tie, the higher tier is the binding one.
            $strictest = $effective->sort(fn ($a, $b) =>
                ($a['days'] <=> $b['days'])
                ?: ((int) $b['mapping']->tier_level <=> (int) $a['mapping']->tier_level)
            )->first();
            $threshold = $strictest['days'];
            $thresholdMapping = $strictest['mapping'];
        }

        return [
            'level'             => (int) $top->tier_level,
            'mapping'           => $top,
            'threshold_days'    => $threshold,
            'threshold_mapping' => $thresholdMapping,
            'all_mappings'      => $mappings,
        ];
    }

    /**
     * Per-tier default threshold (days). Pulled from hr_manager_settings;
     * falls back to TierLevel constants.
     */
    public function defaultThresholdDays(int $tierLevel): ?int
    {
        $key = TierLevel::thresholdSettingKey($tierLevel);
        if (!$key) {
            return null;
        }

        $stored = Setting::getValue($key, null);
        if ($stored !== null && $stored !== '') {
            return (int) $stored;
        }
        return TierLevel::defaultThresholdDays($tierLevel);
    }

    /**
     * Whether tier auto-resolution is currently possible on this install.
     * False when no Discord-role-assignment provider is detected (SeAT
     * Connector framework today; could grow to support seat-discord-pings
     * etc. as those providers expose user-role assignments).
     */
    public function autoResolutionAvailable(): bool
    {
        return Schema::hasTable('seat_connector_users')
            && Schema::hasTable('seat_connector_sets');
    }

    /**
     * Snowflake IDs of every Discord role the given user holds, via the
     * warlof/seat-connector framework when present. Returns an empty array
     * when no provider is available or the user has zero Discord roles
     * assigned.
     */
    private function discordRoleIdsForUser(int $userId): array
    {
        if (!$this->autoResolutionAvailable()) {
            return [];
        }

        // Reuse the connector resolver — it unions the polymorphic set_entity
        // pivot (user / corp / alliance / role / squad / public) and is cached
        // per user. (A user's Discord roles are NOT a set_id on the users row.)
        $identity = app(SeatConnectorService::class)->getIdentityForUser($userId);

        return collect($identity['roles'] ?? [])
            ->pluck('role_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}

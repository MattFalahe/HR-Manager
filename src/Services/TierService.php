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
 * "Highest tier wins" when a user is mapped to multiple tiers: a Director
 * who is also a Member gets tier=Director, not Member. Matches Matt's
 * 2026-05-25 design conversation.
 *
 * Degrades gracefully:
 *   - No SeAT Connector framework installed -> returns null (caller shows
 *     "tier not resolved; install warlof/seat-connector for auto-tiering")
 *   - User has no mapped roles -> returns null (caller shows "no tier set")
 */
class TierService
{
    /**
     * @return array{level:int, mapping:RoleTierMapping, threshold_days:?int, all_mappings:\Illuminate\Support\Collection}|null
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

        // Highest tier wins. Within the same tier, prefer corp-specific over
        // global (corporation_id NOT NULL beats NULL) so per-corp overrides
        // win their per-mapping threshold setting.
        $top = $mappings
            ->sortBy([
                ['tier_level', 'desc'],
                ['corporation_id', 'desc'],
            ])
            ->first();

        $threshold = $top->threshold_days ?? $this->defaultThresholdDays($top->tier_level);

        return [
            'level'          => (int) $top->tier_level,
            'mapping'        => $top,
            'threshold_days' => $threshold,
            'all_mappings'   => $mappings,
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

        try {
            return DB::table('seat_connector_users')
                ->join('seat_connector_sets', 'seat_connector_sets.id', '=', 'seat_connector_users.set_id')
                ->where('seat_connector_users.user_id', $userId)
                ->where('seat_connector_sets.connector_type', 'discord')
                ->pluck('seat_connector_sets.connector_id')
                ->map(fn($id) => (string) $id)
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            // Schema mismatch or missing column - degrade silently so the
            // player view still renders. Log loud so operators see it.
            \Illuminate\Support\Facades\Log::warning(
                '[HR Manager] TierService: failed querying seat_connector_users for user ' . $userId . ': ' . $e->getMessage()
            );
            return [];
        }
    }
}

<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal zKillboard adapter for the member profile's "Recent PvP" panel.
 *
 * Direct HTTP fetch with a 1-hour cache. ZKill politely asks for a
 * User-Agent identifying the consumer; we set one. Network failures and
 * non-200 responses return `['available' => false]` so the view falls
 * back to a muted message rather than throwing.
 *
 * Future: if Manager Core ever exposes a shared zKill service via
 * PluginBridge (per memory `project_seat_upstream_fixes` discussion), this
 * service can route through there and centralise rate-limiting across the
 * whole suite. For now it's a direct adapter — single-plugin scope only.
 */
class ZkillService
{
    private const CACHE_TTL_SECONDS = 3600; // 1 hour
    private const FETCH_TIMEOUT     = 3;    // seconds — keep page render snappy
    private const USER_AGENT        = 'SeAT HR Manager Plugin (https://github.com/MattFalahe/hr-manager)';

    /**
     * Aggregate PvP stats for a character. Cached.
     *
     * @return array{
     *   available: bool,
     *   ships_destroyed?: int,
     *   ships_lost?: int,
     *   isk_destroyed?: float,
     *   isk_lost?: float,
     *   solo_kills?: int,
     *   solo_losses?: int,
     *   danger_ratio?: int,
     *   gang_ratio?: int,
     *   recent_active?: bool,
     *   reason?: string,
     * }
     */
    public function getCharacterStats(int $characterId): array
    {
        $cacheKey = "hr-zkill-stats-{$characterId}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($characterId) {
            return $this->fetchStats($characterId);
        });
    }

    /**
     * Force a refresh of cached stats (used by the member profile's
     * Refresh Data button when wired). Bypasses cache and rewrites.
     */
    public function refreshCharacterStats(int $characterId): array
    {
        $cacheKey = "hr-zkill-stats-{$characterId}";
        Cache::forget($cacheKey);
        $stats = $this->fetchStats($characterId);
        Cache::put($cacheKey, $stats, self::CACHE_TTL_SECONDS);
        return $stats;
    }

    /**
     * Cache PEEK — returns the cached stats if warm, null otherwise.
     * Never triggers a network fetch. Used by the role classifier so a
     * player profile rendering N alts can't fire N cold zKill requests:
     * PvP data is only consulted when it's already warm (warmed by a
     * member-profile visit, which fetches it for the PvP card). Polite
     * by construction.
     */
    public function getCachedStats(int $characterId): ?array
    {
        $cached = Cache::get("hr-zkill-stats-{$characterId}");
        return is_array($cached) ? $cached : null;
    }

    /**
     * Best-effort fetch. Times out fast, swallows errors, returns muted
     * envelope on failure.
     */
    private function fetchStats(int $characterId): array
    {
        if ($characterId <= 0) {
            return ['available' => false, 'reason' => 'invalid_character_id'];
        }

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::FETCH_TIMEOUT)
                ->get("https://zkillboard.com/api/stats/characterID/{$characterId}/");

            if (!$response->successful()) {
                return [
                    'available' => false,
                    'reason'    => 'http_' . $response->status(),
                ];
            }

            $data = $response->json();
            if (!is_array($data) || empty($data)) {
                // zKill returns an empty array for characters with no PvP record
                return ['available' => true, 'ships_destroyed' => 0, 'ships_lost' => 0, 'recent_active' => false];
            }

            return [
                'available'        => true,
                'ships_destroyed'  => (int)   ($data['shipsDestroyed'] ?? 0),
                'ships_lost'       => (int)   ($data['shipsLost']      ?? 0),
                'isk_destroyed'    => (float) ($data['iskDestroyed']   ?? 0),
                'isk_lost'         => (float) ($data['iskLost']        ?? 0),
                'solo_kills'       => (int)   ($data['soloKills']      ?? 0),
                'solo_losses'      => (int)   ($data['soloLosses']     ?? 0),
                'danger_ratio'    => isset($data['dangerRatio']) ? (int) $data['dangerRatio'] : null,
                'gang_ratio'      => isset($data['gangRatio'])   ? (int) $data['gangRatio']   : null,
                'recent_active'   => !empty($data['hasSupers']) || ($data['shipsDestroyed'] ?? 0) > 0
                                    || ($data['shipsLost'] ?? 0) > 0,
                'fetched_at'       => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] zKill fetch failed for character ' . $characterId . ': ' . $e->getMessage());
            return [
                'available' => false,
                'reason'    => 'fetch_failed',
            ];
        }
    }
}

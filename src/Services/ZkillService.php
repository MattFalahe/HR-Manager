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
 * PluginBridge, this service can route through there and centralise
 * rate-limiting across the whole suite. For now it's a direct adapter,
 * single-plugin scope only.
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
                return ['available' => true, 'ships_destroyed' => 0, 'ships_lost' => 0, 'recent_active' => false, 'windows' => $this->windowsFromMonths([])];
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
                // Rolling 1 / 3 / 6 / 12-month PvP windows summed from zKill's own
                // per-month breakdown (no extra API calls — same response).
                'windows'          => $this->windowsFromMonths($data['months'] ?? []),
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

    /**
     * Sum zKill's per-month breakdown into rolling 1 / 3 / 6 / 12-month windows.
     * months keys are 'YYYYMM'; a fixed-width numeric compare handles year
     * boundaries. Each window includes the current calendar month plus the N-1
     * before it.
     *
     * @param array<string,array> $months
     * @return array<string,array{kills:int,losses:int,isk_destroyed:float,isk_lost:float}>
     */
    private function windowsFromMonths(array $months): array
    {
        $now = now();
        $spans = ['1m' => 1, '3m' => 3, '6m' => 6, '12m' => 12];
        $out = [];

        foreach ($spans as $key => $n) {
            $cutoff = (int) $now->copy()->subMonths($n - 1)->format('Ym');
            $kills = 0; $losses = 0; $iskD = 0.0; $iskL = 0.0;
            foreach ($months as $ym => $m) {
                if (!is_array($m) || (int) $ym < $cutoff) {
                    continue;
                }
                $kills  += (int)   ($m['shipsDestroyed'] ?? 0);
                $losses += (int)   ($m['shipsLost']      ?? 0);
                $iskD   += (float) ($m['iskDestroyed']   ?? 0);
                $iskL   += (float) ($m['iskLost']        ?? 0);
            }
            $out[$key] = ['kills' => $kills, 'losses' => $losses, 'isk_destroyed' => $iskD, 'isk_lost' => $iskL];
        }

        return $out;
    }

    /**
     * PvP breakdown for a whole applying account — windowed stats per character,
     * with the most-active PvP character flagged. Fires one (cached) fetch per
     * character, so this is meant to be called ON DEMAND (the application panel
     * lazy-loads it), never on every page render.
     *
     * @param array<int,string> $characters  characterId => name
     * @return array{characters:array<int,array>, most_active_id:?int, any_data:bool}
     */
    public function getAccountPvpBreakdown(array $characters, bool $cachedOnly = false): array
    {
        $rows = [];
        $mostActiveId = null;
        $mostActiveScore = -1;
        $anyData = false;

        foreach ($characters as $cid => $name) {
            $cid = (int) $cid;
            if ($cid <= 0) {
                continue;
            }

            // $cachedOnly (player view): peek the cache only — never cold-fetch
            // per alt, which for a 30-alt account would be 30 rate-limited zKill
            // requests on a page load. Characters warm as their own view is
            // opened; here we just show what's already warm.
            $stats = $cachedOnly
                ? ($this->getCachedStats($cid) ?: ['available' => false])
                : $this->getCharacterStats($cid);
            $available = !empty($stats['available']);
            $windows   = $stats['windows'] ?? [];
            $allKills  = (int) ($stats['ships_destroyed'] ?? 0);
            $allLosses = (int) ($stats['ships_lost'] ?? 0);
            $hasPvp    = $available && ($allKills > 0 || $allLosses > 0);
            if ($hasPvp) {
                $anyData = true;
            }

            // Most active = highest 12-month kill count, all-time kills as tiebreak.
            $score = ((int) ($windows['12m']['kills'] ?? 0)) * 1000000 + $allKills;
            if ($hasPvp && $score > $mostActiveScore) {
                $mostActiveScore = $score;
                $mostActiveId = $cid;
            }

            $rows[] = [
                'character_id'    => $cid,
                'name'            => $name,
                'available'       => $available,
                'has_pvp'         => $hasPvp,
                'windows'         => $windows,
                'all_time_kills'  => $allKills,
                'all_time_losses' => $allLosses,
                'danger_ratio'    => $stats['danger_ratio'] ?? null,
            ];
        }

        usort($rows, function ($a, $b) {
            return (($b['windows']['12m']['kills'] ?? 0) <=> ($a['windows']['12m']['kills'] ?? 0))
                ?: (($b['all_time_kills']) <=> ($a['all_time_kills']));
        });

        return ['characters' => $rows, 'most_active_id' => $mostActiveId, 'any_data' => $anyData];
    }
}

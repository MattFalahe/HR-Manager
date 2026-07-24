<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Raw activity totals behind the role badges — the "extended details of
 * classification". The badges say WHAT a character does; this gives the number.
 *
 * Batched: one query per metric across ALL the passed characters, so it stays
 * fast for a many-alt account (pass a single id for the per-character view).
 * Everything except ore value / PvP / FC is a plain SeAT-core query, so it
 * works standalone; those three self-hide when their source (Mining Manager /
 * a warm zKill cache / SeAT Broadcast) is absent. Pass $userId to include the
 * account-level FC broadcast count (player view); omit it on the per-character
 * view, where FC isn't a character-level signal.
 *
 * Window follows HR_ACTIVITY_WINDOW_MONTHS (default 6), same as the classifier.
 */
class PlayerActivityService
{
    /**
     * @param  array<int>  $characterIds
     * @return array{bounty_isk:float,mission_isk:float,ore_value_isk:float,trade_volume_isk:float,trade_txns:int,industry_jobs:int,pi_colonies:int,pvp_kills:int,fc_broadcasts:int,months:int,has_data:bool}
     */
    public function forCharacters(array $characterIds, int $corporationId, ?int $months = null, ?int $userId = null): array
    {
        $characterIds = array_values(array_unique(array_filter(array_map('intval', $characterIds))));
        $months = $months ?? (int) config('hr-manager.performance.activity_window_months', 6);
        $since  = now()->subMonths($months);

        $m = [
            'bounty_isk' => 0.0, 'mission_isk' => 0.0, 'ore_value_isk' => 0.0,
            'trade_volume_isk' => 0.0, 'trade_txns' => 0,
            'industry_jobs' => 0, 'pi_colonies' => 0, 'pvp_kills' => 0, 'fc_broadcasts' => 0,
            'months' => $months, 'has_data' => false,
        ];
        if (empty($characterIds)) {
            return $m;
        }

        // Bounties + agent missions (SeAT core wallet journals).
        try {
            if (Schema::hasTable('character_wallet_journals')) {
                $r = DB::table('character_wallet_journals')
                    ->whereIn('character_id', $characterIds)
                    ->where('date', '>=', $since)
                    ->where('amount', '>', 0)
                    ->selectRaw("
                        SUM(CASE WHEN ref_type LIKE 'bounty%' THEN amount ELSE 0 END) AS bounty,
                        SUM(CASE WHEN ref_type IN ('agent_mission_reward', 'agent_mission_time_bonus_reward') THEN amount ELSE 0 END) AS mission
                    ")
                    ->first();
                $m['bounty_isk']  = (float) ($r->bounty ?? 0);
                $m['mission_isk'] = (float) ($r->mission ?? 0);
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] activity bounty/mission failed: ' . $e->getMessage());
        }

        // Market trading (SeAT core wallet transactions).
        try {
            if (Schema::hasTable('character_wallet_transactions')) {
                $r = DB::table('character_wallet_transactions')
                    ->whereIn('character_id', $characterIds)
                    ->where('date', '>=', $since)
                    ->selectRaw('COUNT(*) AS cnt, SUM(unit_price * quantity) AS vol')
                    ->first();
                $m['trade_txns']       = (int) ($r->cnt ?? 0);
                $m['trade_volume_isk'] = (float) ($r->vol ?? 0);
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] activity trade failed: ' . $e->getMessage());
        }

        // Industry jobs (SeAT core) — count of jobs on record.
        try {
            if (Schema::hasTable('character_industry_jobs')) {
                $m['industry_jobs'] = (int) DB::table('character_industry_jobs')
                    ->whereIn('character_id', $characterIds)
                    ->count();
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] activity industry failed: ' . $e->getMessage());
        }

        // Planetary colonies (SeAT core).
        try {
            if (Schema::hasTable('character_planets')) {
                $m['pi_colonies'] = (int) DB::table('character_planets')
                    ->whereIn('character_id', $characterIds)
                    ->count();
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] activity PI failed: ' . $e->getMessage());
        }

        // Ore value — Mining Manager's own monthly valuation (self-hides w/o MM).
        try {
            $summaryClass = 'MiningManager\\Models\\MiningLedgerMonthlySummary';
            if (class_exists($summaryClass)) {
                $m['ore_value_isk'] = (float) $summaryClass::whereIn('character_id', $characterIds)
                    ->where('month', '>=', now()->subMonths($months)->startOfMonth())
                    ->sum('total_value');
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] activity mining failed: ' . $e->getMessage());
        }

        // PvP kills — zKill cache PEEK only (never a cold fetch from here).
        try {
            $zkill = app(ZkillService::class);
            foreach ($characterIds as $cid) {
                $stats = $zkill->getCachedStats($cid);
                if (is_array($stats) && ($stats['available'] ?? false)) {
                    $m['pvp_kills'] += (int) ($stats['ships_destroyed'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] activity pvp failed: ' . $e->getMessage());
        }

        // FC broadcasts — account-level, only when a user id is given (player view).
        if ($userId !== null && $userId > 0) {
            try {
                $fc = app(FcActivityService::class)->getForUser($userId);
                $m['fc_broadcasts'] = (int) ($fc['total'] ?? 0);
            } catch (\Throwable $e) {
                Log::info('[HR Manager] activity fc failed: ' . $e->getMessage());
            }
        }

        $m['has_data'] = ($m['bounty_isk'] > 0 || $m['mission_isk'] > 0 || $m['ore_value_isk'] > 0
            || $m['trade_volume_isk'] > 0 || $m['industry_jobs'] > 0 || $m['pi_colonies'] > 0
            || $m['pvp_kills'] > 0 || $m['fc_broadcasts'] > 0);

        return $m;
    }
}

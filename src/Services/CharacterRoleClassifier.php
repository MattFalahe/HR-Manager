<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Infers what a character is USED FOR from observed activity signals.
 *
 * Design principle: these are OBSERVED activities, not guesses. Having
 * an active PI colony means the character does PI. Receiving bounty
 * income means it ratted. Starting an industry job means it builds.
 * The classifier doesn't speculate — it reports what the character
 * demonstrably did in the window.
 *
 * Signal sources (all gracefully degrade when absent):
 *   - Ratter / Mission Runner : CWM ratting.getCharacterBreakdown
 *                               (bounty ref_types vs agent_mission ref_types)
 *   - Miner                   : Mining Manager monthly summaries
 *   - Trader                  : SeAT character_wallet_transactions
 *                               (high txn count over window WITH sells —
 *                               an active market participant, not just
 *                               someone buying their ships)
 *   - Planetary Industrialist : SeAT character_planets (current colonies)
 *   - Industrialist           : SeAT character_industry_jobs (active jobs,
 *                               split manufacturing vs science by activity_id)
 *   - FC                      : SeAT Broadcast pings.broadcast.sent
 *                               (human-level — resolved via the
 *                               character's owner user). EventBus-
 *                               accumulated, no network call.
 *   - PvPer                   : zKillboard stats (ships_destroyed). NOTE:
 *                               zKill gives LIFETIME stats, not windowed —
 *                               so this is "has a meaningful PvP record",
 *                               not "PvP'd in the last 6 months". Read
 *                               via a cache PEEK (never a cold fetch from
 *                               the classifier) so bulk rendering can't
 *                               storm zKill — the badge shows when the
 *                               data is already warm.
 *
 * Each role carries an intensity (primary = dominant by ISK / colony
 * count, secondary = present but not dominant) and a human detail
 * string ("1.4B bounties, 6mo").
 *
 * Returns a stable shape so the member + player views can render
 * badges without branching on availability per-signal:
 *   [
 *     'roles'   => [ ['key','label','icon','intensity','detail'], ... ],
 *     'primary' => 'ratter'|null,
 *     'has_data'=> bool,
 *   ]
 *
 * Multi-role is expected and normal — a multibox main can be both a
 * ratter and an industrialist. The 'primary' field is the single most
 * dominant activity for the at-a-glance badge.
 */
class CharacterRoleClassifier
{
    private const CACHE_TTL = 1800; // 30 min — activity profile changes slowly

    // ISK floor a money-signal role must clear to register. Below this
    // it's noise (one stray ratting tick, a single ore can).
    private const ISK_FLOOR = 100_000_000; // 100M over the window

    // Trader: at least this many market transactions over the window
    // AND at least one sell — filters out "bought 5 ships" from real
    // market activity.
    private const TRADER_MIN_TXNS = 40;

    // PvPer: at least this many lifetime kills to register — filters
    // out the "got on one killmail once" noise.
    private const PVP_MIN_KILLS = 20;

    // Industrialist (blueprint sourcing): at least this many FULFILLED
    // corp blueprint requests to register the builder signal from BP
    // sourcing alone. Below this it's a one-off, not a habit.
    private const BP_MIN_FULFILLED = 3;

    public function __construct(
        private CrossPluginDataService $cross,
        private ZkillService $zkill,
        private FcActivityService $fc
    ) {}

    /**
     * @return array{roles: array<int,array>, primary: ?string, has_data: bool}
     */
    public function classify(int $characterId, int $corporationId, int $months = 6): array
    {
        return Cache::remember(
            "hr-role-class-{$characterId}-{$corporationId}-{$months}",
            self::CACHE_TTL,
            fn () => $this->build($characterId, $corporationId, $months)
        );
    }

    public function bustCache(int $characterId, int $corporationId, int $months = 6): void
    {
        Cache::forget("hr-role-class-{$characterId}-{$corporationId}-{$months}");
    }

    // -----------------------------------------------------------------

    private function build(int $characterId, int $corporationId, int $months): array
    {
        $roles = [];
        // Magnitudes used to pick the single dominant (primary) role.
        // Money signals compared in ISK; activity-count signals get a
        // synthetic weight so they can win primary only when no money
        // signal is present.
        $weights = [];

        // --- Ratting / missions (CWM) ---
        try {
            $bd = $this->cross->getRattingBreakdown($characterId, $corporationId, $months);
            if (($bd['available'] ?? false)) {
                [$bounty, $mission] = $this->foldRatting($bd);
                if ($bounty >= self::ISK_FLOOR) {
                    $roles['ratter'] = $this->role('ratter', 'Ratter', 'fa-crosshairs',
                        $this->iskShort($bounty) . ' bounties');
                    $weights['ratter'] = $bounty;
                }
                if ($mission >= self::ISK_FLOOR) {
                    $roles['mission'] = $this->role('mission', 'Mission Runner', 'fa-scroll',
                        $this->iskShort($mission) . ' agent rewards');
                    $weights['mission'] = $mission;
                }
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] role classifier ratting failed: ' . $e->getMessage());
        }

        // --- Mining (MM) ---
        try {
            $mining = $this->cross->getMiningHistory($characterId, $months);
            $mineVal = (float) ($mining['total_value'] ?? 0);
            if (($mining['available'] ?? false) && $mineVal >= self::ISK_FLOOR) {
                $roles['miner'] = $this->role('miner', 'Miner', 'fa-gem',
                    $this->iskShort($mineVal) . ' ore value');
                $weights['miner'] = $mineVal;
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] role classifier mining failed: ' . $e->getMessage());
        }

        // --- Planetary industry (SeAT core) ---
        try {
            if (Schema::hasTable('character_planets')) {
                $piCount = DB::table('character_planets')
                    ->where('character_id', $characterId)
                    ->count();
                if ($piCount > 0) {
                    $roles['pi'] = $this->role('pi', 'Planetary Industrialist', 'fa-globe',
                        $piCount . ' active ' . ($piCount === 1 ? 'colony' : 'colonies'));
                    // Activity-count signal: weight below any money role so
                    // PI only wins primary when it's the sole activity.
                    $weights['pi'] = $piCount * 1_000_000;
                }
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] role classifier PI failed: ' . $e->getMessage());
        }

        // --- Industry jobs (SeAT core) ---
        try {
            if (Schema::hasTable('character_industry_jobs')) {
                $jobs = DB::table('character_industry_jobs')
                    ->where('character_id', $characterId)
                    ->get(['activity_id']);
                if ($jobs->isNotEmpty()) {
                    // activity_id: 1 = manufacturing; 3/4/5/8 = science
                    // (research/copy/invention); 9 = reactions.
                    $mfg = $jobs->where('activity_id', 1)->count();
                    $sci = $jobs->whereIn('activity_id', [3, 4, 5, 8])->count();
                    $rx  = $jobs->where('activity_id', 9)->count();
                    $total = $jobs->count();

                    $bits = [];
                    if ($mfg > 0) $bits[] = $mfg . ' mfg';
                    if ($sci > 0) $bits[] = $sci . ' science';
                    if ($rx > 0)  $bits[] = $rx . ' reactions';

                    $roles['industry'] = $this->role('industry', 'Industrialist', 'fa-industry',
                        $total . ' ' . ($total === 1 ? 'job' : 'jobs') . ' (' . implode(', ', $bits) . ')');
                    $weights['industry'] = $total * 1_000_000;
                }
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] role classifier industry failed: ' . $e->getMessage());
        }

        // --- Blueprint sourcing (Blueprint Manager via MC) ---
        // A member who sources blueprints from the corp library is a
        // builder. Strengthens an existing Industrialist signal, or
        // establishes it on its own when there's no active industry job in
        // the window (the job already completed, or they build from sourced
        // BPs). The capability gives full-history request stats, so this is
        // "has a real BP-sourcing record", like the zKill lifetime signal.
        // No-op when Blueprint Manager / MC is absent.
        try {
            $bp = $this->cross->getBlueprintCharacterStats($characterId, $corporationId);
            if (($bp['available'] ?? false) && is_array($bp['data'] ?? null)) {
                $fulfilled = (int) ($bp['data']['fulfilled'] ?? 0);
                if ($fulfilled >= self::BP_MIN_FULFILLED) {
                    $bpDetail = $fulfilled . ' ' . ($fulfilled === 1 ? 'blueprint' : 'blueprints') . ' sourced';
                    if (isset($roles['industry'])) {
                        // Already a builder via active jobs — enrich the
                        // detail and bump the weight so heavy BP sourcing
                        // ranks the role higher for the primary pick.
                        $roles['industry']['detail'] .= ' · ' . $bpDetail;
                        $weights['industry'] = ($weights['industry'] ?? 0) + $fulfilled * 1_000_000;
                    } else {
                        $roles['industry'] = $this->role('industry', 'Industrialist', 'fa-industry', $bpDetail);
                        $weights['industry'] = $fulfilled * 1_000_000;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] role classifier blueprint failed: ' . $e->getMessage());
        }

        // --- Trader (SeAT core: market transactions) ---
        try {
            if (Schema::hasTable('character_wallet_transactions')) {
                $since = now()->subMonths($months);
                $txns = DB::table('character_wallet_transactions')
                    ->where('character_id', $characterId)
                    ->where('date', '>=', $since)
                    ->selectRaw('COUNT(*) as cnt, SUM(unit_price * quantity) as volume, SUM(is_buy = 0) as sells')
                    ->first();

                $cnt    = (int) ($txns->cnt ?? 0);
                $sells  = (int) ($txns->sells ?? 0);
                $volume = (float) ($txns->volume ?? 0);

                // Active market participant: high transaction count AND
                // at least one sell (actual trading, not just buying).
                if ($cnt >= self::TRADER_MIN_TXNS && $sells > 0) {
                    $roles['trader'] = $this->role('trader', 'Trader', 'fa-balance-scale',
                        number_format($cnt) . ' txns · ' . $this->iskShort($volume) . ' volume');
                    // Real ISK volume — competes with money signals for primary.
                    $weights['trader'] = $volume;
                }
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] role classifier trader failed: ' . $e->getMessage());
        }

        // --- PvPer (zKillboard) ---
        // Cache PEEK only — never forces a cold fetch from inside the
        // classifier. The PvP badge appears when zKill data is already
        // warm (a member-profile visit fetches it for the PvP card).
        // This keeps the classifier all-local + fast, and a player
        // profile with N alts can't trigger N cold zKill requests.
        try {
            $stats = $this->zkill->getCachedStats($characterId);
            if (is_array($stats) && ($stats['available'] ?? false)) {
                $kills = (int) ($stats['ships_destroyed'] ?? 0);
                if ($kills >= self::PVP_MIN_KILLS) {
                    $danger = $stats['danger_ratio'] ?? null;
                    $detail = number_format($kills) . ' kills'
                        . ($danger !== null ? ' · ' . $danger . '% danger' : '');
                    $roles['pvper'] = $this->role('pvper', 'PvPer', 'fa-skull-crossbones', $detail);
                    // Synthetic weight below money signals — PvP isn't a
                    // corp-income activity, so it never wins "primary
                    // breadwinner" over a real ISK role, but it ranks
                    // above pure-presence roles by kill count.
                    $weights['pvper'] = min(50_000_000, $kills * 100_000);
                }
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] role classifier pvp failed: ' . $e->getMessage());
        }

        // --- FC (SeAT Broadcast, human-level) ---
        // FC activity is sent by the SeAT user, not a character, so we
        // resolve the character's owner and check the human's FC profile.
        // EventBus-accumulated — no network call.
        try {
            $ownerUserId = (int) DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->whereNull('deleted_at')
                ->value('user_id');
            if ($ownerUserId > 0) {
                $fcProfile = $this->fc->getForUser($ownerUserId);
                if (!empty($fcProfile['is_fc'])) {
                    $roles['fc'] = $this->role('fc', 'FC', 'fa-broadcast-tower',
                        $fcProfile['total'] . ' broadcasts');
                    // Leadership signal — synthetic weight, ranks above
                    // pure-presence roles but below economic activity.
                    $weights['fc'] = min(60_000_000, ((int) $fcProfile['total']) * 1_000_000);
                }
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] role classifier FC failed: ' . $e->getMessage());
        }

        // Pick the single dominant role + tag intensities.
        $primary = null;
        if (!empty($weights)) {
            arsort($weights);
            $primary = array_key_first($weights);
        }
        foreach ($roles as $key => &$role) {
            $role['intensity'] = ($key === $primary) ? 'primary' : 'secondary';
        }
        unset($role);

        // Re-order so the primary role renders first, rest by weight.
        $ordered = [];
        foreach (array_keys($weights) as $key) {
            if (isset($roles[$key])) $ordered[] = $roles[$key];
        }

        return [
            'roles'    => $ordered,
            'primary'  => $primary,
            'has_data' => !empty($ordered),
        ];
    }

    /**
     * Fold CWM ratting breakdown ref_types into [bounty, mission] ISK.
     */
    private function foldRatting(array $breakdown): array
    {
        $rows = $breakdown['data'] ?? $breakdown['breakdown'] ?? [];
        if (is_object($rows)) $rows = (array) $rows;
        $bounty = 0.0;
        $mission = 0.0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $r = is_array($row) || is_object($row) ? (array) $row : [];
                $refType = (string) ($r['ref_type'] ?? '');
                $total   = (float) ($r['total'] ?? 0);
                if ($total <= 0) continue;
                if (str_starts_with($refType, 'bounty')) {
                    $bounty += $total;
                } elseif (str_starts_with($refType, 'agent_mission')) {
                    $mission += $total;
                }
            }
        }
        return [$bounty, $mission];
    }

    private function role(string $key, string $label, string $icon, string $detail): array
    {
        return [
            'key'    => $key,
            'label'  => $label,
            'icon'   => $icon,
            'detail' => $detail,
        ];
    }

    private function iskShort(float $v): string
    {
        $abs = abs($v);
        if ($abs >= 1e12) return number_format($v / 1e12, 1) . 'T';
        if ($abs >= 1e9)  return number_format($v / 1e9, 1) . 'B';
        if ($abs >= 1e6)  return number_format($v / 1e6, 0) . 'M';
        return number_format($v, 0);
    }
}

<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\Application;
use HrManager\Models\Note;
use HrManager\Models\PlayerStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Web\Models\User;

/**
 * Player-centric (user_id-keyed) aggregator. Returns one rolled-up view of
 * every character on a SeAT user account plus per-corp tier / status flags.
 *
 *   - All alts surfaced regardless of current corp affiliation (per Matt's
 *     "detailed page should display all characters regardless if they are
 *     in corp or no" requirement)
 *   - days_in_corp computed from character_corporation_histories so we get
 *     past stints + cumulative totals, not just the current snapshot
 *   - last_activity sourced across SeAT wallet journals + MM mining ledger +
 *     CWM ratting; max() across sources is the player's freshest activity
 *     signal. Industry / zKill deferred per the v1.2.0 design conversation.
 */
class PlayerService
{
    private TierService $tier;

    public function __construct(TierService $tier)
    {
        $this->tier = $tier;
    }

    /**
     * Full per-player summary for the detail view. Computed on-demand;
     * no cache yet (page is admin-only / low-traffic).
     *
     * @return array{
     *   user: ?User,
     *   characters: Collection,
     *   alt_summaries: array,
     *   tier: ?array,
     *   status: ?PlayerStatus,
     *   current_stint_days: ?int,
     *   total_days_in_corp: ?int,
     *   last_activity_at: ?Carbon,
     *   note_count: int,
     * }
     */
    public function getPlayerSummary(int $userId, int $corporationId): array
    {
        $user = User::find($userId);
        $characters = $this->charactersForUser($userId);

        // Alt cards show every character the account has linked — including ones
        // whose token was later revoked/expired — so a token-lost alt still
        // appears, badged with its token state. The wallet / note rollups below
        // stay on the live-token $characters set (no fresh data for dead tokens).
        $displayCharacters = $this->ownedCharactersForUser($userId);
        $tokenStatuses = $this->tokenStatusMap(
            $displayCharacters->pluck('character_id')->map(fn ($id) => (int) $id)->all()
        );

        $altSummaries = $displayCharacters->map(function (CharacterInfo $char) use ($corporationId, $tokenStatuses) {
            $alt = $this->summarizeAlt($char, $corporationId);
            $alt['token_status'] = $tokenStatuses[(int) $char->character_id] ?? 'missing';
            return $alt;
        })->values()->all();

        // Player-level rollups: max across alts (per the design - if Alt 1
        // joined the corp in 2020 and Main joined in 2023, the player has
        // been associated with the corp since 2020).
        $currentStintDays = collect($altSummaries)->max('current_stint_days');
        $totalDaysInCorp  = collect($altSummaries)->max('total_days_in_corp');
        $lastActivityAt   = collect($altSummaries)
            ->pluck('last_activity_at')
            ->filter()
            ->max();

        $tier = $this->tier->resolveTier($userId, $corporationId);

        $status = PlayerStatus::where('user_id', $userId)
            ->where('corporation_id', $corporationId)
            ->first();

        $noteCount = $this->noteCountForPlayer($userId, $characters->pluck('character_id')->all());

        $walletRollup = $this->aggregateWalletSignals($characters, $corporationId);

        return [
            'user'               => $user,
            'characters'         => $characters,
            'alt_summaries'      => $altSummaries,
            'tier'               => $tier,
            'status'             => $status,
            'current_stint_days' => $currentStintDays,
            'total_days_in_corp' => $totalDaysInCorp,
            'last_activity_at'   => $lastActivityAt,
            'note_count'         => $noteCount,
            'wallet_rollup'      => $walletRollup,
        ];
    }

    /**
     * Aggregate CWM wallet signals across every alt to a player-level rollup
     * for the player profile UI. Quietly returns ['available' => false]
     * when MC/CWM are absent or no alt has CWM data — caller renders the
     * muted "wallet rollup unavailable" line.
     *
     * @param  \Illuminate\Database\Eloquent\Collection $characters
     */
    private function aggregateWalletSignals($characters, int $corporationId): array
    {
        $cross = app(CrossPluginDataService::class);

        $anyAvailable        = false;
        $lifetimeContributed = 0.0;
        $lifetimeWithdrawn   = 0.0;
        $netSum              = 0.0;
        $worstCompliance     = null;
        $latestContribution  = null;
        $bestPercentile      = null; // best contribution percentile across alts
        $totalContributors   = null; // corp contributor pool size (for "Nth of M")

        foreach ($characters as $char) {
            $characterId = (int) ($char->character_id ?? 0);
            if ($characterId <= 0) {
                continue;
            }

            $lifetime = $cross->getCharacterLifetimeSummary($characterId, $corporationId);
            if ($lifetime['available'] ?? false) {
                $anyAvailable = true;
                $data = $lifetime['data'] ?? null;
                $lifetimeContributed += (float) ($this->envelopeField($data, 'lifetime_total_contributed') ?? 0);
                $lifetimeWithdrawn   += (float) ($this->envelopeField($data, 'lifetime_total_withdrawn')   ?? 0);
                $lastPeriod = $this->envelopeField($data, 'last_contribution_period');
                if ($lastPeriod) {
                    try {
                        $parsed = Carbon::parse($lastPeriod);
                        if ($latestContribution === null || $parsed->gt($latestContribution)) {
                            $latestContribution = $parsed;
                        }
                    } catch (\Throwable $e) {
                        // best-effort; ignore unparseable periods
                    }
                }
            }

            $net = $cross->getCharacterNetPosition($characterId, $corporationId, 6);
            if ($net['available'] ?? false) {
                $anyAvailable = true;
                $netSum += (float) ($this->envelopeField($net['data'] ?? null, 'net_amount') ?? 0);
            }

            $tax = $cross->getCharacterTaxCompliance($characterId, $corporationId, 6);
            if ($tax['available'] ?? false) {
                $anyAvailable = true;
                $pct = $this->envelopeField($tax['data'] ?? null, 'compliance_pct');
                if ($pct !== null && ($worstCompliance === null || $pct < $worstCompliance)) {
                    $worstCompliance = (float) $pct;
                }
            }

            // Contribution percentile (how this alt ranks among the corp's
            // contributors). Keep the BEST alt's standing — the player's
            // overall pull is at least their strongest character's.
            $rank = $cross->getCharacterContributionPercentile($characterId, $corporationId, 'last_3_months');
            if ($rank['available'] ?? false) {
                $anyAvailable = true;
                $rData = $rank['data'] ?? null;
                $p = $this->envelopeField($rData, 'percentile');
                if ($p !== null && ($bestPercentile === null || (float) $p > $bestPercentile)) {
                    $bestPercentile = (float) $p;
                }
                $tc = $this->envelopeField($rData, 'total_contributors');
                if ($tc !== null) {
                    $totalContributors = (int) $tc;
                }
            }
        }

        // Ratting / mining / engagement rolled up from HR's own cached
        // assessments (no extra bridge calls). Counts even when CWM is absent,
        // so the impact panel still shows activity for ratters/miners.
        $charIds = $characters->pluck('character_id')->map(fn ($id) => (int) $id)->filter()->values()->all();
        $rattingIncome = 0.0;
        $miningValue   = 0.0;
        $activeMonths  = 0;
        if (!empty($charIds) && \Illuminate\Support\Facades\Schema::hasTable('hr_manager_member_assessments')) {
            $ass = \HrManager\Models\MemberAssessment::whereIn('character_id', $charIds)
                ->where('corporation_id', $corporationId)
                ->get(['total_ratting_income', 'total_mining_value', 'active_months']);
            $rattingIncome = (float) $ass->sum('total_ratting_income');
            $miningValue   = (float) $ass->sum('total_mining_value');
            $activeMonths  = (int) ($ass->max('active_months') ?? 0);
            if ($rattingIncome > 0 || $miningValue > 0 || $activeMonths > 0) {
                $anyAvailable = true;
            }
        }

        if (!$anyAvailable) {
            return ['available' => false];
        }

        return [
            'available'           => true,
            'lifetime_contributed' => $lifetimeContributed,
            'lifetime_withdrawn'   => $lifetimeWithdrawn,
            'net_position_6mo'     => $netSum,
            'is_net_positive'      => $netSum >= 0,
            'worst_compliance_pct' => $worstCompliance,
            'last_contribution_at' => $latestContribution,
            'contribution_percentile' => $bestPercentile,
            'total_contributors'      => $totalContributors,
            'ratting_income'          => $rattingIncome,
            'mining_value'            => $miningValue,
            'active_months'           => $activeMonths,
        ];
    }

    private function envelopeField($data, string $key)
    {
        if ($data === null) return null;
        if (is_array($data)) return $data[$key] ?? null;
        if (is_object($data)) return $data->{$key} ?? null;
        return null;
    }

    /**
     * Index-page row builder. One row per SeAT user with at least one
     * character currently in $corporationId. Light query - only enough
     * data to render a list (name, alt count, tier badge, status flag).
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function indexForCorporation(int $corporationId, ?string $search = null, int $perPage = 50)
    {
        // Users who have at least one character currently in the target corp
        $userIds = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('character_affiliations.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->distinct()
            ->pluck('refresh_tokens.user_id');

        if ($userIds->isEmpty()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $query = User::whereIn('id', $userIds);

        if ($search) {
            // Search the user's main character name
            $query->whereHas('main_character', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate($perPage);

        // Decorate each user with summary fields
        $users->getCollection()->transform(function (User $u) use ($corporationId) {
            $characters = $this->charactersForUser($u->id);
            $tier = $this->tier->resolveTier($u->id, $corporationId);
            $status = PlayerStatus::where('user_id', $u->id)
                ->where('corporation_id', $corporationId)
                ->first();

            $main = $characters->firstWhere('character_id', $u->main_character_id) ?? $characters->first();
            $inCorpCount = $characters->filter(function (CharacterInfo $c) use ($corporationId) {
                return $this->affiliationCorpId($c->character_id) === $corporationId;
            })->count();

            $u->hr_summary = [
                'main_character'  => $main,
                'alt_count'       => $characters->count(),
                'in_corp_count'   => $inCorpCount,
                'tier'            => $tier,
                'status'          => $status,
            ];
            return $u;
        });

        return $users;
    }

    // -----------------------------------------------------------------
    // Per-character helpers
    // -----------------------------------------------------------------

    /**
     * Every CharacterInfo row attached to a SeAT user via refresh_tokens.
     * Includes characters that left the corp - the detail page surfaces
     * out-of-corp alts deliberately for historical context.
     */
    public function charactersForUser(int $userId): Collection
    {
        $characterIds = RefreshToken::where('user_id', $userId)
            ->whereNull('deleted_at')
            ->pluck('character_id')
            ->all();

        if (empty($characterIds)) {
            return new Collection();
        }

        return CharacterInfo::whereIn('character_id', $characterIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * Every CharacterInfo the account has EVER linked, including characters whose
     * refresh token was later revoked or expired (soft-deleted in refresh_tokens).
     * Unlike charactersForUser() (live tokens only), this powers the player-profile
     * alt cards so a token-lost alt still shows up, badged with its token state.
     * A superset of charactersForUser() — never returns fewer characters.
     */
    public function ownedCharactersForUser(int $userId): Collection
    {
        if (!Schema::hasTable('refresh_tokens')) {
            return new Collection();
        }

        // DB::table (query builder) ignores the soft-delete global scope, so this
        // sees revoked/expired tokens too — that's how a lost-token alt is kept.
        $characterIds = DB::table('refresh_tokens')
            ->where('user_id', $userId)
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if (empty($characterIds)) {
            return new Collection();
        }

        return CharacterInfo::whereIn('character_id', $characterIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * Per-character token status for the player-profile alt badges:
     *   'active'  — a live refresh token is on record
     *   'expired' — a token row exists but is soft-deleted (revoked / expired / lost)
     *   'missing' — no token row at all (caller supplies this via the ?? fallback)
     * Reads only the already-synced refresh_tokens table; one batched query.
     *
     * @param  array<int,int>  $charIds
     * @return array<int,string>  character_id => 'active'|'expired'
     */
    private function tokenStatusMap(array $charIds): array
    {
        if (empty($charIds) || !Schema::hasTable('refresh_tokens')) {
            return [];
        }

        $rows = DB::table('refresh_tokens')
            ->whereIn('character_id', $charIds)
            ->get(['character_id', 'deleted_at']);

        $map = [];
        foreach ($rows as $r) {
            $cid = (int) $r->character_id;
            // Defensive against a stray duplicate row: a live token always wins.
            if (($map[$cid] ?? null) === 'active') {
                continue;
            }
            $map[$cid] = ($r->deleted_at !== null) ? 'expired' : 'active';
        }

        return $map;
    }

    /**
     * Per-alt summary for the detail page status box.
     */
    private function summarizeAlt(CharacterInfo $char, int $corporationId): array
    {
        $currentCorp = $this->affiliationCorpId($char->character_id);
        $inCorpNow   = $currentCorp === $corporationId;
        $stints      = $this->stintsInCorp($char->character_id, $corporationId);

        $totalDays = 0;
        $currentStintDays = null;
        foreach ($stints as $stint) {
            $end = $stint['end'] ?? now();
            $days = max(0, $stint['start']->diffInDays($end));
            $totalDays += $days;
            if ($stint['is_current']) {
                $currentStintDays = $days;
            }
        }

        return [
            'character_id'        => (int) $char->character_id,
            'name'                => $char->name ?? '#' . $char->character_id,
            'current_corp_id'     => $currentCorp,
            'in_corp_now'         => $inCorpNow,
            'current_stint_days'  => $currentStintDays,
            'total_days_in_corp'  => $totalDays,
            'stint_count'         => count($stints),
            'last_activity_at'    => $this->lastActivityFor($char->character_id),
            'security_status'     => $char->security_status,
            'total_sp'            => $char->total_sp,
        ];
    }

    /**
     * All historical stints this character has had in the given corp, ordered
     * oldest first. Each stint: ['start' => Carbon, 'end' => ?Carbon,
     * 'is_current' => bool]. Computed from character_corporation_histories
     * by pairing each row with the start of the NEXT row (any corp) as the
     * end date. The most recent row in the target corp is "current" iff
     * character_affiliations still shows them there.
     *
     * @return array<int, array{start:Carbon, end:?Carbon, is_current:bool}>
     */
    private function stintsInCorp(int $characterId, int $corporationId): array
    {
        try {
            $allRows = DB::table('character_corporation_histories')
                ->where('character_id', $characterId)
                ->orderBy('start_date', 'asc')
                ->get(['record_id', 'corporation_id', 'start_date']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[HR Manager] PlayerService: corporation_history query failed for character ' . $characterId . ': ' . $e->getMessage()
            );
            return [];
        }

        if ($allRows->isEmpty()) {
            return [];
        }

        $currentAffilCorp = $this->affiliationCorpId($characterId);
        $stints = [];

        $rows = $allRows->values();
        for ($i = 0; $i < $rows->count(); $i++) {
            $row = $rows[$i];
            if ((int) $row->corporation_id !== $corporationId) {
                continue;
            }

            $start = Carbon::parse($row->start_date);
            $nextRow = $rows->get($i + 1);
            $end = $nextRow ? Carbon::parse($nextRow->start_date) : null;
            $isCurrent = $nextRow === null && $currentAffilCorp === $corporationId;

            $stints[] = [
                'start'      => $start,
                'end'        => $end,
                'is_current' => $isCurrent,
            ];
        }

        return $stints;
    }

    private function affiliationCorpId(int $characterId): ?int
    {
        $row = CharacterAffiliation::find($characterId);
        return $row ? (int) $row->corporation_id : null;
    }

    /**
     * Max activity timestamp across every signal we have access to:
     *   - character_wallet_journals (SeAT-native, always available)
     *   - mining_manager_* (when MM installed)
     *   - corp_wallet_manager via bridge (when CWM installed)
     *
     * Skipped for v1.2.0: industry jobs, zKill kills. Add as new branches
     * here when those data sources are wired.
     */
    private function lastActivityFor(int $characterId): ?Carbon
    {
        $candidates = [];

        // SeAT wallet journal — any movement is "this person logged in"
        if (Schema::hasTable('character_wallet_journals')) {
            try {
                $date = DB::table('character_wallet_journals')
                    ->where('character_id', $characterId)
                    ->max('date');
                if ($date) {
                    $candidates[] = Carbon::parse($date);
                }
            } catch (\Throwable $e) {
                // Schema drift — skip silently, log warn
                \Illuminate\Support\Facades\Log::warning('[HR Manager] PlayerService: wallet journal query failed: ' . $e->getMessage());
            }
        }

        // Mining Manager — last mining summary record. Same defensive
        // class_exists pattern as CrossPluginDataService.
        if (class_exists('MiningManager\Models\MiningLedgerMonthlySummary')) {
            try {
                $month = DB::table('mining_manager_ledger_monthly_summaries')
                    ->where('character_id', $characterId)
                    ->max('month');
                if ($month) {
                    // monthly aggregate; use end-of-month as the proxy timestamp
                    $candidates[] = Carbon::parse($month)->endOfMonth();
                }
            } catch (\Throwable $e) {
                // MM table may not be present even if class is autoloaded
            }
        }

        // CWM ratting — read via PluginBridge capability (degrades when CWM absent)
        if (class_exists('ManagerCore\Services\PluginBridge')) {
            try {
                $bridge = app(\ManagerCore\Services\PluginBridge::class);
                if ($bridge->hasCapability('corp-wallet-manager', 'ratting.getCharacterIncome')) {
                    $corpId = $this->affiliationCorpId($characterId);
                    if ($corpId) {
                        $income = $bridge->call('corp-wallet-manager', 'ratting.getCharacterIncome', $characterId, $corpId, 12);
                        $last = $income->last_activity ?? null;
                        if ($last) {
                            $candidates[] = Carbon::parse($last);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // CWM call failed — degrade silently
            }
        }

        if (empty($candidates)) {
            return null;
        }

        return collect($candidates)->max();
    }

    /**
     * Count notes attached anywhere on this player:
     *   - 'member' notes for any of their characters
     *   - 'application' notes for any of their applications
     *   - 'player' notes for the user itself (added 2026-05-25 morph map)
     */
    private function noteCountForPlayer(int $userId, array $characterIds): int
    {
        $appIds = Application::whereIn('character_id', $characterIds)->pluck('id')->all();

        $count = 0;
        if (!empty($characterIds)) {
            $count += Note::where('noteable_type', 'member')
                ->whereIn('noteable_id', $characterIds)
                ->count();
        }
        if (!empty($appIds)) {
            $count += Note::where('noteable_type', 'application')
                ->whereIn('noteable_id', $appIds)
                ->count();
        }
        $count += Note::where('noteable_type', 'player')
            ->where('noteable_id', $userId)
            ->count();

        return $count;
    }

    /**
     * Notes for the detail-view timeline. Returns all notes across every
     * surface (member-level, application-level, player-level) the viewer
     * is allowed to see (private notes scoped by visibleTo).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function notesForPlayer(int $userId, array $characterIds, int $viewerUserId): \Illuminate\Database\Eloquent\Collection
    {
        $appIds = Application::whereIn('character_id', $characterIds)->pluck('id')->all();

        return Note::where(function ($q) use ($characterIds, $appIds, $userId) {
            $q->where(function ($q2) use ($characterIds) {
                $q2->where('noteable_type', 'member')->whereIn('noteable_id', $characterIds);
            });
            if (!empty($appIds)) {
                $q->orWhere(function ($q2) use ($appIds) {
                    $q2->where('noteable_type', 'application')->whereIn('noteable_id', $appIds);
                });
            }
            $q->orWhere(function ($q2) use ($userId) {
                $q2->where('noteable_type', 'player')->where('noteable_id', $userId);
            });
        })
            ->visibleTo($viewerUserId)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}

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
    /**
     * Ratting / mission ref_types in the CORPORATION wallet journal, where
     * second_party_id is the character who earned the payout. Same list Corp
     * Wallet Manager's ratting service uses; kept here because the batched
     * read below goes at those rows directly.
     */
    private const RAT_REF_TYPES = [
        'bounty_prizes',
        'bounty_prize',
        'agent_mission_reward',
        'agent_mission_time_bonus_reward',
    ];

    /**
     * Trailing window for the ratting read. Matches the window the bridge
     * capability was asked for, so the answer is the one it used to give.
     */
    private const RAT_MONTHS = 12;

    /**
     * character_id => last-activity datetime string, or null meaning "looked
     * and found nothing". Filled by primeActivityCache().
     *
     * The nightly pass asks for the same character over and over: a player is
     * classified once per corp they hold a character in, and the command walks
     * every corp SeAT tracks, so an account with six alts across six corps was
     * recomputing all four activity reads six times for each of them.
     *
     * Capped because this service is a container singleton and a queue worker
     * outlives the job it is running, so an uncapped memo would grow for the
     * life of the worker. Passing the cap clears it, which costs only the
     * reuse.
     */
    private array $activityMemo = [];

    private const ACTIVITY_MEMO_MAX = 20000;

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

        // Resolve every alt's activity in one batch before the per-alt cards
        // below ask for it one at a time.
        $this->primeActivityCache(
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
     * The only three things the nightly classifier reads off a player: which
     * characters the account owns, when each was last active, and the freshest
     * of those.
     *
     * It used to take these out of getPlayerSummary, which builds the whole
     * profile page: tier, status, token badges, note counts, per-character
     * corp-history stints, a CWM wallet rollup. The classifier looks at none
     * of that, and on a large install paying for it was the difference between
     * a nightly pass measured in minutes and one measured in hours.
     *
     * Deliberately takes no corp: nothing here varies by corp, so a player
     * with characters in several corps resolves once and the memo answers the
     * rest of the pass.
     *
     * @return array{characters: Collection, last_activity_at: ?Carbon, alt_activity: array<int, ?Carbon>}
     */
    public function getClassifierSignals(int $userId): array
    {
        // Live tokens only, matching the set the wallet / blueprint / buyback
        // signal passes score against.
        $characters = $this->charactersForUser($userId);

        // Activity rolls up over every character the account has ever linked,
        // revoked tokens included: a token lost last week does not undo the
        // fact that the human was playing.
        $activity = $this->lastActivityForMany(
            $this->ownedCharactersForUser($userId)
                ->pluck('character_id')->map(fn ($id) => (int) $id)->all()
        );

        $last = null;
        foreach ($activity as $stamp) {
            if ($stamp !== null && ($last === null || $stamp->gt($last))) {
                $last = $stamp;
            }
        }

        return [
            'characters'       => $characters,
            'last_activity_at' => $last,
            'alt_activity'     => $activity,
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
     *   - corporation_member_trackings logins (SeAT-native)
     *   - character_wallet_journals (SeAT-native, always available)
     *   - mining_ledger (when MM installed)
     *   - corporation_wallet_journals ratting payouts (SeAT-native)
     *
     * Skipped for v1.2.0: industry jobs, zKill kills. Add as new branches
     * in activitySources() when those data sources are wired.
     */
    private function lastActivityFor(int $characterId): ?Carbon
    {
        return $this->lastActivityForMany([$characterId])[$characterId] ?? null;
    }

    /**
     * Last activity for many characters in a handful of grouped queries
     * instead of four reads per character.
     *
     * @param  array<int>  $characterIds
     * @return array<int, ?Carbon>  character_id => last activity, null for none
     */
    public function lastActivityForMany(array $characterIds): array
    {
        $ids = $this->cleanIds($characterIds);
        if (empty($ids)) {
            return [];
        }

        $this->primeActivityCache($ids);

        $out = [];
        foreach ($ids as $id) {
            $stamp = $this->activityMemo[$id] ?? null;
            $out[$id] = $stamp !== null ? Carbon::parse($stamp) : null;
        }

        return $out;
    }

    /**
     * Resolve last activity for every character in the batch that isn't
     * memoised yet, each source in one grouped query.
     *
     * @param  array<int>  $characterIds
     */
    public function primeActivityCache(array $characterIds): void
    {
        // array_key_exists, not isset: a memoised null means "already looked
        // and there is no activity", and re-querying those every time would
        // give back exactly the work the memo exists to avoid.
        $ids = array_values(array_filter(
            $this->cleanIds($characterIds),
            fn ($id) => !array_key_exists($id, $this->activityMemo)
        ));
        if (empty($ids)) {
            return;
        }

        if (count($this->activityMemo) + count($ids) > self::ACTIVITY_MEMO_MAX) {
            $this->activityMemo = [];
        }

        $best = [];
        foreach ($this->activitySources($ids) as $map) {
            foreach ($map as $charId => $value) {
                $parsed = $this->parseStamp($value);
                if ($parsed === null) {
                    continue;
                }
                if (!isset($best[$charId]) || $parsed->gt($best[$charId])) {
                    $best[$charId] = $parsed;
                }
            }
        }

        // Every id asked for gets an entry, hit or miss, so a character with no
        // activity at all is recorded as answered rather than re-read.
        foreach ($ids as $id) {
            $this->activityMemo[$id] = isset($best[$id]) ? $best[$id]->toDateTimeString() : null;
        }
    }

    /**
     * One character_id => timestamp map per activity source. Newest across all
     * of them wins.
     *
     * @param  array<int>  $ids
     * @return array<int, array<int, mixed>>
     */
    private function activitySources(array $ids): array
    {
        return [
            // SeAT corp member tracking — the character's last in-game LOGIN.
            // The most reliable "is this member active" signal, and the same
            // source the Corp-wide activity panel buckets on, so a member who
            // logs in but doesn't rat / mine / trade still reads as active
            // (not dead weight).
            $this->maxByCharacter('corporation_member_trackings', 'character_id', 'logon_date', $ids),

            // SeAT wallet journal — any movement is "this person logged in".
            $this->maxByCharacter('character_wallet_journals', 'character_id', 'date', $ids),

            // Mining Manager: the last day the character mined. Taken from the
            // ledger, not the monthly summaries, whose month would put the last
            // activity at the end of the month, ahead of today. Same defensive
            // class_exists pattern as CrossPluginDataService.
            class_exists('MiningManager\Models\MiningLedger')
                ? $this->maxByCharacter('mining_ledger', 'character_id', 'date', $ids, fn ($q) => $q->whereNull('deleted_at'))
                : [],

            $this->rattingActivity($ids),
        ];
    }

    /**
     * MAX(date column) per character over one table, chunked. Table and column
     * names are literals from the call sites above, never caller input.
     *
     * @param  array<int>  $ids
     * @return array<int, mixed>
     */
    private function maxByCharacter(string $table, string $keyColumn, string $dateColumn, array $ids, ?callable $constrain = null): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $out = [];
        try {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $query = DB::table($table)
                    ->whereIn($keyColumn, $chunk)
                    ->groupBy($keyColumn)
                    ->selectRaw('`' . $keyColumn . '` AS char_key, MAX(`' . $dateColumn . '`) AS last_seen');

                if ($constrain !== null) {
                    $constrain($query);
                }

                foreach ($query->get() as $row) {
                    $out[(int) $row->char_key] = $row->last_seen;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] PlayerService: batched activity read on ' . $table . ' failed: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Last ratting / mission payout per character, read straight from SeAT's
     * corporation wallet journal.
     *
     * This went through Corp Wallet Manager's ratting capability, one bridge
     * call per character. SeAT indexes that table on corporation_id, id and
     * date but not on second_party_id, so each of those calls scanned the
     * corp's whole journal partition: roughly a second apiece, which was most
     * of what a nightly classification pass spent. One grouped read answers
     * the entire batch off the same rows. CharacterRoleClassifier already
     * makes the same substitution, for the same reason.
     *
     * The corp journal is not redundant with the character's own journal: a
     * member whose wallet scope is missing or revoked has no personal rows at
     * all, and the corp-side payout is then the only record that they were out
     * ratting.
     *
     * Scoped to each character's own corp and to the same trailing window the
     * capability was asked for, so this returns what the bridge returned.
     *
     * @param  array<int>  $ids
     * @return array<int, mixed>
     */
    private function rattingActivity(array $ids): array
    {
        if (!Schema::hasTable('corporation_wallet_journals')) {
            return [];
        }

        $affiliations = $this->affiliationCorpIds($ids);
        if (empty($affiliations)) {
            return [];
        }

        $out = [];
        $corpIds = array_values(array_unique($affiliations));

        try {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $rows = DB::table('corporation_wallet_journals')
                    ->whereIn('corporation_id', $corpIds)
                    ->whereIn('second_party_id', $chunk)
                    ->whereIn('ref_type', self::RAT_REF_TYPES)
                    ->where('date', '>=', now()->subMonths(self::RAT_MONTHS))
                    ->groupBy('corporation_id', 'second_party_id')
                    ->selectRaw('`corporation_id` AS corp_key, `second_party_id` AS char_key, MAX(`date`) AS last_seen')
                    ->get();

                foreach ($rows as $row) {
                    $charId = (int) $row->char_key;
                    // Only the character's own corp counts. The capability this
                    // replaces was asked about one corp at a time, and a payout
                    // from a corp they have since left is not evidence their
                    // current corp holds on them.
                    if (($affiliations[$charId] ?? null) !== (int) $row->corp_key) {
                        continue;
                    }
                    $out[$charId] = $row->last_seen;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] PlayerService: batched ratting activity read failed: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Current corp per character, batched.
     *
     * @param  array<int>  $characterIds
     * @return array<int, int>
     */
    private function affiliationCorpIds(array $characterIds): array
    {
        if (!Schema::hasTable('character_affiliations')) {
            return [];
        }

        $out = [];
        try {
            foreach (array_chunk($characterIds, 1000) as $chunk) {
                $rows = DB::table('character_affiliations')
                    ->whereIn('character_id', $chunk)
                    ->get(['character_id', 'corporation_id']);

                foreach ($rows as $row) {
                    if ($row->corporation_id) {
                        $out[(int) $row->character_id] = (int) $row->corporation_id;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] PlayerService: batched affiliation read failed: ' . $e->getMessage());
        }

        return $out;
    }

    private function parseStamp($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<int> */
    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn ($id) => $id > 0
        )));
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

<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\Application;
use HrManager\Models\MemberAssessment;
use HrManager\Models\PlayerClassification;
use HrManager\Support\TierLevel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregates "quick corp status" data from SeAT core tables + HR tables
 * + sibling plugins (via existing CrossPluginDataService) into one struct
 * the Corp Health view can render without service calls inside Blade.
 *
 * Every section returns ['available' => bool, ...] so the view can branch
 * on "this data source isn't installed / has no director scope / etc."
 * and render a muted fallback rather than crash.
 */
class CorpStatusService
{
    /**
     * @return array{
     *   overview: array,
     *   tier_distribution: array,
     *   last_login_distribution: array,
     *   membership_trend: array,
     *   director_roster: array,
     *   role_holders: array,
     *   application_funnel: array,
     *   character_quality: array,
     *   recruitment_summary: array,
     *   wallet_corp_totals: array,
     *   wallet_freeloaders: array,
     *   wallet_anomalies: array,
     *   untaxed_earners: array,
     *   loyalty_streaks: array,
     *   corp_outflows: array,
     *   role_distribution: array,
     * }
     */
    /**
     * Cache key prefix for the full status struct. Bumping the integer
     * suffix invalidates all stored copies on deploy — safer than
     * scanning the cache.
     */
    // v3: overview now reads corporation_members (authoritative roster)
    // instead of character_affiliations (sparse), so char_count + the
    // registered_pct numbers can be wildly different from v2.
    // v4: tabbed + lazy-loaded. Cache key now includes the tab so each
    // tab builds + caches independently — opening the Economy tab no
    // longer pays for building Overview's sections and vice-versa.
    private const STATUS_CACHE_PREFIX = 'hr-corp-status-v4-';
    private const STATUS_CACHE_TTL    = 300; // 5 minutes

    // Director-health analysis (page-level, used by the Overview CRITICAL +
    // unauthed-director cards). Cached on its own key so it's available on
    // every tab without rebuilding a whole tab payload.
    private const DIRECTOR_HEALTH_CACHE_PREFIX = 'hr-director-health-';
    // A director dark this many days is flagged inactive (corp-survival risk),
    // regardless of whether they're registered in SeAT. Matches the roster's
    // red last-logon threshold.
    private const DIRECTOR_INACTIVE_DAYS = 30;

    // Corp-wide activity: buckets EVERY member (registered or not) by last
    // login from corporation_member_trackings, so the dashboard reflects the
    // whole corp rather than only the handful the token-based classifier
    // covers. The day boundaries mirror the real classifier's bands for an
    // ordinary Member (active < T/2, at_risk T/2..T, inactive T..T*2, dead
    // weight >= T*2) where T is the configured Member-tier threshold — so an
    // unregistered member is bucketed exactly as a registered Member would be
    // on login signal alone. Falls back to 90d if no Member threshold is set.
    private const ROSTER_ACTIVITY_CACHE_PREFIX = 'hr-roster-activity-';
    private const ROSTER_FALLBACK_THRESHOLD = 90;
    // Cap on the flagged member drill-down list so a huge dormant roster can't
    // bloat the cached payload (counts are always exact; the list is a sample).
    private const ROSTER_FLAGGED_LIST_CAP = 100;

    /** Per-request memo of unified member-financial rows — the 5 Wallet
     *  Insights cards share one fetch (corp-wide CWM, else registered cache). */
    private array $memberRowsMemo = [];

    // Section → tab assignment. The controller passes the active tab;
    // only its sections build. Keeping this as a map (not hardcoded per
    // builder) makes it trivial to move a section between tabs later.
    public const TABS = ['overview', 'composition', 'economy', 'structure-compliance', 'recruitment', 'purge'];

    /**
     * Build ONLY the sections belonging to one tab. Cached per
     * (corp, tab) so each tab is independently lazy. Unknown tab falls
     * back to overview.
     */
    public function getCorporationStatus(int $corporationId, string $tab = 'overview'): array
    {
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'overview';
        }
        return Cache::remember(
            self::STATUS_CACHE_PREFIX . $tab . '-' . $corporationId,
            self::STATUS_CACHE_TTL,
            fn() => $this->buildTab($corporationId, $tab)
        );
    }

    /**
     * Force a cache miss across ALL tabs for a corp. Called after
     * operations that materially change what the Corp Health page
     * should show: ClassifierService::classifyCorporation, the Run Now
     * button, AssessmentService refresh of any member in the corp.
     */
    public function bustCache(int $corporationId): void
    {
        foreach (self::TABS as $tab) {
            Cache::forget(self::STATUS_CACHE_PREFIX . $tab . '-' . $corporationId);
        }
        Cache::forget(self::DIRECTOR_HEALTH_CACHE_PREFIX . $corporationId);
        Cache::forget(self::ROSTER_ACTIVITY_CACHE_PREFIX . $corporationId);
    }

    /**
     * Per-tab section builder. Only the active tab's sections are
     * computed — so e.g. the Economy tab's CWM bridge calls never fire
     * unless the operator opens that tab.
     */
    private function buildTab(int $corporationId, string $tab): array
    {
        return match ($tab) {
            'composition' => [
                'role_distribution' => $this->roleDistribution($corporationId),
                'character_quality' => $this->characterQuality($corporationId),
                'role_holders'      => $this->roleSummary($corporationId),
                'director_roster'   => $this->roleRoster($corporationId, 'Director'),
                'fc_status'         => app(FcActivityService::class)->getCorpFcStatus($corporationId),
            ],
            'economy' => [
                'wallet_corp_totals' => $this->walletCorpTotals($corporationId),
                'top_contributors'   => $this->topContributors($corporationId),
                'wallet_freeloaders' => $this->walletFreeloaders($corporationId),
                'wallet_anomalies'   => $this->walletAnomalies($corporationId),
                'untaxed_earners'    => $this->untaxedEarners($corporationId),
                'loyalty_streaks'    => $this->loyaltyStreaks($corporationId),
                'corp_outflows'      => $this->corpOutflows($corporationId),
                'flagged_members'    => $this->corpWalletFlaggedMembers($corporationId),
            ],
            'recruitment' => [
                'application_funnel'  => $this->applicationFunnel($corporationId),
                'recruitment_stats'   => $this->recruitmentStats($corporationId),
                'recruitment_summary' => $this->recruitmentSummary($corporationId),
                'accepted_not_joined' => $this->acceptedNotJoined($corporationId),
            ],
            'purge' => [
                'purge_board' => app(PurgeBoardService::class)->getCorpBoard($corporationId),
            ],
            default => [ // overview
                'overview'                => $this->overview($corporationId),
                'tier_distribution'       => $this->tierDistribution($corporationId),
                'last_login_distribution' => $this->lastLoginDistribution($corporationId),
                'membership_trend'        => $this->membershipTrend($corporationId, 90),
                'structure_health'        => $this->structureHealth($corporationId),
            ],
        };
    }

    /**
     * Corp infrastructure health from SeAT's authoritative
     * corporation_structures table, gated on Structure Manager being
     * installed (it is SM's domain). Read-only, no ESI, no changes to SM.
     * Returns available=false when SM is absent or there are no structures,
     * so the Corp Health overview hides the card cleanly.
     */
    private function structureHealth(int $corporationId): array
    {
        if (!class_exists('StructureManager\StructureManagerServiceProvider')
            || !Schema::hasTable('corporation_structures')) {
            return ['available' => false, 'reason' => 'structure_manager_absent'];
        }

        try {
            $hasUniverse = Schema::hasTable('universe_structures');
            // Optional SDE join for the structure-type breakdown. Guarded so a
            // stripped install with no SDE still renders totals + fuel + threat
            // (the breakdown just goes empty). Never a hard requirement.
            $hasSde = Schema::hasTable('invTypes') && Schema::hasTable('invGroups');

            $query = DB::table('corporation_structures as cs')
                ->where('cs.corporation_id', $corporationId);

            if ($hasUniverse) {
                $query->leftJoin('universe_structures as us', 'us.structure_id', '=', 'cs.structure_id');
            }
            if ($hasSde) {
                $query->leftJoin('invTypes as it', 'it.typeID', '=', 'cs.type_id')
                    ->leftJoin('invGroups as ig', 'ig.groupID', '=', 'it.groupID');
            }

            $select = ['cs.structure_id', 'cs.type_id', 'cs.state', 'cs.fuel_expires', 'cs.state_timer_end'];
            if ($hasUniverse) {
                $select[] = 'us.name as structure_name';
            }
            if ($hasSde) {
                $select[] = 'ig.groupName as group_name';
            }
            $query->select($select);

            $rows = $query->get();
            $total = $rows->count();

            $fuel = ['healthy' => 0, 'low' => 0, 'critical' => 0, 'unfuelled' => 0];
            $byGroup = [];
            $threatened = [];
            $soonest = null;
            $reinforced = ['armor_reinforce', 'hull_reinforce'];
            $now = Carbon::now();

            foreach ($rows as $r) {
                $group = $hasSde ? ($r->group_name ?? null) : null;
                if ($group !== null && $group !== '') {
                    $byGroup[$group] = ($byGroup[$group] ?? 0) + 1;
                }

                $expires = $r->fuel_expires ? Carbon::parse($r->fuel_expires) : null;
                if ($expires === null || $expires->isPast()) {
                    $fuel['unfuelled']++;
                } else {
                    $hrs = $now->diffInHours($expires);
                    if ($hrs < 12) {
                        $fuel['critical']++;
                    } elseif ($hrs < 48) {
                        $fuel['low']++;
                    } else {
                        $fuel['healthy']++;
                    }
                    if ($soonest === null || $expires->lt($soonest['at'])) {
                        $soonest = [
                            'at'   => $expires,
                            'name' => $r->structure_name ?? ('Structure #' . $r->structure_id),
                        ];
                    }
                }

                if (in_array($r->state, $reinforced, true)) {
                    $threatened[] = [
                        'name'      => $r->structure_name ?? ('Structure #' . $r->structure_id),
                        'state'     => (string) $r->state,
                        'timer_end' => $r->state_timer_end,
                    ];
                }
            }

            arsort($byGroup);

            return [
                'available'  => true,
                'total'      => $total,
                'fuel'       => $fuel,
                'by_group'   => $byGroup,
                'threatened' => $threatened,
                'soonest'    => $soonest !== null
                    ? ['name' => $soonest['name'], 'human' => $soonest['at']->diffForHumans()]
                    : null,
                // Incident trend (forward-only, accumulated from structure.alert.*
                // events). Only meaningful when Manager Core is present to carry
                // the events; without MC the snapshot above still stands.
                'incidents'  => class_exists('ManagerCore\Services\EventBus')
                    ? app(StructureIncidentService::class)->getCorpSummary($corporationId, 90)
                    : ['available' => false],
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] structureHealth failed: ' . $e->getMessage());
            return ['available' => false, 'reason' => 'query_failed'];
        }
    }

    /**
     * Legacy full-struct builder — retained for any caller that wants
     * everything at once (e.g. a future export/diagnostic). Not used by
     * the tabbed Corp Health page.
     */
    private function buildCorporationStatus(int $corporationId): array
    {
        return [
            'overview'                => $this->overview($corporationId),
            'tier_distribution'       => $this->tierDistribution($corporationId),
            'last_login_distribution' => $this->lastLoginDistribution($corporationId),
            'membership_trend'        => $this->membershipTrend($corporationId, 90),
            'director_roster'         => $this->roleRoster($corporationId, 'Director'),
            'role_holders'            => $this->roleSummary($corporationId),
            'application_funnel'      => $this->applicationFunnel($corporationId),
            'character_quality'       => $this->characterQuality($corporationId),
            'recruitment_summary'     => $this->recruitmentSummary($corporationId),
            'wallet_corp_totals'      => $this->walletCorpTotals($corporationId),
            'accepted_not_joined'     => $this->acceptedNotJoined($corporationId),
            'top_contributors'        => $this->topContributors($corporationId),
            // Wallet Insights cluster — corp-level rollups of the wallet
            // data HR already caches per-member, plus the one corp-level
            // CWM capability (outflows). All director-tier fraud/health
            // radar. Each degrades gracefully when CWM columns are
            // absent.
            'wallet_freeloaders'      => $this->walletFreeloaders($corporationId),
            'wallet_anomalies'        => $this->walletAnomalies($corporationId),
            'untaxed_earners'         => $this->untaxedEarners($corporationId),
            'loyalty_streaks'         => $this->loyaltyStreaks($corporationId),
            'corp_outflows'           => $this->corpOutflows($corporationId),
            'role_distribution'       => $this->roleDistribution($corporationId),
        ];
    }

    /**
     * Corp composition by activity — what fraction of the roster rats /
     * mines / trades / does PI / does industry. Computed from BULK
     * queries (HR's cached assessment + bulk SeAT-core scans), NOT by
     * running the per-character classifier 200 times. A 200-member corp
     * costs ~5 aggregate queries here instead of 200 classify() calls.
     *
     * Framing: ACTIVITY PARTICIPATION, not mutually-exclusive roles. A
     * multibox member can rat AND mine AND build, so the per-role
     * percentages overlap and won't sum to 100%. The "no detected
     * activity" bucket (roster minus the union of all classified chars)
     * IS exclusive and surfaces lurkers / pure-PvP / inactive members.
     *
     * PvP is deliberately excluded: zKill is a per-character network
     * call, so a corp-wide aggregate would mean N HTTP requests. Noted
     * in the view.
     */
    private function roleDistribution(int $corpId): array
    {
        $rosterSource = $this->resolveRosterSource($corpId);
        $rosterIds = DB::table($rosterSource)
            ->where('corporation_id', $corpId)
            ->pluck('character_id')
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $rosterSize = count($rosterIds);
        if ($rosterSize === 0) {
            return ['available' => false, 'reason' => 'empty_roster'];
        }

        $floor = 100_000_000; // same ISK floor the classifier uses
        $since = now()->subMonths(6);

        // --- Ratters + Miners from HR's cached assessment ---
        $ratterIds = [];
        $minerIds  = [];
        if (Schema::hasColumn('hr_manager_member_assessments', 'total_ratting_income')) {
            $ratterIds = MemberAssessment::where('corporation_id', $corpId)
                ->where('total_ratting_income', '>=', $floor)
                ->pluck('character_id')->map(fn($id) => (int) $id)->all();
            $minerIds = MemberAssessment::where('corporation_id', $corpId)
                ->where('total_mining_value', '>=', $floor)
                ->pluck('character_id')->map(fn($id) => (int) $id)->all();
        }

        // --- Traders from SeAT wallet transactions (bulk group) ---
        $traderIds = [];
        if (Schema::hasTable('character_wallet_transactions')) {
            $traderIds = DB::table('character_wallet_transactions')
                ->whereIn('character_id', $rosterIds)
                ->where('date', '>=', $since)
                ->groupBy('character_id')
                ->havingRaw('COUNT(*) >= 40 AND SUM(is_buy = 0) > 0')
                ->pluck('character_id')->map(fn($id) => (int) $id)->all();
        }

        // --- PI from SeAT character_planets (distinct chars w/ colonies) ---
        $piIds = [];
        if (Schema::hasTable('character_planets')) {
            $piIds = DB::table('character_planets')
                ->whereIn('character_id', $rosterIds)
                ->distinct()
                ->pluck('character_id')->map(fn($id) => (int) $id)->all();
        }

        // --- Industry from SeAT character_industry_jobs ---
        $industryIds = [];
        if (Schema::hasTable('character_industry_jobs')) {
            $industryIds = DB::table('character_industry_jobs')
                ->whereIn('character_id', $rosterIds)
                ->distinct()
                ->pluck('character_id')->map(fn($id) => (int) $id)->all();
        }

        // Union of every classified character → "no detected activity"
        // is the exclusive remainder.
        $classified = array_unique(array_merge($ratterIds, $minerIds, $traderIds, $piIds, $industryIds));
        $noActivity = max(0, $rosterSize - count($classified));

        $roles = [
            ['key' => 'ratter',   'label' => 'Ratters',         'icon' => 'fa-crosshairs', 'count' => count($ratterIds)],
            ['key' => 'miner',    'label' => 'Miners',          'icon' => 'fa-gem',        'count' => count($minerIds)],
            ['key' => 'trader',   'label' => 'Traders',         'icon' => 'fa-balance-scale', 'count' => count($traderIds)],
            ['key' => 'pi',       'label' => 'PI farmers',      'icon' => 'fa-globe',      'count' => count($piIds)],
            ['key' => 'industry', 'label' => 'Industrialists',  'icon' => 'fa-industry',   'count' => count($industryIds)],
        ];
        // Sort by count desc so the dominant activities lead.
        usort($roles, fn($a, $b) => $b['count'] <=> $a['count']);

        // Pct of roster for each (overlapping).
        foreach ($roles as &$r) {
            $r['pct'] = $rosterSize > 0 ? round(($r['count'] / $rosterSize) * 100, 1) : 0;
        }
        unset($r);

        return [
            'available'    => true,
            'roster_size'  => $rosterSize,
            'roles'        => $roles,
            'no_activity'  => $noActivity,
            'no_activity_pct' => $rosterSize > 0 ? round(($noActivity / $rosterSize) * 100, 1) : 0,
            'cwm_present'  => !empty($ratterIds) || !empty($minerIds),
        ];
    }

    // -----------------------------------------------------------------
    // Wallet Insights cluster (director-tier)
    // -----------------------------------------------------------------

    /**
     * Unified per-member financial rows feeding every Wallet Insights card.
     * Prefers CWM's corp-wide roll-up (`contribution.getCorpMemberSummary` —
     * EVERY member, registered or not), and falls back to HR's registered-only
     * assessment cache when CWM/the capability is absent. Normalized object
     * shape so both sources feed the same card logic. Memoized per request so
     * the five cards share a single fetch.
     *
     * Returns ['source' => 'corp-wide'|'registered'|'none', 'rows' => Collection].
     * Each row: {character_id, contribution, net, compliance(?float|null),
     * earned, active_months, is_registered}.
     */
    private function memberFinancialRows(int $corpId): array
    {
        if (array_key_exists($corpId, $this->memberRowsMemo)) {
            return $this->memberRowsMemo[$corpId];
        }

        // 1. Corp-wide via CWM (covers unregistered members).
        $wrapper = app(CrossPluginDataService::class)->getCorpMemberFinancials($corpId);
        $payload = ($wrapper['available'] ?? false) ? ($wrapper['data'] ?? null) : null;
        if (is_array($payload) && !empty($payload['available']) && !empty($payload['members'])) {
            $members = $payload['members'];
            $charIds = array_map(fn ($m) => (int) ($m['character_id'] ?? 0), $members);
            $registered = DB::table('refresh_tokens')
                ->whereIn('character_id', $charIds)
                ->whereNull('deleted_at')
                ->pluck('character_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->toArray();
            $rows = collect($members)->map(fn ($m) => (object) [
                'character_id'  => (int) ($m['character_id'] ?? 0),
                'contribution'  => (float) ($m['lifetime_contribution'] ?? 0),
                'net'           => (float) ($m['net_position'] ?? 0),
                'compliance'    => $m['tax_compliance_pct'] ?? null,
                'earned'        => (float) ($m['ratting_income'] ?? 0),
                'active_months' => (int) ($m['active_months'] ?? 0),
                'is_registered' => isset($registered[(int) ($m['character_id'] ?? 0)]),
            ]);
            return $this->memberRowsMemo[$corpId] = ['source' => 'corp-wide', 'rows' => $rows];
        }

        // 2. Fallback: registered-only assessment cache.
        if (Schema::hasColumn('hr_manager_member_assessments', 'lifetime_contribution')) {
            $rows = MemberAssessment::where('corporation_id', $corpId)
                ->get(['character_id', 'lifetime_contribution', 'net_position_6mo', 'tax_compliance_pct', 'total_ratting_income', 'total_mining_value', 'active_months'])
                ->map(fn ($a) => (object) [
                    'character_id'  => (int) $a->character_id,
                    'contribution'  => (float) ($a->lifetime_contribution ?? 0),
                    'net'           => $a->net_position_6mo !== null ? (float) $a->net_position_6mo : 0.0,
                    'compliance'    => $a->tax_compliance_pct !== null ? (float) $a->tax_compliance_pct : null,
                    'earned'        => (float) ($a->total_ratting_income ?? 0) + (float) ($a->total_mining_value ?? 0),
                    'active_months' => (int) ($a->active_months ?? 0),
                    'is_registered' => true,
                ]);
            return $this->memberRowsMemo[$corpId] = ['source' => 'registered', 'rows' => $rows];
        }

        return $this->memberRowsMemo[$corpId] = ['source' => 'none', 'rows' => collect()];
    }

    /**
     * Bottom contributors: current members with zero or near-zero
     * lifetime contribution. The flip side of topContributors —
     * surfaces who's taking up a roster slot without paying in.
     */
    private function walletFreeloaders(int $corpId, float $threshold = 1_000_000): array
    {
        $data = $this->memberFinancialRows($corpId);
        if ($data['source'] === 'none') {
            return ['available' => false, 'reason' => 'cwm_columns_missing'];
        }

        $rows = $data['rows']
            ->filter(fn ($r) => $r->contribution < $threshold)
            ->sortBy('contribution')
            ->take(10)
            ->values();

        $names = $this->namesFor($rows->pluck('character_id')->all());
        $nonChar = $this->nonCharacterEntities($rows->pluck('character_id')->all());

        $list = $rows->map(function ($r) use ($names, $nonChar, $threshold) {
            // "Active but not paying" is the worst signal — earned ISK
            // (ratting/mining) but contributed almost nothing.
            return [
                'character_id' => $r->character_id,
                'name'         => $names[$r->character_id] ?? ('#' . $r->character_id),
                'entity_type'  => $nonChar[$r->character_id] ?? null,
                'contributed'  => $r->contribution,
                'active_months' => $r->active_months,
                'earned'       => $r->earned,
                'active_but_not_paying' => $r->earned > 100_000_000 && $r->contribution < $threshold,
                'is_registered' => $r->is_registered,
            ];
        })->all();

        return ['available' => true, 'list' => $list, 'threshold' => $threshold, 'source' => $data['source']];
    }

    /**
     * Characters the operator has marked as paying tax to the ALLIANCE
     * rather than the corp. Their corp-tax compliance reads low because the
     * corp never sees their payment, so they're exempted from the LOW/VTX
     * compliance anomaly flags (the net-position NEG flag still applies —
     * that's independent of where tax is paid). Returns [id => true] for
     * O(1) isset() lookups. Global (a character pays the alliance regardless
     * of which corp's board you're viewing).
     */
    private function allianceTaxExemptChars(): array
    {
        $raw = \HrManager\Models\Setting::getValue('alliance_tax_exempt_chars', []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) return [];
        $ids = array_filter(array_map('intval', $raw), fn ($id) => $id > 0);
        return array_fill_keys($ids, true);
    }

    /**
     * Wallet anomaly board: members carrying a red flag — net-negative
     * position (taking more than giving) or compliance under 50%. The
     * "5 of your 200 members need a second look" radar that's otherwise
     * buried one-profile-at-a-time. Alliance-tax-exempt members are not
     * flagged for low compliance (they pay the alliance, not the corp).
     */
    private function walletAnomalies(int $corpId): array
    {
        $data = $this->memberFinancialRows($corpId);
        if ($data['source'] === 'none') {
            return ['available' => false, 'reason' => 'cwm_columns_missing'];
        }

        $exempt = $this->allianceTaxExemptChars();

        $rows = $data['rows']
            ->filter(fn ($r) => $r->net < 0
                || ($r->compliance !== null && $r->compliance < 50 && !isset($exempt[$r->character_id])))
            ->sortBy('net')
            ->take(15)
            ->values();

        $names = $this->namesFor($rows->pluck('character_id')->all());
        $nonChar = $this->nonCharacterEntities($rows->pluck('character_id')->all());

        $list = $rows->map(function ($r) use ($names, $nonChar, $exempt) {
            $isExempt = isset($exempt[$r->character_id]);
            $flags = [];
            if ($r->net < 0) $flags[] = 'NEG';
            if (!$isExempt && $r->compliance !== null && $r->compliance < 30) $flags[] = 'VTX';
            elseif (!$isExempt && $r->compliance !== null && $r->compliance < 50) $flags[] = 'LOW';
            return [
                'character_id'   => $r->character_id,
                'name'           => $names[$r->character_id] ?? ('#' . $r->character_id),
                'entity_type'    => $nonChar[$r->character_id] ?? null,
                'net_position'   => $r->net,
                'compliance_pct' => $r->compliance,
                'flags'          => $flags,
                'is_registered'  => $r->is_registered,
                'alliance_tax_exempt' => $isExempt,
            ];
        })->all();

        return ['available' => true, 'list' => $list, 'total' => count($list), 'source' => $data['source']];
    }

    /**
     * Untaxed-income heatmap: members who EARNED meaningful ISK
     * (ratting + mining) over the window but whose tax compliance is
     * low. The automatic tax-dodger catcher — pairs reported activity
     * against actual tax paid. The single highest-value fraud signal
     * because it's affirmative (they HAVE income, they're NOT paying)
     * rather than just "they're quiet".
     */
    private function untaxedEarners(int $corpId, float $earnedFloor = 100_000_000, float $complianceCeil = 50): array
    {
        $data = $this->memberFinancialRows($corpId);
        if ($data['source'] === 'none') {
            return ['available' => false, 'reason' => 'cwm_columns_missing'];
        }

        // Earned >= floor AND tax compliance below the ceiling. Compliance
        // needs Mining Manager (mining_taxes); rows with null compliance have
        // no tax expectation and are not "dodging", so they're excluded.
        // Alliance-tax-exempt members are excluded too — they ARE paying,
        // just to the alliance, so they're not dodging.
        $exempt = $this->allianceTaxExemptChars();
        $rows = $data['rows']
            ->filter(fn ($r) => $r->compliance !== null && $r->compliance < $complianceCeil && $r->earned >= $earnedFloor && !isset($exempt[$r->character_id]))
            ->sortBy('compliance')
            ->take(15)
            ->values();

        $names = $this->namesFor($rows->pluck('character_id')->all());

        $list = $rows->map(function ($r) use ($names) {
            return [
                'character_id'   => $r->character_id,
                'name'           => $names[$r->character_id] ?? ('#' . $r->character_id),
                'compliance_pct' => (float) $r->compliance,
                'ratting_income' => $r->earned,
                'mining_value'   => 0.0,
                'is_registered'  => $r->is_registered,
            ];
        })->all();

        return ['available' => true, 'list' => $list, 'total' => count($list), 'source' => $data['source']];
    }

    /**
     * Loyalty recognition: members with the strongest positive net
     * position over the window. The counterweight to all the "who's
     * failing" sections — gives directors a "who to thank / promote"
     * surface. Most plugins are purely punitive; this one earns
     * goodwill.
     */
    private function loyaltyStreaks(int $corpId): array
    {
        $data = $this->memberFinancialRows($corpId);
        if ($data['source'] === 'none') {
            return ['available' => false, 'reason' => 'cwm_columns_missing'];
        }

        $rows = $data['rows']
            ->filter(fn ($r) => $r->net > 0)
            ->sortByDesc('net')
            ->take(5)
            ->values();

        $names = $this->namesFor($rows->pluck('character_id')->all());

        $list = $rows->map(fn ($r) => [
            'character_id'   => $r->character_id,
            'name'           => $names[$r->character_id] ?? ('#' . $r->character_id),
            'net_position'   => $r->net,
            'lifetime'       => $r->contribution,
            'active_months'  => $r->active_months,
            'is_registered'  => $r->is_registered,
        ])->all();

        return ['available' => true, 'list' => $list, 'total' => count($list), 'source' => $data['source']];
    }

    /**
     * Corp wallet outflows: where the corp's ISK is going. Consumes the
     * corp-level wallet.getCorpOutflows CWM capability (the one
     * corp-scoped capability HR didn't previously surface). Top
     * recipients + unattributed bucket. Director-tier fraud radar
     * pairing with the per-character Wallet Audit panel.
     */
    private function corpOutflows(int $corpId): array
    {
        $result = app(CrossPluginDataService::class)->getCorpOutflows($corpId, 3);
        if (!($result['available'] ?? false)) {
            return ['available' => false, 'reason' => $result['reason'] ?? 'cwm_absent'];
        }

        $byRecipient = $result['by_recipient'] ?? [];
        // Trim to the top 10 destinations so the card stays scannable.
        $top = array_slice($byRecipient, 0, 10);

        return [
            'available'           => true,
            'top_recipients'      => $top,
            'recipient_count'     => count($byRecipient),
            'unattributed_amount' => (float) ($result['unattributed_amount'] ?? 0),
            'unattributed_count'  => (int) ($result['unattributed_count'] ?? 0),
            'months'              => (int) ($result['months'] ?? 3),
        ];
    }

    /**
     * Members with recent CWM wallet-signal events (last 30 days), across
     * ALL corp members — registered or not. Sourced from HR's own event
     * history (which CWM populates for every member it reports on), so this
     * surfaces the unregistered members the assessment-cache cards above
     * cannot see. The per-member drill-down behind the Wallet signals
     * summary on the Overview tab. Names resolve via NameResolutionService.
     */
    private function corpWalletFlaggedMembers(int $corpId): array
    {
        if (!Schema::hasTable('hr_manager_member_history_events')) {
            return ['available' => false, 'reason' => 'no_history'];
        }

        $events = DB::table('hr_manager_member_history_events')
            ->where('corporation_id', $corpId)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->whereIn('event_type', [
                'wallet_stalled', 'wallet_contribution_drop',
                'wallet_compliance_dropped', 'wallet_unusual_recipient',
            ])
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get(['character_id', 'event_type', 'occurred_at']);

        if ($events->isEmpty()) {
            return ['available' => true, 'list' => [], 'total' => 0];
        }

        // Aggregate per character in PHP (portable — avoids GROUP_CONCAT).
        $byChar = [];
        foreach ($events as $e) {
            $cid = (int) $e->character_id;
            if (!isset($byChar[$cid])) {
                $byChar[$cid] = ['types' => [], 'last_at' => $e->occurred_at, 'count' => 0];
            }
            $byChar[$cid]['types'][$e->event_type] = true;
            $byChar[$cid]['count']++;
            if ($e->occurred_at > $byChar[$cid]['last_at']) {
                $byChar[$cid]['last_at'] = $e->occurred_at;
            }
        }

        $charIds = array_keys($byChar);
        $names = app(NameResolutionService::class)->getCharacterNamesWithFallback($charIds);
        $registered = DB::table('refresh_tokens')
            ->whereIn('character_id', $charIds)
            ->whereNull('deleted_at')
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->toArray();

        $list = [];
        foreach ($byChar as $cid => $agg) {
            $list[] = [
                'character_id'  => $cid,
                'name'          => $names[$cid] ?? ('#' . $cid),
                'is_registered' => isset($registered[$cid]),
                'types'         => array_keys($agg['types']),
                'last_at'       => $agg['last_at'],
                'count'         => $agg['count'],
            ];
        }

        // Most-recently-flagged first.
        usort($list, fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));

        return [
            'available' => true,
            'list'      => array_slice($list, 0, 50),
            'total'     => count($list),
        ];
    }

    /**
     * Batch character_infos name lookup. Shared by every Wallet
     * Insights method so they don't each re-query.
     */
    private function namesFor(array $charIds): array
    {
        if (empty($charIds)) return [];
        // Resolver (character_infos -> universe_names -> ESI) so the corp-wide
        // Wallet Insights rows show names for UNREGISTERED members too, not a
        // bare #id. Returns a #id fallback per the resolver, compatible with
        // the existing `$names[$id] ?? '#'.$id` call sites.
        $names = app(NameResolutionService::class)->getCharacterNamesWithFallback($charIds);

        // The corp-wide roll-up can include NON-character entities (a
        // corporation that moved ISK through the wallet appears as a ~98m id).
        // The character resolver leaves those as '#id'; resolve them as corp /
        // alliance names so the cards aren't littered with bare ids.
        $unresolved = array_values(array_filter(
            array_map('intval', $charIds),
            fn ($id) => $id > 0 && (!isset($names[$id]) || $names[$id] === ('#' . $id))
        ));
        if (!empty($unresolved)) {
            try {
                if (Schema::hasTable('universe_names')) {
                    DB::table('universe_names')
                        ->whereIn('entity_id', $unresolved)
                        ->whereIn('category', ['corporation', 'alliance'])
                        ->get(['entity_id', 'name'])
                        ->each(function ($r) use (&$names) {
                            if (!empty($r->name)) $names[(int) $r->entity_id] = (string) $r->name;
                        });
                }
                if (Schema::hasTable('corporation_infos')) {
                    DB::table('corporation_infos')
                        ->whereIn('corporation_id', $unresolved)
                        ->get(['corporation_id', 'name'])
                        ->each(function ($r) use (&$names) {
                            $id = (int) $r->corporation_id;
                            if (!empty($r->name) && (!isset($names[$id]) || $names[$id] === ('#' . $id))) {
                                $names[$id] = (string) $r->name;
                            }
                        });
                }
            } catch (\Throwable $e) {
                // best-effort; bare #id fallback remains
            }
        }

        return $names;
    }

    /**
     * Which of the given ids are NON-character entities (corporation /
     * alliance) — used by the Wallet Insights cards to badge a corp/alliance
     * row so it's clear it isn't a member. Returns [id => 'corporation'|
     * 'alliance']. Empty when nothing matches.
     */
    private function nonCharacterEntities(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
        if (empty($ids)) return [];
        $out = [];
        try {
            if (Schema::hasTable('universe_names')) {
                DB::table('universe_names')
                    ->whereIn('entity_id', $ids)
                    ->whereIn('category', ['corporation', 'alliance'])
                    ->get(['entity_id', 'category'])
                    ->each(function ($r) use (&$out) {
                        $out[(int) $r->entity_id] = (string) $r->category;
                    });
            }
            // corporation_infos as a second source (catches corps universe_names hasn't cached).
            if (Schema::hasTable('corporation_infos')) {
                $known = array_keys($out);
                $remaining = array_values(array_diff($ids, $known));
                if (!empty($remaining)) {
                    DB::table('corporation_infos')
                        ->whereIn('corporation_id', $remaining)
                        ->pluck('corporation_id')
                        ->each(function ($id) use (&$out) {
                            $out[(int) $id] = 'corporation';
                        });
                }
            }
        } catch (\Throwable $e) {
            // best-effort
        }
        return $out;
    }

    /**
     * Top 5 lifetime contributors in this corp from HR's own cached
     * MemberAssessment table — no cross-plugin call. Honest labeling:
     * this is LIFETIME total, not current month, because the assessment
     * cache only stores lifetime aggregates. For monthly leaderboards
     * operators should click through to CWM.
     */
    private function topContributors(int $corpId): array
    {
        $data = $this->memberFinancialRows($corpId);
        if ($data['source'] === 'none') {
            return ['available' => false, 'reason' => 'cwm_columns_missing'];
        }

        $rows = $data['rows']
            ->filter(fn ($r) => $r->contribution > 0)
            ->sortByDesc('contribution')
            ->take(5)
            ->values();

        $names = $this->namesFor($rows->pluck('character_id')->all());

        $list = $rows->map(fn ($r) => [
            'character_id'  => $r->character_id,
            'name'          => $names[$r->character_id] ?? ('#' . $r->character_id),
            'contributed'   => $r->contribution,
            'is_registered' => $r->is_registered,
        ])->all();

        return [
            'available' => true,
            'list'      => $list,
            'total'     => count($list),
            'source'    => $data['source'],
        ];
    }

    /**
     * "Accepted but never joined" backlog: applicants who were said yes
     * to but never actually showed up in the corporation. Catches
     * ghosting at a glance. Reads the joined_corp_at column populated
     * by hr-manager:detect-corp-joins.
     */
    private function acceptedNotJoined(int $corpId): array
    {
        if (!Schema::hasColumn('hr_manager_applications', 'joined_corp_at')) {
            return ['available' => false, 'reason' => 'migration_pending'];
        }

        $rows = Application::where('corporation_id', $corpId)
            ->where('status', 'accepted')
            ->whereNull('joined_corp_at')
            ->whereNotNull('decided_at')
            ->whereNull('deleted_at')
            ->get(['id', 'character_id', 'decided_at']);

        if ($rows->isEmpty()) {
            return [
                'available'    => true,
                'total'        => 0,
                'late'         => 0,
                'ghosted'      => 0,
                'sample'       => [],
            ];
        }

        $now = now();
        $late = 0;
        $ghosted = 0;
        $sample = [];

        foreach ($rows as $row) {
            $days = (int) $row->decided_at?->diffInDays($now);
            if ($days >= 14) {
                $ghosted++;
            } elseif ($days >= 3) {
                $late++;
            }
            if (count($sample) < 5) {
                $sample[] = [
                    'application_id' => $row->id,
                    'character_id'   => $row->character_id,
                    'days_since'     => $days,
                ];
            }
        }

        return [
            'available' => true,
            'total'     => $rows->count(),
            'late'      => $late,
            'ghosted'   => $ghosted,
            'sample'    => $sample,
        ];
    }

    // -----------------------------------------------------------------
    // Headline overview
    // -----------------------------------------------------------------

    private function overview(int $corpId): array
    {
        $corp = DB::table('corporation_infos')
            ->where('corporation_id', $corpId)
            ->first();

        // Resolve the authoritative roster source — same priority chain
        // as MemberController::index. Pre-fix this read from
        // character_affiliations which only contains chars SeAT has
        // touched, yielding misleading "68.8% registered" stats when
        // ESI sees 199 members but SeAT only tracks 16. Now uses
        // corporation_members when populated by a director-scoped
        // ESI token (read_corporation_membership.v1).
        $rosterSource = $this->resolveRosterSource($corpId);

        // Collapsed: total chars + registered chars + distinct humans in
        // ONE round trip against the authoritative source.
        $counts = DB::table($rosterSource . ' as cm')
            ->leftJoin('refresh_tokens', function ($j) {
                $j->on('cm.character_id', '=', 'refresh_tokens.character_id')
                  ->whereNull('refresh_tokens.deleted_at');
            })
            ->where('cm.corporation_id', $corpId)
            ->selectRaw('
                COUNT(DISTINCT cm.character_id)                                                      as char_count,
                COUNT(DISTINCT CASE WHEN refresh_tokens.character_id IS NOT NULL
                                    THEN cm.character_id END)                                        as registered_count,
                COUNT(DISTINCT refresh_tokens.user_id)                                               as human_count
            ')
            ->first();

        $charCount       = (int) ($counts->char_count ?? 0);
        $registeredCount = (int) ($counts->registered_count ?? 0);
        $humanCount      = (int) ($counts->human_count ?? 0);

        $unregisteredCount = max(0, $charCount - $registeredCount);
        $altsPerHuman = $humanCount > 0 ? round($registeredCount / $humanCount, 2) : 0;
        $registeredPct = $charCount > 0 ? round(($registeredCount / $charCount) * 100, 1) : 0;

        $pendingApps = Application::where('corporation_id', $corpId)
            ->whereIn('status', ['applied', 'under_review', 'interview'])
            ->whereNull('deleted_at')
            ->count();

        // Active vs inactive counts from the classifier (Phase 3 cache)
        $activeCount = PlayerClassification::forCorporation($corpId)
            ->where('category', PlayerClassification::CATEGORY_ACTIVE)
            ->count();
        $concernCount = PlayerClassification::forCorporation($corpId)
            ->whereIn('category', [
                PlayerClassification::CATEGORY_AT_RISK,
                PlayerClassification::CATEGORY_INACTIVE,
                PlayerClassification::CATEGORY_DEAD_WEIGHT,
            ])
            ->count();

        // CEO main char name (if we can find it)
        $ceoName = null;
        if ($corp && $corp->ceo_id) {
            $ceoName = DB::table('character_infos')->where('character_id', $corp->ceo_id)->value('name');
        }

        return [
            'available'         => true,
            'corp_name'         => $corp->name ?? null,
            'ticker'            => $corp->ticker ?? null,
            'ceo_id'            => $corp->ceo_id ?? null,
            'ceo_name'          => $ceoName,
            'alliance_id'       => $corp->alliance_id ?? null,
            'esi_member_count'  => $corp->member_count ?? null, // CCP's count
            'char_count'        => $charCount,
            'registered_count'  => $registeredCount,
            'unregistered_count'=> $unregisteredCount,
            'registered_pct'    => $registeredPct,
            'human_count'       => $humanCount,
            'alts_per_human'    => $altsPerHuman,
            'active_count'      => $activeCount,
            'concern_count'     => $concernCount,
            'pending_apps'      => $pendingApps,
            'roster_source'     => $rosterSource,
            'roster_is_authoritative' => in_array(
                $rosterSource,
                ['corporation_members', 'corporation_member_trackings'],
                true
            ),
        ];
    }

    /**
     * Probe roster tables in priority order. Same chain as
     * MemberController::resolveRosterSource — corporation_members first
     * (full ESI roster), then corporation_member_trackings, then
     * character_affiliations as the sparse last-resort fallback.
     */
    private function resolveRosterSource(int $corporationId): string
    {
        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (Schema::hasTable($table)
                && DB::table($table)->where('corporation_id', $corporationId)->limit(1)->exists()) {
                return $table;
            }
        }
        return 'character_affiliations';
    }

    // -----------------------------------------------------------------
    // Activity tier distribution (from PlayerClassification)
    // -----------------------------------------------------------------

    private function tierDistribution(int $corpId): array
    {
        $rows = PlayerClassification::forCorporation($corpId)
            ->whereNotNull('tier_level')
            ->selectRaw('tier_level, COUNT(*) as c')
            ->groupBy('tier_level')
            ->pluck('c', 'tier_level')
            ->toArray();

        $unmappedCount = PlayerClassification::forCorporation($corpId)
            ->whereNull('tier_level')
            ->count();

        $byTier = [];
        $max = 0;
        foreach (TierLevel::ALL as $level) {
            $count = (int) ($rows[$level] ?? 0);
            $max = max($max, $count);
            $byTier[] = [
                'level'       => $level,
                'short_label' => TierLevel::shortLabel($level),
                'label'       => TierLevel::label($level),
                'badge_class' => TierLevel::badgeClass($level),
                'count'       => $count,
            ];
        }

        return [
            'available'      => true,
            'by_tier'        => $byTier,
            'unmapped_count' => $unmappedCount,
            'max'            => $max,
        ];
    }

    // -----------------------------------------------------------------
    // Last login bucketed (needs corp_member_trackings, populated when a
    // director-scoped token is registered with esi-corporations.track_members.v1)
    // -----------------------------------------------------------------

    private function lastLoginDistribution(int $corpId): array
    {
        if (!Schema::hasTable('corporation_member_trackings')) {
            return ['available' => false, 'reason' => 'no_director_data'];
        }

        $rows = DB::table('corporation_member_trackings')
            ->where('corporation_id', $corpId)
            ->whereNotNull('logon_date')
            ->pluck('logon_date');

        if ($rows->isEmpty()) {
            return ['available' => false, 'reason' => 'no_director_data'];
        }

        $buckets = [
            '24h'     => 0,
            '7d'      => 0,
            '30d'     => 0,
            '60d'     => 0,
            '90d'     => 0,
            'dormant' => 0,
        ];

        foreach ($rows as $logon) {
            try {
                $when = Carbon::parse($logon);
            } catch (\Throwable $e) {
                continue;
            }
            $days = $when->diffInDays(now());
            $hours = $when->diffInHours(now());

            if ($hours < 24) {
                $buckets['24h']++;
            } elseif ($days < 7) {
                $buckets['7d']++;
            } elseif ($days < 30) {
                $buckets['30d']++;
            } elseif ($days < 60) {
                $buckets['60d']++;
            } elseif ($days < 90) {
                $buckets['90d']++;
            } else {
                $buckets['dormant']++;
            }
        }

        return [
            'available' => true,
            'buckets'   => $buckets,
            'total'     => array_sum($buckets),
        ];
    }

    // -----------------------------------------------------------------
    // Net membership trend over the last N days from corp history
    // -----------------------------------------------------------------

    private function membershipTrend(int $corpId, int $days): array
    {
        if (!Schema::hasTable('character_corporation_histories')) {
            return ['available' => false, 'reason' => 'no_history_table'];
        }

        $since = now()->subDays($days)->startOfDay();

        // Joins per day for this corp (start_date in window)
        $joins = DB::table('character_corporation_histories')
            ->where('corporation_id', $corpId)
            ->where('start_date', '>=', $since)
            ->selectRaw('DATE(start_date) as d, COUNT(*) as c')
            ->groupBy(DB::raw('DATE(start_date)'))
            ->pluck('c', 'd')
            ->toArray();

        // Approximate "leaves" by counting history rows for ANY OTHER corp whose
        // start_date is in the window AND whose previous row (in the character's
        // own history) referenced this corp. Skip for v1 — leaving SeAT to
        // compute the cleaner side; show joins only with a note.

        $byDay = [];
        $maxJoins = 0;
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $j = (int) ($joins[$date] ?? 0);
            $maxJoins = max($maxJoins, $j);
            $byDay[] = ['date' => $date, 'joins' => $j];
        }

        return [
            'available'  => true,
            'by_day'     => $byDay,
            'max_joins'  => $maxJoins,
            'total_joins' => array_sum(array_column($byDay, 'joins')),
        ];
    }

    // -----------------------------------------------------------------
    // Director roster (and the more general role_holders summary)
    // -----------------------------------------------------------------

    private function roleRoster(int $corpId, string $role): array
    {
        if (!Schema::hasTable('corporation_roles')) {
            return ['available' => false, 'reason' => 'no_roles_table'];
        }

        // corporation_roles is the director-token table (SeAT's
        // Corporation\Roles job, /corporations/{id}/roles/): it carries every
        // member's character_id + corporation_id + role, so it covers the
        // whole corp, not just registered alts. type='roles' is the base
        // corp-wide grant — Director / Personnel_Manager live there;
        // grantable_* (can grant, doesn't hold) and roles_at_* (structure-
        // scoped) are excluded so we count who actually HOLDS the role.
        $rows = DB::table('corporation_roles')
            ->leftJoin('character_infos', 'corporation_roles.character_id', '=', 'character_infos.character_id')
            ->where('corporation_roles.corporation_id', $corpId)
            ->where('corporation_roles.type', 'roles')
            ->where('corporation_roles.role', $role)
            ->select([
                'corporation_roles.character_id',
                'character_infos.name',
                'character_infos.security_status',
            ])
            ->distinct()
            ->get();

        if ($rows->isEmpty()) {
            return ['available' => false, 'reason' => 'no_role_holders'];
        }

        // Resolve names for every role-holder. corporation_roles covers the
        // whole corp, but character_infos only carries registered alts — so
        // most member ids would otherwise render as a bare #id. The resolver
        // layers character_infos -> universe_names -> a one-shot bulk ESI
        // /universe/names/ resolve (public endpoint, cached back into
        // universe_names so it's free next time). Runs on cache-miss only
        // since the whole tab payload is cached.
        $charIds = $rows->pluck('character_id')->all();
        $resolvedNames = app(NameResolutionService::class)->getCharacterNamesWithFallback($charIds);

        // Decorate with last_logon from member_trackings + classifier category
        $logons = Schema::hasTable('corporation_member_trackings')
            ? DB::table('corporation_member_trackings')
                ->whereIn('character_id', $charIds)
                ->where('corporation_id', $corpId)
                ->pluck('logon_date', 'character_id')
                ->toArray()
            : [];

        // Resolve user_id for each char to look up classifier category
        $charUserMap = DB::table('refresh_tokens')
            ->whereIn('character_id', $charIds)
            ->whereNull('deleted_at')
            ->pluck('user_id', 'character_id')
            ->toArray();

        $userClassifications = !empty($charUserMap)
            ? PlayerClassification::forCorporation($corpId)
                ->whereIn('user_id', array_unique($charUserMap))
                ->pluck('category', 'user_id')
                ->toArray()
            : [];

        $list = $rows->map(function ($row) use ($logons, $charUserMap, $userClassifications, $resolvedNames) {
            $userId = $charUserMap[$row->character_id] ?? null;
            return [
                'character_id'    => (int) $row->character_id,
                'name'            => $resolvedNames[(int) $row->character_id] ?? ($row->name ?? '#' . $row->character_id),
                'security_status' => $row->security_status,
                'last_logon'      => $logons[$row->character_id] ?? null,
                'user_id'         => $userId,
                'classifier'      => $userId ? ($userClassifications[$userId] ?? null) : null,
            ];
        })->all();

        return [
            'available' => true,
            'list'      => $list,
            'count'     => count($list),
        ];
    }

    /**
     * Headcount per in-game corp role. Useful for "we have 5 directors, 12
     * personnel managers" overview without dumping every roster.
     */
    private function roleSummary(int $corpId): array
    {
        if (!Schema::hasTable('corporation_roles')) {
            return ['available' => false, 'reason' => 'no_roles_table'];
        }

        // Director-token table; type='roles' = base corp-wide grants only
        // (so a member who can merely GRANT director isn't counted as one).
        $rows = DB::table('corporation_roles')
            ->where('corporation_id', $corpId)
            ->where('type', 'roles')
            ->selectRaw('role, COUNT(DISTINCT character_id) as c')
            ->groupBy('role')
            ->orderByDesc('c')
            ->get();

        if ($rows->isEmpty()) {
            return ['available' => false, 'reason' => 'no_role_holders'];
        }

        return [
            'available' => true,
            'by_role'   => $rows->map(fn($r) => ['role' => $r->role, 'count' => (int) $r->c])->all(),
        ];
    }

    // -----------------------------------------------------------------
    // Director health — inactivity + SeAT-registration coverage across
    // EVERY director (authed or not). The classifier's is_inactive_director
    // only covers registered users, so an unauthed director who has gone
    // dark is invisible to it; this reads the corp roster directly.
    // -----------------------------------------------------------------

    public function getDirectorHealth(int $corpId): array
    {
        return Cache::remember(
            self::DIRECTOR_HEALTH_CACHE_PREFIX . $corpId,
            self::STATUS_CACHE_TTL,
            fn () => $this->buildDirectorHealth($corpId)
        );
    }

    private function buildDirectorHealth(int $corpId): array
    {
        if (!Schema::hasTable('corporation_roles')) {
            return ['available' => false, 'reason' => 'no_roles_table'];
        }

        // Every director (corp-wide grant), from the director-token table.
        $directorIds = DB::table('corporation_roles')
            ->where('corporation_id', $corpId)
            ->where('type', 'roles')
            ->where('role', 'Director')
            ->distinct()
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($directorIds)) {
            return [
                'available' => true, 'total' => 0, 'threshold_days' => self::DIRECTOR_INACTIVE_DAYS,
                'active_count' => 0, 'inactive' => [], 'inactive_count' => 0,
                'unauthed' => [], 'unauthed_count' => 0, 'unauthed_inactive_count' => 0, 'unknown_count' => 0,
            ];
        }

        // Last logon (director token populates this for ALL members, not just
        // registered ones — that's what lets us catch unauthed dark directors).
        $logons = Schema::hasTable('corporation_member_trackings')
            ? DB::table('corporation_member_trackings')
                ->where('corporation_id', $corpId)
                ->whereIn('character_id', $directorIds)
                ->pluck('logon_date', 'character_id')
                ->toArray()
            : [];

        // Registration: a director is "in SeAT" if a live refresh_token exists.
        $userByChar = DB::table('refresh_tokens')
            ->whereIn('character_id', $directorIds)
            ->whereNull('deleted_at')
            ->pluck('user_id', 'character_id')
            ->toArray();

        $classByUser = !empty($userByChar)
            ? PlayerClassification::forCorporation($corpId)
                ->whereIn('user_id', array_unique(array_values($userByChar)))
                ->pluck('category', 'user_id')
                ->toArray()
            : [];

        $names = app(NameResolutionService::class)->getCharacterNamesWithFallback($directorIds);

        $threshold = self::DIRECTOR_INACTIVE_DAYS;
        $now = now();
        $inactive = [];
        $unauthed = [];
        $activeCount = 0;
        $unknownCount = 0;
        $unauthedInactive = 0;

        foreach ($directorIds as $cid) {
            $userId = isset($userByChar[$cid]) ? (int) $userByChar[$cid] : null;
            $isAuthed = $userId !== null;

            $logon = $logons[$cid] ?? null;
            $days = null;
            if ($logon) {
                try {
                    // abs() guards the Carbon diffInDays sign/return-type drift.
                    $days = (int) abs(\Carbon\Carbon::parse($logon)->diffInDays($now));
                } catch (\Throwable $e) {
                    $days = null;
                }
            }
            $isInactive = $days !== null && $days >= $threshold;

            $entry = [
                'character_id'     => $cid,
                'name'             => $names[$cid] ?? ('#' . $cid),
                'user_id'          => $userId,
                'is_authed'        => $isAuthed,
                'last_logon'       => $logon,
                'days_since_logon' => $days,
                'classifier'       => $isAuthed ? ($classByUser[$userId] ?? null) : null,
            ];

            if ($isInactive) {
                $inactive[] = $entry;
            } elseif ($days !== null) {
                $activeCount++;
            } else {
                $unknownCount++;
            }

            if (!$isAuthed) {
                $unauthed[] = $entry;
                if ($isInactive) {
                    $unauthedInactive++;
                }
            }
        }

        // Inactive: longest-dark first. Unauthed: dark ones first, then the
        // rest (null days sort last).
        usort($inactive, fn ($a, $b) => ($b['days_since_logon'] ?? 0) <=> ($a['days_since_logon'] ?? 0));
        usort($unauthed, fn ($a, $b) => ($b['days_since_logon'] ?? -1) <=> ($a['days_since_logon'] ?? -1));

        return [
            'available'               => true,
            'total'                   => count($directorIds),
            'threshold_days'          => $threshold,
            'active_count'            => $activeCount,
            'inactive'                => $inactive,
            'inactive_count'          => count($inactive),
            'unauthed'                => $unauthed,
            'unauthed_count'          => count($unauthed),
            'unauthed_inactive_count' => $unauthedInactive,
            'unknown_count'           => $unknownCount,
        ];
    }

    // -----------------------------------------------------------------
    // Corp-wide activity — buckets EVERY member by last login, so the
    // dashboard reflects the whole corp instead of only the registered
    // pilots the token-based classifier can see. The classifier stays the
    // authoritative source for registered players (it folds in wallet /
    // mining / skill signals); this is the login-only picture for everyone.
    // -----------------------------------------------------------------

    public function getRosterActivity(int $corpId): array
    {
        return Cache::remember(
            self::ROSTER_ACTIVITY_CACHE_PREFIX . $corpId,
            self::STATUS_CACHE_TTL,
            fn () => $this->buildRosterActivity($corpId)
        );
    }

    private function buildRosterActivity(int $corpId): array
    {
        $rosterSource = $this->resolveRosterSource($corpId);
        if (!Schema::hasTable('corporation_member_trackings')
            || !in_array($rosterSource, ['corporation_members', 'corporation_member_trackings'], true)) {
            // No director-token roster/tracking — can't see unregistered
            // members at all, so a corp-wide view would be misleading.
            return ['available' => false, 'reason' => 'no_director_data'];
        }

        $memberIds = DB::table($rosterSource)
            ->where('corporation_id', $corpId)
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $total = count($memberIds);
        if ($total === 0) {
            return ['available' => false, 'reason' => 'empty_roster'];
        }

        $logons = DB::table('corporation_member_trackings')
            ->where('corporation_id', $corpId)
            ->whereIn('character_id', $memberIds)
            ->pluck('logon_date', 'character_id')
            ->toArray();

        $tokenRows = DB::table('refresh_tokens')
            ->whereIn('character_id', $memberIds)
            ->whereNull('deleted_at')
            ->get(['character_id', 'user_id']);
        $registeredSet = [];
        $charToUser = [];
        foreach ($tokenRows as $tr) {
            $cid = (int) $tr->character_id;
            $registeredSet[$cid] = true;
            $charToUser[$cid] = (int) $tr->user_id;
        }
        $registeredCount = count($registeredSet);

        // Mirror the classifier's Member-tier bands so an unregistered member
        // is bucketed exactly like a registered Member would be on login alone.
        $threshold = (int) (app(\HrManager\Services\TierService::class)
            ->defaultThresholdDays(TierLevel::MEMBER) ?? self::ROSTER_FALLBACK_THRESHOLD);
        if ($threshold < 1) {
            $threshold = self::ROSTER_FALLBACK_THRESHOLD;
        }

        $buckets = ['active' => 0, 'at_risk' => 0, 'inactive' => 0, 'dead_weight' => 0, 'unknown' => 0];
        $flagged = [];
        $now = now();
        foreach ($memberIds as $cid) {
            $logon = $logons[$cid] ?? null;
            if (!$logon) {
                $buckets['unknown']++;
                continue;
            }
            try {
                $days = (int) abs(\Carbon\Carbon::parse($logon)->diffInDays($now));
            } catch (\Throwable $e) {
                $buckets['unknown']++;
                continue;
            }
            // Exact mirror of ClassifierService::categorize (T*2 / T / T/2).
            if ($days >= $threshold * 2) {
                $buckets['dead_weight']++;
                $cat = 'dead_weight';
            } elseif ($days >= $threshold) {
                $buckets['inactive']++;
                $cat = 'inactive';
            } elseif ($days >= $threshold / 2) {
                $buckets['at_risk']++;
                $cat = 'at_risk';
            } else {
                $buckets['active']++;
                continue; // active members aren't part of the at-risk drill-down
            }
            $flagged[] = [
                'character_id' => $cid,
                'days'         => $days,
                'category'     => $cat,
                'logon'        => (string) $logon,
            ];
        }

        // Build the flagged drill-down (at-risk / inactive / dead-weight), most
        // overdue first, capped. Names resolved in bulk (no per-member ESI).
        usort($flagged, fn ($a, $b) => $b['days'] <=> $a['days']);
        $flaggedTotal = count($flagged);
        $flagged = array_slice($flagged, 0, self::ROSTER_FLAGGED_LIST_CAP);
        if (!empty($flagged)) {
            $names = $this->namesFor(array_column($flagged, 'character_id'));
            foreach ($flagged as &$f) {
                $f['name']       = $names[$f['character_id']] ?? ('#' . $f['character_id']);
                $f['registered'] = isset($registeredSet[$f['character_id']]);
                $f['user_id']    = $charToUser[$f['character_id']] ?? null;
            }
            unset($f);
        }

        return [
            'available'      => true,
            'total'          => $total,
            'registered'     => $registeredCount,
            'unregistered'   => max(0, $total - $registeredCount),
            'buckets'        => $buckets,
            'threshold_days' => $threshold,
            'active_days'    => (int) round($threshold / 2),
            'at_risk_days'   => $threshold,
            'inactive_days'  => $threshold * 2,
            'flagged'        => $flagged,
            'flagged_total'  => $flaggedTotal,
        ];
    }

    // -----------------------------------------------------------------
    // Application funnel
    // -----------------------------------------------------------------

    private function applicationFunnel(int $corpId): array
    {
        // Collapsed: 6 separate COUNT queries replaced with one conditional
        // aggregation over the same base filter. Saves 5 round trips and
        // keeps the buffer pool cache hot for the single read.
        $since30 = now()->subDays(30);

        $row = DB::table('hr_manager_applications')
            ->where('corporation_id', $corpId)
            ->whereNull('deleted_at')
            ->selectRaw('
                SUM(CASE WHEN status IN (\'applied\',\'under_review\',\'interview\') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = \'accepted\'  AND decided_at  >= ? THEN 1 ELSE 0 END)            as accepted_30d,
                SUM(CASE WHEN status = \'rejected\'  AND decided_at  >= ? THEN 1 ELSE 0 END)            as rejected_30d,
                SUM(CASE WHEN status = \'withdrawn\' AND updated_at  >= ? THEN 1 ELSE 0 END)            as withdrew_30d,
                SUM(CASE WHEN status = \'accepted\'  THEN 1 ELSE 0 END)                                  as lifetime_accepted,
                SUM(CASE WHEN status = \'rejected\'  THEN 1 ELSE 0 END)                                  as lifetime_rejected
            ', [$since30, $since30, $since30])
            ->first();

        $pending          = (int) ($row->pending ?? 0);
        $accepted30       = (int) ($row->accepted_30d ?? 0);
        $rejected30       = (int) ($row->rejected_30d ?? 0);
        $withdrew30       = (int) ($row->withdrew_30d ?? 0);
        $lifetimeAccepted = (int) ($row->lifetime_accepted ?? 0);
        $lifetimeRejected = (int) ($row->lifetime_rejected ?? 0);

        $totalDecided  = $accepted30 + $rejected30;
        $acceptancePct = $totalDecided > 0 ? round($accepted30 / $totalDecided * 100, 1) : null;

        return [
            'available'         => true,
            'pending'           => $pending,
            'accepted_30d'      => $accepted30,
            'rejected_30d'      => $rejected30,
            'withdrew_30d'      => $withdrew30,
            'acceptance_pct'    => $acceptancePct,
            'lifetime_accepted' => $lifetimeAccepted,
            'lifetime_rejected' => $lifetimeRejected,
        ];
    }

    // -----------------------------------------------------------------
    // Recruitment stats — submission trend, decision mix, throughput
    // (time-to-decision), oldest pending, and who's processing apps.
    // Complements applicationFunnel (which is the 30d in/out snapshot).
    // -----------------------------------------------------------------

    private function recruitmentStats(int $corpId): array
    {
        if (!Schema::hasTable('hr_manager_applications')) {
            return ['available' => false];
        }

        $cols = Schema::getColumnListing('hr_manager_applications');
        $submittedCol = in_array('submitted_at', $cols, true) ? 'submitted_at' : 'created_at';
        $hasDecidedBy = in_array('decided_by', $cols, true);
        $hasDecidedAt = in_array('decided_at', $cols, true);
        $pendingStatuses = ['applied', 'under_review', 'interview'];
        $since30 = now()->subDays(30);
        $since90 = now()->subDays(90);

        $base = fn () => DB::table('hr_manager_applications')
            ->where('corporation_id', $corpId)
            ->whereNull('deleted_at');

        // Submission trend + lifetime decision mix in one aggregate.
        $agg = $base()
            ->selectRaw("
                SUM(CASE WHEN {$submittedCol} >= ? THEN 1 ELSE 0 END) as sub_30d,
                SUM(CASE WHEN {$submittedCol} >= ? THEN 1 ELSE 0 END) as sub_90d,
                COUNT(*) as lifetime,
                SUM(CASE WHEN status = 'accepted'  THEN 1 ELSE 0 END) as accepted,
                SUM(CASE WHEN status = 'rejected'  THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'withdrawn' THEN 1 ELSE 0 END) as withdrawn
            ", [$since30, $since90])
            ->first();

        $accepted  = (int) ($agg->accepted ?? 0);
        $rejected  = (int) ($agg->rejected ?? 0);
        $withdrawn = (int) ($agg->withdrawn ?? 0);
        $resolved  = $accepted + $rejected + $withdrawn;

        // Average days from submission to decision (accepted/rejected, last 90d).
        $avgDecisionDays = null;
        if ($hasDecidedAt) {
            $decidedRows = $base()
                ->whereIn('status', ['accepted', 'rejected'])
                ->whereNotNull('decided_at')
                ->whereNotNull($submittedCol)
                ->where('decided_at', '>=', $since90)
                ->get([$submittedCol . ' as submitted', 'decided_at']);
            $hours = 0;
            $n = 0;
            foreach ($decidedRows as $r) {
                try {
                    $s = \Carbon\Carbon::parse($r->submitted);
                    $d = \Carbon\Carbon::parse($r->decided_at);
                    if ($d->greaterThanOrEqualTo($s)) {
                        $hours += $s->diffInHours($d);
                        $n++;
                    }
                } catch (\Throwable $e) {
                    // skip unparseable row
                }
            }
            if ($n > 0) {
                $avgDecisionDays = round(($hours / $n) / 24, 1);
            }
        }

        // Oldest still-pending application, in days.
        $oldestPendingDays = null;
        $oldest = $base()->whereIn('status', $pendingStatuses)->min($submittedCol);
        if ($oldest) {
            try {
                $oldestPendingDays = (int) \Carbon\Carbon::parse($oldest)->diffInDays(now());
            } catch (\Throwable $e) {
                // leave null
            }
        }

        // Top recruiters by decisions made (last 90d).
        $topRecruiters = [];
        if ($hasDecidedBy && $hasDecidedAt) {
            $rows = $base()
                ->whereNotNull('decided_by')
                ->where('decided_at', '>=', $since90)
                ->selectRaw('decided_by, COUNT(*) as c')
                ->groupBy('decided_by')
                ->orderByDesc('c')
                ->limit(5)
                ->get();
            foreach ($rows as $r) {
                $topRecruiters[] = [
                    'name'  => $this->resolveUserName((int) $r->decided_by),
                    'count' => (int) $r->c,
                ];
            }
        }

        return [
            'available'           => true,
            'sub_30d'             => (int) ($agg->sub_30d ?? 0),
            'sub_90d'             => (int) ($agg->sub_90d ?? 0),
            'lifetime'            => (int) ($agg->lifetime ?? 0),
            'accepted'            => $accepted,
            'rejected'            => $rejected,
            'withdrawn'           => $withdrawn,
            'accept_pct'          => $resolved > 0 ? (int) round($accepted / $resolved * 100) : null,
            'reject_pct'          => $resolved > 0 ? (int) round($rejected / $resolved * 100) : null,
            'withdraw_pct'        => $resolved > 0 ? (int) round($withdrawn / $resolved * 100) : null,
            'avg_decision_days'   => $avgDecisionDays,
            'oldest_pending_days' => $oldestPendingDays,
            'top_recruiters'      => $topRecruiters,
        ];
    }

    private function resolveUserName(int $userId): string
    {
        if (Schema::hasTable('users')) {
            $mainId = DB::table('users')->where('id', $userId)->value('main_character_id');
            if ($mainId && Schema::hasTable('character_infos')) {
                $name = DB::table('character_infos')->where('character_id', $mainId)->value('name');
                if ($name) {
                    return (string) $name;
                }
            }
        }
        return 'User #' . $userId;
    }

    // -----------------------------------------------------------------
    // Character quality aggregates (sec status, SP) — sampled across the
    // registered characters in the corp
    // -----------------------------------------------------------------

    private function characterQuality(int $corpId): array
    {
        // SeAT v5's character_infos table does NOT carry total_sp — the
        // canonical source is the character_skills table summed per char.
        // Some older SeAT versions DO carry total_sp on character_infos,
        // so we schema-probe first to stay compatible with both layouts.
        $hasInfosTotalSp = Schema::hasColumn('character_infos', 'total_sp');

        $select = [
            'character_affiliations.character_id',
            'character_infos.security_status',
        ];
        if ($hasInfosTotalSp) {
            $select[] = 'character_infos.total_sp';
        }

        $rows = DB::table('character_affiliations')
            ->leftJoin('character_infos', 'character_affiliations.character_id', '=', 'character_infos.character_id')
            ->where('character_affiliations.corporation_id', $corpId)
            ->select($select)
            ->get();

        if ($rows->isEmpty()) {
            return ['available' => false];
        }

        $charIds = $rows->pluck('character_id')->all();

        // Canonical SeAT v5 SP source is character_info_skills.total_sp — one
        // aggregate row per character (the same field CharacterInfo->skillpoints
        // exposes, and what the rest of HR reads). Prefer it. Fall back to
        // summing per-skill character_skills rows only on older installs that
        // lack the aggregate table, then to the legacy character_infos.total_sp
        // column. Degrades silently to "-" when no source is available.
        $spByChar = [];
        if (!$hasInfosTotalSp) {
            if (Schema::hasTable('character_info_skills')) {
                try {
                    $spByChar = DB::table('character_info_skills')
                        ->whereIn('character_id', $charIds)
                        ->pluck('total_sp', 'character_id')
                        ->toArray();
                } catch (\Throwable $e) {
                    Log::warning('[HR Manager] CorpStatusService: character_info_skills read failed: ' . $e->getMessage());
                }
            } elseif (Schema::hasTable('character_skills')) {
                try {
                    $skillColumns = Schema::getColumnListing('character_skills');
                    $spColumn = in_array('skillpoints_in_skill', $skillColumns, true)
                        ? 'skillpoints_in_skill'
                        : (in_array('skill_points', $skillColumns, true) ? 'skill_points' : null);

                    if ($spColumn !== null) {
                        $spByChar = DB::table('character_skills')
                            ->whereIn('character_id', $charIds)
                            ->selectRaw("character_id, SUM({$spColumn}) as total_sp")
                            ->groupBy('character_id')
                            ->pluck('total_sp', 'character_id')
                            ->toArray();
                    }
                } catch (\Throwable $e) {
                    Log::warning('[HR Manager] CorpStatusService: character_skills aggregation failed: ' . $e->getMessage());
                }
            }
        }

        $secValues = $rows->pluck('security_status')->filter(fn($v) => $v !== null)->map(fn($v) => (float) $v)->values();

        // Build SP values — prefer the per-char aggregate when present;
        // otherwise fall back to the legacy column on the row.
        $spValues = collect();
        foreach ($rows as $row) {
            $sp = $spByChar[$row->character_id] ?? ($hasInfosTotalSp ? ($row->total_sp ?? null) : null);
            if ($sp !== null && (float) $sp > 0) {
                $spValues->push((float) $sp);
            }
        }
        $spValues = $spValues->values();

        return [
            'available'      => true,
            'sec_count'      => $secValues->count(),
            'sec_avg'        => $secValues->isNotEmpty() ? round($secValues->avg(), 2) : null,
            'sec_min'        => $secValues->isNotEmpty() ? round($secValues->min(), 2) : null,
            'sec_max'        => $secValues->isNotEmpty() ? round($secValues->max(), 2) : null,
            'sec_negative'   => $secValues->filter(fn($v) => $v < 0)->count(),
            'sp_count'       => $spValues->count(),
            'sp_avg'         => $spValues->isNotEmpty() ? (int) $spValues->avg() : null,
            'sp_median'      => $spValues->isNotEmpty() ? (int) $this->median($spValues->all()) : null,
            'sp_total'       => (float) $spValues->sum(),
        ];
    }

    // -----------------------------------------------------------------
    // Recruitment summary (landings + views aggregated)
    // -----------------------------------------------------------------

    private function recruitmentSummary(int $corpId): array
    {
        if (!Schema::hasTable('hr_manager_recruitment_landings')) {
            return ['available' => false];
        }

        $landings = DB::table('hr_manager_recruitment_landings')
            ->where('corporation_id', $corpId)
            ->get(['id', 'is_published', 'view_count', 'application_count']);

        if ($landings->isEmpty()) {
            return ['available' => true, 'has_landings' => false];
        }

        $published = $landings->where('is_published', 1)->count();
        $totalViews = (int) $landings->sum('view_count');
        $totalApps = (int) $landings->sum('application_count');

        $views30 = 0;
        if (Schema::hasTable('hr_manager_recruitment_views')) {
            $views30 = DB::table('hr_manager_recruitment_views')
                ->whereIn('landing_id', $landings->pluck('id'))
                ->where('viewed_at', '>=', now()->subDays(30))
                ->count();
        }

        return [
            'available'        => true,
            'has_landings'     => true,
            'landings_total'   => $landings->count(),
            'landings_published' => $published,
            'lifetime_views'   => $totalViews,
            'lifetime_apps'    => $totalApps,
            'views_30d'        => $views30,
        ];
    }

    // -----------------------------------------------------------------
    // Wallet corp aggregates (sum of per-character CWM lifetime totals
    // already cached on member assessments)
    // -----------------------------------------------------------------

    private function walletCorpTotals(int $corpId): array
    {
        // Defensive guard: CWM Round-2 aggregate columns landed in commit
        // 40ce808, but installs that ran the original v1.0.0 migration
        // before that commit lack the columns on disk. The forward-only
        // 2026_06_01_000002 migration adds them, but until it runs we
        // must not query columns that don't exist.
        if (!Schema::hasColumn('hr_manager_member_assessments', 'lifetime_contribution')) {
            return ['available' => false, 'reason' => 'cwm_columns_missing'];
        }

        $rows = MemberAssessment::where('corporation_id', $corpId)
            ->whereNotNull('lifetime_contribution')
            ->get([
                'lifetime_contribution',
                'net_position_6mo',
                'wallet_compliance_pct_6mo',
            ]);

        if ($rows->isEmpty()) {
            return ['available' => false, 'reason' => 'no_wallet_aggregates'];
        }

        $compliances = $rows->pluck('wallet_compliance_pct_6mo')->filter(fn($v) => $v !== null)->map(fn($v) => (float) $v);

        return [
            'available'              => true,
            'sample_size'            => $rows->count(),
            'lifetime_contribution'  => (float) $rows->sum('lifetime_contribution'),
            'net_position_6mo'       => (float) $rows->sum('net_position_6mo'),
            'avg_compliance_pct'     => $compliances->isNotEmpty() ? round($compliances->avg(), 1) : null,
            'low_compliance_count'   => $compliances->filter(fn($v) => $v < 50)->count(),
        ];
    }

    // -----------------------------------------------------------------

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) return 0;
        $mid = (int) floor($n / 2);
        if ($n % 2 === 1) {
            return (float) $values[$mid];
        }
        return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
    }
}

<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\PlayerClassification;
use HrManager\Models\PlayerStatus;
use HrManager\Support\TierLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Computes player activity classifications and detects transitions.
 *
 * Algorithm per (user, corp):
 *   1. Resolve tier via TierService (highest-wins across mapped roles)
 *   2. Compute days_inactive = now - max(last_activity across alts)
 *   3. If PlayerStatus.status='loa' AND LOA is currently in effect -> skip,
 *      stay 'active' (sanctioned absence)
 *   4. Categorize against threshold:
 *      - active:      days_inactive < threshold * 0.5
 *      - at_risk:     threshold * 0.5 <= days_inactive < threshold
 *      - inactive:    threshold <= days_inactive < threshold * 2
 *      - dead_weight: days_inactive >= threshold * 2
 *   5. CWM wallet-signal layer (added v1.x, optional — degrades to no-op
 *      when MC / CWM are absent). Fetches contribution trend, activity
 *      gaps, net position, tax compliance and lifetime aggregate via
 *      CrossPluginDataService → MC PluginBridge → CWM, then may step the
 *      category *down* (active → at_risk → inactive) when wallet signals
 *      diverge from login-only activity. A "loyalty modifier" can also
 *      hold a borderline at_risk back at active when lifetime contribution
 *      is large and the recent trend is still positive. Each wallet
 *      signal that fires is recorded as a flag and republished as a
 *      dedicated hr.player.flagged_wallet_* event on transition.
 *   5b. Blueprint engagement layer (added v1.x, optional — no-op when MC /
 *      Blueprint Manager absent). A parallel POSITIVE modifier: recent corp
 *      blueprint sourcing holds a borderline at_risk player back at active
 *      (a builder who's demonstrably engaged), mirroring the loyalty
 *      modifier. Only applies to at_risk — never rescues inactive/dead_weight.
 *      Recorded as a 'blueprint_hold' flag (no event, like loyalty_hold).
 *   5c. Buyback engagement layer (added v1.x, optional — no-op when MC /
 *      Buyback Manager absent). Same shape as 5b: a recent COUNTED buyback
 *      contribution (one the per-corp policy credits — personal / uncounted
 *      buyback never rescues) holds a borderline at_risk player at active.
 *      at_risk only; recorded as a 'buyback_hold' flag (no event).
 *   6. Director special case: any L3 player in 'inactive' or 'dead_weight'
 *      raises is_inactive_director=true (corp survival depends on them).
 *      Wallet-director check: an L3 with zero director attribution rows
 *      against the corp wallet is escalated via the same critical-alert
 *      pathway as the inactive-director case.
 *
 * Transition detection: compare new classification with prior persisted row;
 * publish hr.player.flagged_* events on category changes.
 */
class ClassifierService
{
    private TierService $tier;
    private PlayerService $player;
    private HistoryEventService $history;
    private NotificationService $notifications;
    private CrossPluginDataService $crossPlugin;

    public function __construct(
        TierService $tier,
        PlayerService $player,
        HistoryEventService $history,
        NotificationService $notifications,
        CrossPluginDataService $crossPlugin
    ) {
        $this->tier = $tier;
        $this->player = $player;
        $this->history = $history;
        $this->notifications = $notifications;
        $this->crossPlugin = $crossPlugin;
    }

    /**
     * Classify every user in the given corp. Returns counts by category for
     * use in cron output / Corp Health summary.
     *
     * @return array{active:int, at_risk:int, inactive:int, dead_weight:int, inactive_directors:int}
     */
    public function classifyCorporation(int $corporationId): array
    {
        $userIds = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('character_affiliations.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->distinct()
            ->pluck('refresh_tokens.user_id');

        $counts = [
            PlayerClassification::CATEGORY_ACTIVE      => 0,
            PlayerClassification::CATEGORY_AT_RISK     => 0,
            PlayerClassification::CATEGORY_INACTIVE    => 0,
            PlayerClassification::CATEGORY_DEAD_WEIGHT => 0,
            'inactive_directors'                       => 0,
        ];

        foreach ($userIds as $userId) {
            $classification = $this->classifyPlayer((int) $userId, $corporationId);
            if (!$classification) {
                continue;
            }
            $counts[$classification->category]++;
            if ($classification->is_inactive_director) {
                $counts['inactive_directors']++;
            }
        }

        // Bust the CorpStatusService cache so the Corp Health page reflects
        // the new category counts immediately (without waiting for the
        // 5-min TTL to expire).
        try {
            app(CorpStatusService::class)->bustCache($corporationId);
        } catch (\Throwable $e) {
            // Cache busting is best-effort; classifier output is authoritative
            // regardless. Don't fail the run on cache infra hiccups.
        }

        return $counts;
    }

    /**
     * Classify one (user, corp). Persists the classification row + detects
     * transition vs prior persisted state + publishes events on change.
     */
    public function classifyPlayer(int $userId, int $corporationId): ?PlayerClassification
    {
        $tierData = $this->tier->resolveTier($userId, $corporationId);
        $tierLevel = $tierData ? $tierData['level'] : null;
        $thresholdDays = $tierData['threshold_days'] ?? null;

        // LOA suppression — sanctioned absence stays "active" for classifier
        $status = PlayerStatus::where('user_id', $userId)
            ->where('corporation_id', $corporationId)
            ->first();
        $loaActive = $status && $status->isLoaActive();

        // Activity signal: max last_activity across the player's alts
        $summary = $this->player->getPlayerSummary($userId, $corporationId);
        $lastActivity = $summary['last_activity_at'];

        $daysInactive = $lastActivity
            ? max(0, (int) $lastActivity->diffInDays(now()))
            : 9999; // never any activity = treat as very inactive

        $baseCategory = $this->categorize($daysInactive, $thresholdDays, $loaActive);

        // CWM wallet-signal pass — purely additive, no-op when MC/CWM absent.
        // LOA suppression still wins (sanctioned absence shouldn't be flagged
        // for wallet stalling). Applicants (no threshold) also skip — they
        // aren't expected to be contributing yet.
        $walletFlags = [];
        $category = $baseCategory;
        if (!$loaActive && $thresholdDays && $summary['characters']->isNotEmpty()) {
            [$category, $walletFlags] = $this->applyWalletSignals(
                $userId,
                $corporationId,
                $summary['characters'],
                $baseCategory,
                $tierLevel
            );
        }

        // Blueprint engagement pass — a POSITIVE signal (Blueprint Manager
        // via MC). A member actively sourcing blueprints from the corp
        // library is engaged in industry; recent engagement holds a
        // borderline at_risk player back at active, mirroring the wallet
        // loyalty modifier. Only runs when the category is at_risk, so it
        // never rescues inactive/dead_weight (a stale request months ago
        // doesn't undo a long absence). No-op when BP/MC absent.
        if (!$loaActive && $thresholdDays
            && $category === PlayerClassification::CATEGORY_AT_RISK
            && $summary['characters']->isNotEmpty()) {
            [$category, $blueprintFlags] = $this->applyBlueprintSignal(
                $corporationId,
                $summary['characters'],
                $category,
                $thresholdDays
            );
            // Fold the engagement flag into the persisted "why" flag set so
            // Corp Health can render the badge. emitWalletFlagEvents ignores
            // it (not in its event map), exactly like loyalty_hold.
            $walletFlags = array_merge($walletFlags, $blueprintFlags);
        }

        // Buyback engagement pass — a POSITIVE signal (Buyback Manager via MC).
        // A member with a recent COUNTED buyback contribution is engaged in the
        // corp economy; like the blueprint modifier, recent engagement holds a
        // borderline at_risk player at active. Personal / uncounted buyback
        // never rescues (the per-corp policy decides what counts). Re-checks
        // at_risk so it never undoes a long absence or redoes a blueprint hold.
        if (!$loaActive && $thresholdDays
            && $category === PlayerClassification::CATEGORY_AT_RISK
            && $summary['characters']->isNotEmpty()) {
            [$category, $buybackFlags] = $this->applyBuybackSignal(
                $corporationId,
                $summary['characters'],
                $category,
                $thresholdDays
            );
            $walletFlags = array_merge($walletFlags, $buybackFlags);
        }

        // Director attribution is its own pathway — runs independently of
        // category mutation so an active director who's gone quiet on
        // wallet movement still surfaces. We compute the flag here so it
        // can ride alongside the inactive-director critical alert.
        $silentWalletDirector = false;
        if ($tierLevel === TierLevel::DIRECTOR && $summary['characters']->isNotEmpty()) {
            $silentWalletDirector = $this->detectSilentWalletDirector($corporationId, $summary['characters']);
            if ($silentWalletDirector) {
                $walletFlags['silent_wallet_director'] = true;
            }
        }

        $isInactiveDirector = $tierLevel === TierLevel::DIRECTOR
            && in_array($category, [PlayerClassification::CATEGORY_INACTIVE, PlayerClassification::CATEGORY_DEAD_WEIGHT], true);

        // Detect transition vs prior persisted state
        $prior = PlayerClassification::where('user_id', $userId)
            ->where('corporation_id', $corporationId)
            ->first();

        $writeData = [
            'tier_level'           => $tierLevel,
            'category'             => $category,
            'is_inactive_director' => $isInactiveDirector,
            'days_inactive'        => $daysInactive,
            'threshold_days'       => $thresholdDays,
            'last_activity_at'     => $lastActivity,
            // Persist the wallet flag keys that contributed to this
            // category decision so Corp Health can render per-row
            // "why" badges without re-running the classifier.
            'wallet_flags'         => array_values(array_keys(array_filter($walletFlags))),
            'classified_at'        => now(),
        ];

        // Drop wallet_flags from the write if the column is missing on
        // disk (stale install pre-2026_06_01_000002 migration).
        if (!\Illuminate\Support\Facades\Schema::hasColumn('hr_manager_player_classifications', 'wallet_flags')) {
            unset($writeData['wallet_flags']);
        }

        $classification = PlayerClassification::updateOrCreate(
            ['user_id' => $userId, 'corporation_id' => $corporationId],
            $writeData
        );

        if ($prior && $prior->category !== $category) {
            $this->onCategoryTransition($userId, $corporationId, $prior->category, $category, $classification, $walletFlags);
        }

        // Wallet-specific events fire on first occurrence of each flag,
        // independent of category-transition events. Idempotency is per-day:
        // we record a history event keyed on (event_name, user, corp, date)
        // so a player who stays "stalled" across many cron runs only emits
        // once per day. The history service is the source of truth for
        // dedup — publish via EventBus when the history row is fresh.
        $this->emitWalletFlagEvents($userId, $corporationId, $classification, $walletFlags);

        // Inactive director alert fires on first detection (not on every cron run).
        // Use a sticky idempotency via history events keyed on classification id + date.
        if ($isInactiveDirector && (!$prior || !$prior->is_inactive_director)) {
            $this->onInactiveDirectorRaised($userId, $corporationId, $classification);
        }

        // Silent-wallet-director rides the same critical-alert pathway as
        // inactive-director — both indicate "corp survival risk", just from
        // different angles (one stopped logging in, the other stopped
        // touching the wallet). Fires once per occurrence (idempotency via
        // history event dedup inside emitWalletFlagEvents).
        if ($silentWalletDirector) {
            $this->onSilentWalletDirectorRaised($userId, $corporationId, $classification);
        }

        return $classification;
    }

    /**
     * Map (days_inactive, threshold) -> category. LOA-suppressed players
     * always come back as 'active'.
     */
    private function categorize(int $daysInactive, ?int $thresholdDays, bool $loaActive): string
    {
        if ($loaActive) {
            return PlayerClassification::CATEGORY_ACTIVE;
        }
        if (!$thresholdDays) {
            return PlayerClassification::CATEGORY_ACTIVE; // unmapped / applicant — no expectation
        }
        if ($daysInactive >= $thresholdDays * 2) {
            return PlayerClassification::CATEGORY_DEAD_WEIGHT;
        }
        if ($daysInactive >= $thresholdDays) {
            return PlayerClassification::CATEGORY_INACTIVE;
        }
        if ($daysInactive >= $thresholdDays / 2) {
            return PlayerClassification::CATEGORY_AT_RISK;
        }
        return PlayerClassification::CATEGORY_ACTIVE;
    }

    private function onCategoryTransition(int $userId, int $corporationId, string $from, string $to, PlayerClassification $now, array $walletFlags = []): void
    {
        $eventName = match ($to) {
            PlayerClassification::CATEGORY_AT_RISK     => 'hr.player.flagged_at_risk',
            PlayerClassification::CATEGORY_INACTIVE    => 'hr.player.flagged_inactive',
            PlayerClassification::CATEGORY_DEAD_WEIGHT => 'hr.player.flagged_dead_weight',
            PlayerClassification::CATEGORY_ACTIVE      => 'hr.player.recovered',
            default                                    => 'hr.player.classification_changed',
        };

        $payload = [
            'source_plugin'   => 'hr-manager',
            'schema_version'  => 1,
            'event_id'        => 'hr-evt-' . Str::uuid()->toString(),
            'user_id'         => $userId,
            'corporation_id'  => $corporationId,
            'old_category'    => $from,
            'new_category'    => $to,
            'days_inactive'   => $now->days_inactive,
            'threshold_days'  => $now->threshold_days,
            'tier_level'      => $now->tier_level,
            'wallet_flags'    => array_keys(array_filter($walletFlags)),
        ];

        $this->history->record('hr.player.classification_changed', $payload, [
            'user_id'        => $userId,
            'corporation_id' => $corporationId,
            'occurred_at'    => now(),
        ]);

        $this->publishToEventBus($eventName, $payload);
    }

    private function onInactiveDirectorRaised(int $userId, int $corporationId, PlayerClassification $now): void
    {
        $payload = [
            'source_plugin'   => 'hr-manager',
            'schema_version'  => 1,
            'event_id'        => 'hr-evt-' . Str::uuid()->toString(),
            'user_id'         => $userId,
            'corporation_id'  => $corporationId,
            'days_inactive'   => $now->days_inactive,
            'threshold_days'  => $now->threshold_days,
        ];

        $this->history->record('hr.player.inactive_director', $payload, [
            'user_id'        => $userId,
            'corporation_id' => $corporationId,
            'occurred_at'    => now(),
        ]);

        $this->publishToEventBus('hr.player.inactive_director', $payload);

        try {
            $this->notifications->notifyInactiveDirector($userId, $corporationId, $now);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] notifyInactiveDirector failed: ' . $e->getMessage());
        }
    }

    /**
     * Fires the same critical-alert pathway as onInactiveDirectorRaised but
     * for a director who's still logging in (so doesn't trip the days_inactive
     * threshold) yet has zero attributed wallet actions. CWM's director
     * attribution surfaces this — corp survival risk shows up here as
     * "director is functionally absent from corp finances".
     */
    private function onSilentWalletDirectorRaised(int $userId, int $corporationId, PlayerClassification $now): void
    {
        $payload = [
            'source_plugin'   => 'hr-manager',
            'schema_version'  => 1,
            'event_id'        => 'hr-evt-' . Str::uuid()->toString(),
            'user_id'         => $userId,
            'corporation_id'  => $corporationId,
            'days_inactive'   => $now->days_inactive,
            'threshold_days'  => $now->threshold_days,
        ];

        // Idempotency: only publish + notify once per day (history dedup on
        // event name + entity + date). Otherwise this would re-fire every
        // cron run while the director stays silent on the wallet.
        $alreadyToday = $this->historyEventRecordedToday(
            'hr.player.silent_wallet_director',
            $userId,
            $corporationId
        );
        if ($alreadyToday) {
            return;
        }

        $this->history->record('hr.player.silent_wallet_director', $payload, [
            'user_id'        => $userId,
            'corporation_id' => $corporationId,
            'occurred_at'    => now(),
        ]);

        $this->publishToEventBus('hr.player.silent_wallet_director', $payload);

        try {
            $this->notifications->notifyInactiveDirector($userId, $corporationId, $now);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] notifyInactiveDirector (silent_wallet_director) failed: ' . $e->getMessage());
        }
    }

    /**
     * Apply CWM wallet signals on top of the base login-derived category.
     *
     * Signal weighting (additive — only pushes a category *down* toward
     * at_risk/inactive, never up to active, except for the loyalty
     * modifier which can hold a borderline at_risk back at active):
     *
     *   - Contribution stalled (last_active_period > 2 months ago OR
     *     longest_gap_months >= 2) → 'stalled' flag → push to at_risk
     *   - Net position negative over 6 months (withdrew more than contributed)
     *     → 'negative_contribution' flag → push to at_risk
     *   - Tax compliance below 50% with non-zero total_owed → 'compliance_low'
     *     flag → push to at_risk; below 30% → push to inactive
     *   - Lifetime > 5B AND recent trend slope > 0 → 'loyalty' flag → hold
     *     at active even if other signals were borderline
     *
     * Each flag is recorded so emitWalletFlagEvents can publish a dedicated
     * hr.player.flagged_wallet_* event when the player transitions.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int,\Seat\Eveapi\Models\Character\CharacterInfo>  $characters
     * @return array{0:string, 1:array<string,bool>}  [adjusted category, fired flags]
     */
    private function applyWalletSignals(
        int $userId,
        int $corporationId,
        $characters,
        string $baseCategory,
        ?int $tierLevel
    ): array {
        $flags = [];
        $category = $baseCategory;

        // Gather per-alt signals and aggregate at the player level. CWM
        // capabilities are character-scoped, but Corp Health is player-level
        // — so we collapse alts into one set of booleans / sums.
        $stalled               = false;
        $negativeContribution  = false;
        $taxCompliancePct      = null;   // worst-case across alts (lowest)
        $taxTotalOwed          = 0;
        $lifetimeContributed   = 0;
        $recentSlope           = null;   // best-case across alts (highest)
        $cwmReachable          = false;

        foreach ($characters as $char) {
            $characterId = (int) ($char->character_id ?? 0);
            if ($characterId <= 0) {
                continue;
            }

            // Activity gaps — push to at_risk when last contribution period
            // is > 2 months old, or the longest gap of the window is >= 2.
            $gaps = $this->crossPlugin->getCharacterActivityGaps($characterId, $corporationId, 12);
            if ($gaps['available'] ?? false) {
                $cwmReachable = true;
                $gapData = $gaps['data'] ?? null;
                if ($gapData !== null) {
                    $lastPeriod   = $this->fieldVal($gapData, 'last_active_period');
                    $longestGap   = (int) ($this->fieldVal($gapData, 'longest_gap_months') ?? 0);
                    $lastActiveAt = $lastPeriod ? $this->safeParse($lastPeriod) : null;

                    $monthsSinceContribution = $lastActiveAt
                        ? $lastActiveAt->diffInMonths(now())
                        : null;

                    if (($monthsSinceContribution !== null && $monthsSinceContribution > 2)
                        || $longestGap >= 2) {
                        $stalled = true;
                    }
                }
            }

            // Net position — withdrew more than contributed over the window?
            $net = $this->crossPlugin->getCharacterNetPosition($characterId, $corporationId, 6);
            if ($net['available'] ?? false) {
                $cwmReachable = true;
                $netData = $net['data'] ?? null;
                if ($netData !== null) {
                    $isNetPositive = $this->fieldVal($netData, 'is_net_positive');
                    $netAmount     = $this->fieldVal($netData, 'net_amount');
                    // CWM publishes is_net_positive as authoritative; fall
                    // back to net_amount sign when the flag is missing.
                    if ($isNetPositive === false
                        || ($isNetPositive === null && $netAmount !== null && $netAmount < 0)) {
                        $negativeContribution = true;
                    }
                }
            }

            // Tax compliance — MM tax pay-rate. Skipped when the underlying
            // signal is null (MM not installed). available=true with
            // data=null means "no MM" — score as no-signal, not zero.
            $tax = $this->crossPlugin->getCharacterTaxCompliance($characterId, $corporationId, 6);
            if ($tax['available'] ?? false) {
                $cwmReachable = true;
                $taxData = $tax['data'] ?? null;
                if ($taxData !== null) {
                    $compliancePct = $this->fieldVal($taxData, 'compliance_pct');
                    $owed = (float) ($this->fieldVal($taxData, 'total_owed') ?? 0);
                    if ($compliancePct !== null && $owed > 0) {
                        if ($taxCompliancePct === null || $compliancePct < $taxCompliancePct) {
                            $taxCompliancePct = (float) $compliancePct;
                        }
                        $taxTotalOwed += $owed;
                    }
                }
            }

            // Lifetime aggregate — used for loyalty modifier.
            $lifetime = $this->crossPlugin->getCharacterLifetimeSummary($characterId, $corporationId);
            if ($lifetime['available'] ?? false) {
                $cwmReachable = true;
                $lifeData = $lifetime['data'] ?? null;
                if ($lifeData !== null) {
                    $lifetimeContributed += (float) ($this->fieldVal($lifeData, 'lifetime_total_contributed') ?? 0);
                }
            }

            // Trend slope — best across alts. Positive slope = trending up.
            $trend = $this->crossPlugin->getCharacterContributionTrend($characterId, $corporationId, 6);
            if ($trend['available'] ?? false) {
                $cwmReachable = true;
                $trendData = $trend['data'] ?? null;
                if ($trendData !== null) {
                    $slope = $this->fieldVal($trendData, 'slope');
                    if ($slope !== null) {
                        $slope = (float) $slope;
                        if ($recentSlope === null || $slope > $recentSlope) {
                            $recentSlope = $slope;
                        }
                    }
                }
            }
        }

        // If CWM never answered for any alt, nothing to score — return base
        // unchanged. Preserves the "no behavior change when CWM absent"
        // contract.
        if (!$cwmReachable) {
            return [$category, []];
        }

        // Compose the flags into category nudges.
        if ($stalled) {
            $flags['stalled'] = true;
            $category = $this->stepCategoryDown($category, PlayerClassification::CATEGORY_AT_RISK);
        }

        if ($negativeContribution) {
            $flags['negative_contribution'] = true;
            $category = $this->stepCategoryDown($category, PlayerClassification::CATEGORY_AT_RISK);
        }

        if ($taxCompliancePct !== null && $taxTotalOwed > 0) {
            if ($taxCompliancePct < 30) {
                $flags['compliance_low'] = true;
                $flags['compliance_very_low'] = true;
                $category = $this->stepCategoryDown($category, PlayerClassification::CATEGORY_INACTIVE);
            } elseif ($taxCompliancePct < 50) {
                $flags['compliance_low'] = true;
                $category = $this->stepCategoryDown($category, PlayerClassification::CATEGORY_AT_RISK);
            }
        }

        // Loyalty modifier — strong historical contributor still trending
        // up gets held back from at_risk. Only applies when category just
        // tipped to at_risk (we never override inactive/dead_weight; a
        // long-time contributor who's gone silent for months is still a
        // problem even if their lifetime number is huge).
        if ($category === PlayerClassification::CATEGORY_AT_RISK
            && $lifetimeContributed >= 5_000_000_000
            && $recentSlope !== null && $recentSlope > 0) {
            $flags['loyalty_hold'] = true;
            $category = PlayerClassification::CATEGORY_ACTIVE;
        }

        return [$category, $flags];
    }

    /**
     * Blueprint engagement modifier (Blueprint Manager via MC). Recent
     * blueprint sourcing holds a borderline at_risk player back at active —
     * they're demonstrably engaged in industry even if login/wallet signals
     * are soft. Mirrors the wallet loyalty_hold: caller only invokes this
     * when the category is at_risk, so it never rescues inactive/dead_weight.
     *
     * "Engaged" = at least one FULFILLED corp blueprint (a real builder, not
     * just a pending request) AND a request within the tier's activity
     * window. Blueprint requests are SeAT-side and not part of the
     * login-derived last_activity signal, so a recent one is genuinely
     * additional evidence the member is around. No-op when BP/MC is absent
     * or the player has no requests.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int,\Seat\Eveapi\Models\Character\CharacterInfo>  $characters
     * @return array{0:string, 1:array<string,bool>}  [adjusted category, fired flags]
     */
    private function applyBlueprintSignal(int $corporationId, $characters, string $category, int $thresholdDays): array
    {
        $flags = [];

        $characterIds = $characters->pluck('character_id')->map(fn ($id) => (int) $id)->filter()->all();
        if (empty($characterIds)) {
            return [$category, $flags];
        }

        try {
            $bp = app(BlueprintActivityService::class)->getForPlayer($characterIds, $corporationId);
        } catch (\Throwable $e) {
            Log::info('[HR Manager] classifier blueprint signal failed: ' . $e->getMessage());
            return [$category, $flags];
        }

        // BP/MC absent, or installed but the player has no requests — no signal.
        if (!($bp['available'] ?? false) || !($bp['has_data'] ?? false)) {
            return [$category, $flags];
        }

        $fulfilled   = (int) ($bp['fulfilled'] ?? 0);
        $lastRequest = $this->safeParse($bp['last_request'] ?? null);

        if ($fulfilled >= 1
            && $lastRequest !== null
            && $lastRequest->diffInDays(now()) <= $thresholdDays) {
            $flags['blueprint_hold'] = true;
            $category = PlayerClassification::CATEGORY_ACTIVE;
        }

        return [$category, $flags];
    }

    /**
     * Buyback engagement signal (Buyback Manager via MC). A recent COUNTED
     * buyback contribution (one the per-corp policy credits) holds a borderline
     * at_risk player at active, mirroring the blueprint modifier. No-op when
     * BB/MC absent, the player never used buyback, or all their buyback is
     * personal / uncounted.
     */
    private function applyBuybackSignal(int $corporationId, $characters, string $category, int $thresholdDays): array
    {
        $flags = [];

        $characterIds = $characters->pluck('character_id')->map(fn ($id) => (int) $id)->filter()->all();
        if (empty($characterIds)) {
            return [$category, $flags];
        }

        try {
            $bb = app(BuybackContributionService::class)->recentContribution($characterIds, $thresholdDays);
        } catch (\Throwable $e) {
            Log::info('[HR Manager] classifier buyback signal failed: ' . $e->getMessage());
            return [$category, $flags];
        }

        if (!($bb['available'] ?? false) || !($bb['has_recent'] ?? false)) {
            return [$category, $flags];
        }

        $flags['buyback_hold'] = true;
        $category = PlayerClassification::CATEGORY_ACTIVE;

        return [$category, $flags];
    }

    /**
     * Detect a director who is in the corp + logging in (so doesn't trip
     * the inactive-director path) yet has zero director-attributed wallet
     * actions over the trailing window. Operators flagged this as the
     * "silent wallet director" concern — they're around but functionally
     * absent from corp finances. Returns true iff CWM reached an answer
     * AND the player has zero attributed rows.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int,\Seat\Eveapi\Models\Character\CharacterInfo>  $characters
     */
    private function detectSilentWalletDirector(int $corporationId, $characters): bool
    {
        $attribution = $this->crossPlugin->getDirectorAttribution($corporationId, 3, 50_000_000);
        if (!($attribution['available'] ?? false)) {
            return false;
        }
        $rows = $attribution['data'] ?? null;
        if ($rows === null) {
            return false;
        }
        // CWM may return either a flat array of rows, or a wrapper with
        // ['rows' => [...]] / ['attributions' => [...]]. Normalize.
        if (is_object($rows)) {
            $rows = (array) $rows;
        }
        if (isset($rows['rows']) && is_array($rows['rows'])) {
            $rows = $rows['rows'];
        } elseif (isset($rows['attributions']) && is_array($rows['attributions'])) {
            $rows = $rows['attributions'];
        }
        if (!is_array($rows)) {
            return false;
        }

        $playerCharacterIds = $characters->pluck('character_id')->map(fn($id) => (int) $id)->all();
        if (empty($playerCharacterIds)) {
            return false;
        }

        foreach ($rows as $row) {
            $rowCharId = (int) ($this->fieldVal($row, 'character_id')
                ?? $this->fieldVal($row, 'attributed_character_id')
                ?? 0);
            if ($rowCharId > 0 && in_array($rowCharId, $playerCharacterIds, true)) {
                // Director has at least one attributed action — not silent.
                return false;
            }
        }

        return true;
    }

    /**
     * Step a category down toward the target severity. Never up.
     * Order: active < at_risk < inactive < dead_weight.
     */
    private function stepCategoryDown(string $current, string $target): string
    {
        $rank = [
            PlayerClassification::CATEGORY_ACTIVE      => 0,
            PlayerClassification::CATEGORY_AT_RISK     => 1,
            PlayerClassification::CATEGORY_INACTIVE    => 2,
            PlayerClassification::CATEGORY_DEAD_WEIGHT => 3,
        ];
        $currentRank = $rank[$current] ?? 0;
        $targetRank  = $rank[$target] ?? 0;
        return $targetRank > $currentRank ? $target : $current;
    }

    /**
     * Publish hr.player.flagged_wallet_* events for each wallet flag that
     * just newly fired. Dedup is per (user, corp, day) via the history
     * service — a player who stays "stalled" across many cron runs only
     * emits the event once per day.
     *
     * @param  array<string,bool>  $flags
     */
    private function emitWalletFlagEvents(
        int $userId,
        int $corporationId,
        PlayerClassification $classification,
        array $flags
    ): void {
        if (empty($flags)) {
            return;
        }

        $eventMap = [
            'stalled'               => 'hr.player.flagged_wallet_stalled',
            'compliance_low'        => 'hr.player.flagged_wallet_compliance_low',
            'negative_contribution' => 'hr.player.flagged_negative_contribution',
        ];

        foreach ($eventMap as $flagKey => $eventName) {
            if (empty($flags[$flagKey])) {
                continue;
            }
            if ($this->historyEventRecordedToday($eventName, $userId, $corporationId)) {
                continue;
            }

            $payload = [
                'source_plugin'   => 'hr-manager',
                'schema_version'  => 1,
                'event_id'        => 'hr-evt-' . Str::uuid()->toString(),
                'user_id'         => $userId,
                'corporation_id'  => $corporationId,
                'category'        => $classification->category,
                'tier_level'      => $classification->tier_level,
                'days_inactive'   => $classification->days_inactive,
                'threshold_days'  => $classification->threshold_days,
                'flag'            => $flagKey,
            ];

            $this->history->record($eventName, $payload, [
                'user_id'        => $userId,
                'corporation_id' => $corporationId,
                'occurred_at'    => now(),
            ]);
            $this->publishToEventBus($eventName, $payload);
        }
    }

    /**
     * Check whether the given history event has already been recorded today
     * for (user, corp). Used to dedup wallet-flag events so they fire once
     * per day at most, not every cron run.
     */
    private function historyEventRecordedToday(string $eventName, int $userId, int $corporationId): bool
    {
        try {
            return DB::table('hr_manager_member_history_events')
                ->where('event_type', $eventName)
                ->where('user_id', $userId)
                ->where('corporation_id', $corporationId)
                ->where('occurred_at', '>=', now()->startOfDay())
                ->exists();
        } catch (\Throwable $e) {
            // If the table has drifted, fall safe to "not recorded" — would
            // rather re-emit than silently swallow.
            Log::warning('[HR Manager] history dedup check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Defensive accessor for CWM bridge return shapes. PluginBridge may
     * surface a capability's return as stdClass, associative array, or a
     * mix depending on serialization path — read both forms with one call.
     */
    private function fieldVal($obj, string $key)
    {
        if (is_array($obj)) {
            return $obj[$key] ?? null;
        }
        if (is_object($obj)) {
            return $obj->{$key} ?? null;
        }
        return null;
    }

    /**
     * Parse a date-like value (Carbon, string, or scalar) into a Carbon
     * instance or null on failure. Tolerant — CWM may serialize a Carbon
     * as ISO string, as a {date, timezone_type, timezone} array, or as a
     * UNIX timestamp depending on the bridge transport.
     */
    private function safeParse($value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }
        try {
            if (is_array($value) && isset($value['date'])) {
                return Carbon::parse($value['date']);
            }
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp((int) $value);
            }
            return Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function publishToEventBus(string $eventName, array $payload): void
    {
        // Topics::publish is the canonical publish path (registry validation +
        // idempotency-template composition + sanitization). No-ops cleanly
        // when MC is absent.
        if (!class_exists('\\ManagerCore\\Topics')) {
            return;
        }
        // These classifier transitions recur (a player can recover then
        // re-flag), so their registry idempotency templates carry
        // :{detected_at}. Inject it here so every classifier event composes a
        // unique key per transition instead of being deduped forever.
        if (!isset($payload['detected_at'])) {
            $payload['detected_at'] = now()->toIso8601String();
        }
        try {
            \ManagerCore\Topics::publish($eventName, $payload);
        } catch (\Throwable $e) {
            Log::warning("[HR Manager] Topics publish failed for {$eventName}: " . $e->getMessage());
        }
    }
}

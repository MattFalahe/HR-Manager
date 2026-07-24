<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\PlayerClassification;
use HrManager\Models\PlayerStatus;
use HrManager\Models\Setting;
use HrManager\Support\TierLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
    /** A character idle longer than this many days counts as a dormant alt. */
    private const DORMANT_ALT_DAYS = 90;

    /**
     * Minimum dormant alts before an otherwise-active account is flagged as
     * "active but carrying dormant alts" (informational; never reclassifies).
     */
    private const DORMANT_ALT_MIN = 2;

    private TierService $tier;
    private PlayerService $player;
    private HistoryEventService $history;
    private NotificationService $notifications;
    private CrossPluginDataService $crossPlugin;

    /**
     * Quiet mode: persist classifications but emit NO transition / flag events
     * (no webhooks, no history-event spam). Set for the one-time init pass so
     * establishing the baseline over a whole roster doesn't blast operators'
     * webhooks; the nightly runs then notify only on genuinely new changes.
     */
    public bool $quiet = false;

    public function setQuiet(bool $quiet = true): self
    {
        $this->quiet = $quiet;
        return $this;
    }

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

        // A registered corp member with no resolved tier (no Discord-role
        // mapping — e.g. SeAT Connector isn't installed) is still a Member.
        // Measure them against the Member (L0) default threshold so Corp Health
        // produces real Active / At Risk / Inactive / Dead Weight buckets that
        // match the login-based "Corp-wide activity" panel, instead of parking
        // everyone at 'active' for lack of an expectation. Only fills the gap
        // when nothing else resolved — a real mapped tier always wins.
        if ($thresholdDays === null) {
            $tierLevel     = TierLevel::MEMBER;
            $thresholdDays = $this->tier->defaultThresholdDays(TierLevel::MEMBER);
        }

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

        // "Active but carrying dormant alts" annotation. Chosen representation:
        // a flag on the existing ACTIVE row, NOT a 5th category — so the counts,
        // events and Corp Health panels are unchanged. Purely informational: it
        // never moves the category. Counts characters idle > DORMANT_ALT_DAYS
        // (or with no activity signal at all); when an otherwise-active account
        // is carrying multiple, the flag lets a director see the account is
        // alive on its main but lugging unused alts.
        if ($category === PlayerClassification::CATEGORY_ACTIVE) {
            $dormant = 0;
            foreach (($summary['alt_summaries'] ?? []) as $alt) {
                $la = $alt['last_activity_at'] ?? null;
                if ($la === null || $la->diffInDays(now()) > self::DORMANT_ALT_DAYS) {
                    $dormant++;
                }
            }
            if ($dormant >= self::DORMANT_ALT_MIN) {
                $walletFlags['dormant_alts'] = true;
            }
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

        // A director is only "inactive" once they've actually been dark PAST
        // their threshold. The category alone is not enough: the wallet-signal
        // pass above can nudge a time-ACTIVE director into inactive/dead_weight,
        // which used to raise a "[CRITICAL] Director X is inactive for 0 days
        // (threshold 14d)" alert — nonsense, and it re-fired whenever that
        // wallet signal flickered on and off.
        $isInactiveDirector = $tierLevel === TierLevel::DIRECTOR
            && $thresholdDays !== null
            && $daysInactive >= $thresholdDays
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

        // Clear the inactive-director ping stamp once a director is no longer
        // flagged, so a later relapse alerts fresh instead of staying muted by a
        // stale timestamp. While they ARE flagged we leave it alone — the
        // notifier below owns that column.
        if (!$isInactiveDirector && Schema::hasColumn('hr_manager_player_classifications', 'inactive_director_notified_at')) {
            $writeData['inactive_director_notified_at'] = null;
        }

        $classification = PlayerClassification::updateOrCreate(
            ['user_id' => $userId, 'corporation_id' => $corporationId],
            $writeData
        );

        // Quiet mode (init baseline pass) persists the classification above but
        // emits nothing, so seeding a whole roster for the first time doesn't
        // blast webhooks. Nightly runs (quiet=false) then notify only on real
        // transitions away from this baseline.
        if (!$this->quiet) {
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

            // Inactive director alert: first detection, then re-remind on the
            // operator's chosen cadence for as long as they stay dark.
            if ($isInactiveDirector) {
                $this->maybeNotifyInactiveDirector($userId, $corporationId, $classification, $prior);
            }

            // Silent-wallet-director rides the same critical-alert pathway as
            // inactive-director — both indicate "corp survival risk", just from
            // different angles (one stopped logging in, the other stopped
            // touching the wallet). Fires once per occurrence (idempotency via
            // history event dedup inside emitWalletFlagEvents).
            if ($silentWalletDirector) {
                $this->onSilentWalletDirectorRaised($userId, $corporationId, $classification);
            }
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
        // Per-day, per-target dedup: never emit the SAME transition (into the
        // same category) for a player more than once a day. The classifier runs
        // nightly, but a manual "Run Now" — or fluctuating CWM wallet signals
        // flip-flopping a borderline player between buckets across re-runs —
        // could otherwise re-fire the same event. Covers both the EventBus event
        // and the dead-weight webhook below, so nothing spams about one change.
        if ($this->transitionAlreadyEmittedToday($userId, $corporationId, $to)) {
            return;
        }

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

        // Webhook heads-up when a member drops into dead weight — fired once here
        // on the transition (this method only runs when the category actually
        // changed). A dead-weight DIRECTOR is covered by the more specific
        // inactive-director critical alert raised separately, so skip the generic
        // ping for them to avoid a double notification.
        if ($to === PlayerClassification::CATEGORY_DEAD_WEIGHT && !$now->is_inactive_director) {
            try {
                $this->notifications->notifyDeadWeight($userId, $corporationId, $now);
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] notifyDeadWeight failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Setting key for how often the inactive-director alert repeats while the
     * director stays dark. 0 = once per episode (the historic behaviour);
     * otherwise re-remind every N days (1 / 3 / 7 / 14 / 30).
     */
    public const SETTING_DIRECTOR_REPEAT_DAYS = 'inactive_director_repeat_days';

    /**
     * Alert on the first detection, then repeat on the operator's cadence for
     * as long as the director remains flagged. Without a cadence this stays
     * once-per-episode, exactly as before.
     */
    private function maybeNotifyInactiveDirector(int $userId, int $corporationId, PlayerClassification $now, ?PlayerClassification $prior): void
    {
        $firstDetection = !$prior || !$prior->is_inactive_director;
        $hasStamp = Schema::hasColumn('hr_manager_player_classifications', 'inactive_director_notified_at');

        // Stale install without the tracking column: preserve the old
        // fire-once-on-transition behaviour rather than risk repeat spam.
        if (!$hasStamp) {
            if ($firstDetection) {
                $this->onInactiveDirectorRaised($userId, $corporationId, $now);
            }
            return;
        }

        $repeatDays   = (int) Setting::getValue(self::SETTING_DIRECTOR_REPEAT_DAYS, 0);
        $lastNotified = $now->inactive_director_notified_at;

        // Fire on the transition into "dark", then only on the cadence. With
        // cadence 0 (default) this is strictly once per episode — so upgrading
        // never dumps a catch-up burst for directors already flagged.
        $due = $firstDetection
            || ($repeatDays > 0 && (!$lastNotified || $lastNotified->lte(now()->subDays($repeatDays))));

        if (!$due) {
            return;
        }

        $this->onInactiveDirectorRaised($userId, $corporationId, $now);

        $now->forceFill(['inactive_director_notified_at' => now()])->save();
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
     *   - Contribution stalled — the account's FRESHEST contribution across
     *     all alts is > 2 months old → 'stalled' flag → push to at_risk. A
     *     dormant alt with no contribution record never trips this on its own.
     *   - Net position negative over 6 months — the account's SUMMED net across
     *     alts is negative (a main's positive net offsets an alt's withdrawals)
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

        // Gather per-alt signals and aggregate at the ACCOUNT level. CWM
        // capabilities are character-scoped, but classification is per-account
        // — and contributions can legitimately come from ANY character on the
        // account. So negative signals are weighed holistically (is the account
        // AS A WHOLE stalled / net-negative?) rather than firing whenever a
        // single alt trips; otherwise a dormant cyno / mining alt drags an
        // active, contributing main down to at_risk.
        $mostRecentContribMonths = null; // freshest contribution across alts (min months-since)
        $sawContribSignal        = false;
        $netSum                  = 0.0;  // summed net ISK across alts (a main's + offsets an alt's)
        $sawNetAmount            = false;
        $netBoolTally            = 0;    // coarse fallback when CWM gives only is_net_positive
        $sawNetBool              = false;
        $taxCompliancePct        = null; // worst-case across alts (lowest)
        $taxTotalOwed            = 0;
        $lifetimeContributed     = 0;
        $recentSlope             = null; // best-case across alts (highest)
        $cwmReachable            = false;

        foreach ($characters as $char) {
            $characterId = (int) ($char->character_id ?? 0);
            if ($characterId <= 0) {
                continue;
            }

            // Activity gaps — track the account's FRESHEST contribution across
            // alts. A character with no contribution record adds no signal (it
            // isn't a stall); the account counts as stalled only if even its
            // most recent contributor has gone quiet for > 2 months.
            $gaps = $this->crossPlugin->getCharacterActivityGaps($characterId, $corporationId, 12);
            if ($gaps['available'] ?? false) {
                $cwmReachable = true;
                $gapData = $gaps['data'] ?? null;
                if ($gapData !== null) {
                    $lastPeriod   = $this->fieldVal($gapData, 'last_active_period');
                    $lastActiveAt = $lastPeriod ? $this->safeParse($lastPeriod) : null;
                    if ($lastActiveAt !== null) {
                        $sawContribSignal = true;
                        $months = (int) $lastActiveAt->diffInMonths(now());
                        if ($mostRecentContribMonths === null || $months < $mostRecentContribMonths) {
                            $mostRecentContribMonths = $months;
                        }
                    }
                }
            }

            // Net position — SUM across alts so a main's positive net offsets an
            // alt's withdrawals. The account is "negative" only if the whole
            // account withdrew more than it contributed over the window.
            $net = $this->crossPlugin->getCharacterNetPosition($characterId, $corporationId, 6);
            if ($net['available'] ?? false) {
                $cwmReachable = true;
                $netData = $net['data'] ?? null;
                if ($netData !== null) {
                    $netAmount     = $this->fieldVal($netData, 'net_amount');
                    $isNetPositive = $this->fieldVal($netData, 'is_net_positive');
                    if ($netAmount !== null && is_numeric($netAmount)) {
                        $sawNetAmount = true;
                        $netSum += (float) $netAmount;
                    } elseif ($isNetPositive !== null) {
                        // No signed amount from CWM — keep a ± tally as a coarse
                        // account fallback (a positive alt still offsets a
                        // negative one), used only if no alt gave an amount.
                        $sawNetBool = true;
                        $netBoolTally += $isNetPositive ? 1 : -1;
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

        // Compose the flags into category nudges — evaluated on the ACCOUNT
        // aggregate, not per-alt.
        if ($sawContribSignal && $mostRecentContribMonths !== null && $mostRecentContribMonths > 2) {
            $flags['stalled'] = true;
            $category = $this->stepCategoryDown($category, PlayerClassification::CATEGORY_AT_RISK);
        }

        $accountNetNegative = $sawNetAmount ? ($netSum < 0) : ($sawNetBool && $netBoolTally < 0);
        if ($accountNetNegative) {
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
    /**
     * Has a classification transition INTO $toCategory already been recorded for
     * this player today? Reads the classification_changed history rows (payload
     * carries new_category) so the dedup needs no schema change and no change to
     * the recorded event type. Fails safe to "not emitted" so a real transition
     * is never silently dropped if the table drifts.
     */
    private function transitionAlreadyEmittedToday(int $userId, int $corporationId, string $toCategory): bool
    {
        try {
            $payloads = DB::table('hr_manager_member_history_events')
                ->where('event_type', 'hr.player.classification_changed')
                ->where('user_id', $userId)
                ->where('corporation_id', $corporationId)
                ->where('occurred_at', '>=', now()->startOfDay())
                ->pluck('payload');

            foreach ($payloads as $payload) {
                $decoded = is_array($payload) ? $payload : json_decode((string) $payload, true);
                if (is_array($decoded) && ($decoded['new_category'] ?? null) === $toCategory) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] transition dedup check failed: ' . $e->getMessage());
        }

        return false;
    }

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

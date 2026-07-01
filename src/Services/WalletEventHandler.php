<?php

namespace HrManager\Services;

use HrManager\Models\MemberAssessment;
use HrManager\Models\PlayerClassification;
use HrManager\Models\Setting;
use HrManager\Models\WalletAlertState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MC EventBus subscriber for CWM contribution + tax-compliance signals.
 *
 * Wired in HrManagerServiceProvider::registerPluginBridgeCapabilities() to
 * three persistent subscriptions:
 *
 *   - member.contribution.stalled           -> handleStalled()
 *   - member.contribution.milestone         -> handleMilestone()
 *   - member.tax.compliance_dropped         -> handleComplianceDropped()
 *
 * Each handler is idempotent (history events are deduped on a stable
 * source_reference key by HistoryEventService::record). All cross-plugin
 * data arrives via the EventBus payload only. This service does NOT import
 * any CWM classes and does NOT know the publisher's identity beyond the
 * publisher string MC passes through; the array shape of the payload is
 * the only contract.
 *
 * Failure policy mirrors ApplicationService::safelyNotify: catch + log,
 * never throw, so a subscriber failure cannot poison the EventBus
 * dispatcher or hide upstream publishes.
 */
class WalletEventHandler
{
    /**
     * "Recovery gap" (hours) that separates one alert episode from the next. If a
     * character's recurring wallet condition has been quiet — no same-type event
     * refreshing its throttle state — for at least this long, the next event is
     * treated as a NEW episode (the member recovered and then dropped anew) and
     * alerts regardless of the repeat setting. It just has to be comfortably
     * LONGER than CWM's re-publish cadence so an ongoing condition never looks
     * like it recovered mid-episode. Not the same as the operator's repeat
     * cadence (see the `wallet_alert_repeat_hours` setting), which governs
     * re-reminders WITHIN an episode.
     */
    private const WALLET_RECOVERY_GAP_HOURS = 72;

    /**
     * Handle a `member.contribution.stalled` event.
     *
     * Payload shape:
     *   corporation_id, character_id, months_analyzed, longest_gap_months,
     *   last_active_period, detected_at
     *
     * Records a 'wallet_stalled' history event, busts the assessment cache
     * and nudges the player's classification from 'active' to 'at_risk' so
     * the next Corp Health view surfaces them without waiting for the
     * nightly cron.
     */
    public function handleStalled(array $payload): ?array
    {
        $characterId = $this->intOrNull($payload['character_id'] ?? null);
        $corporationId = $this->intOrNull($payload['corporation_id'] ?? null);

        if ($characterId === null || $corporationId === null) {
            Log::warning('[HR Manager] WalletEventHandler::handleStalled rejected malformed payload', [
                'has_character_id'   => isset($payload['character_id']),
                'has_corporation_id' => isset($payload['corporation_id']),
            ]);
            return null;
        }

        try {
            $reason = sprintf(
                'Wallet contributions stalled: longest gap %s months across %s months analyzed (last active: %s).',
                $payload['longest_gap_months'] ?? '?',
                $payload['months_analyzed'] ?? '?',
                $payload['last_active_period'] ?? 'unknown'
            );

            $historyEvent = app(HistoryEventService::class)->record(
                'wallet_stalled',
                array_merge($payload, ['severity' => 'warning', 'reason' => $reason]),
                [
                    'character_id'    => $characterId,
                    'corporation_id'  => $corporationId,
                    'occurred_at'     => $payload['detected_at'] ?? now(),
                    'source_plugin'   => 'corp-wallet-manager',
                    'idempotency_key' => $this->idempotencyKey('wallet_stalled', $payload),
                ]
            );

            $this->bustAssessmentCache($characterId, $corporationId);
            $assessmentUpdated = $this->nudgeToAtRisk($characterId, $corporationId, $reason);

            // Same throttle as handleComplianceDropped (per-webhook toggle:
            // notify_wallet_stalled).
            if ($this->shouldSendWalletAlert('wallet_stalled', $characterId, $corporationId)) {
                $this->dispatchNotification(fn() => app(NotificationService::class)
                    ->notifyWalletStalled($characterId, $corporationId, $reason, $payload));
            }

            Log::info('[HR Manager] Wallet stalled event handled', [
                'character_id'        => $characterId,
                'corporation_id'      => $corporationId,
                'history_event_id'    => $historyEvent?->id,
                'assessment_nudged'   => $assessmentUpdated,
            ]);

            return [
                'handled'                    => true,
                'inserted_history_event_id'  => $historyEvent?->id,
                'assessment_updated'         => $assessmentUpdated,
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] WalletEventHandler::handleStalled failed: ' . $e->getMessage(), [
                'character_id'   => $characterId,
                'corporation_id' => $corporationId,
            ]);
            return [
                'handled'                    => false,
                'inserted_history_event_id'  => null,
                'assessment_updated'         => false,
            ];
        }
    }

    /**
     * Handle a `member.contribution.milestone` event.
     *
     * Payload shape:
     *   corporation_id, character_id, milestone_isk, lifetime_total,
     *   months_active, detected_at
     *
     * Pure positive history: records a 'wallet_milestone' event but does
     * NOT bust the assessment cache or touch the classification. Milestones
     * never downgrade a player.
     */
    public function handleMilestone(array $payload): ?array
    {
        $characterId = $this->intOrNull($payload['character_id'] ?? null);
        $corporationId = $this->intOrNull($payload['corporation_id'] ?? null);

        if ($characterId === null || $corporationId === null) {
            Log::warning('[HR Manager] WalletEventHandler::handleMilestone rejected malformed payload', [
                'has_character_id'   => isset($payload['character_id']),
                'has_corporation_id' => isset($payload['corporation_id']),
            ]);
            return null;
        }

        try {
            // Dedup on the milestone VALUE, not the detection time: a milestone is
            // crossed once, and CWM may re-publish it (with a fresh detected_at) on
            // later cycles. Keying on milestone_isk collapses those into one history
            // row — and, below, one publish + one notification.
            $milestoneKey = sprintf(
                'cwm:wallet_milestone:%s:%s:%s',
                $corporationId,
                $characterId,
                $payload['milestone_isk'] ?? ($payload['detected_at'] ?? '')
            );

            $historyEvent = app(HistoryEventService::class)->record(
                'wallet_milestone',
                array_merge($payload, ['severity' => 'info']),
                [
                    'character_id'    => $characterId,
                    'corporation_id'  => $corporationId,
                    'occurred_at'     => $payload['detected_at'] ?? now(),
                    'source_plugin'   => 'corp-wallet-manager',
                    'idempotency_key' => $milestoneKey,
                ]
            );

            // Only on a genuinely new milestone (fresh history row, not an
            // idempotent replay): publish the downstream event AND notify. Keeps a
            // re-published milestone from firing twice.
            if ($historyEvent !== null) {
                // SeAT Broadcast can render "1B contributor club" pings etc.
                $this->publishMilestoneEvent($characterId, $corporationId, $payload);

                // Per-webhook toggle: notify_wallet_milestone.
                $this->dispatchNotification(fn() => app(NotificationService::class)
                    ->notifyWalletMilestone($characterId, $corporationId, $payload));
            }

            Log::info('[HR Manager] Wallet milestone event handled', [
                'character_id'     => $characterId,
                'corporation_id'   => $corporationId,
                'history_event_id' => $historyEvent?->id,
                'milestone_isk'    => $payload['milestone_isk'] ?? null,
            ]);

            return [
                'handled'                    => true,
                'inserted_history_event_id'  => $historyEvent?->id,
                'assessment_updated'         => false,
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] WalletEventHandler::handleMilestone failed: ' . $e->getMessage(), [
                'character_id'   => $characterId,
                'corporation_id' => $corporationId,
            ]);
            return [
                'handled'                    => false,
                'inserted_history_event_id'  => null,
                'assessment_updated'         => false,
            ];
        }
    }

    /**
     * Handle a `member.tax.compliance_dropped` event.
     *
     * Payload shape:
     *   corporation_id, character_id, compliance_pct, consecutive_overdue,
     *   total_owed, total_paid, floor_pct, detected_at
     *
     * The strongest CWM signal HR cares about. Records a critical-severity
     * 'wallet_compliance_dropped' history event, busts the assessment cache
     * and nudges the classification from 'active' to 'at_risk'.
     */
    public function handleComplianceDropped(array $payload): ?array
    {
        $characterId = $this->intOrNull($payload['character_id'] ?? null);
        $corporationId = $this->intOrNull($payload['corporation_id'] ?? null);

        if ($characterId === null || $corporationId === null) {
            Log::warning('[HR Manager] WalletEventHandler::handleComplianceDropped rejected malformed payload', [
                'has_character_id'   => isset($payload['character_id']),
                'has_corporation_id' => isset($payload['corporation_id']),
            ]);
            return null;
        }

        try {
            $reason = sprintf(
                'Tax compliance dropped to %s%% (floor %s%%): %s consecutive overdue cycles, owed %s ISK, paid %s ISK.',
                $payload['compliance_pct'] ?? '?',
                $payload['floor_pct'] ?? '?',
                $payload['consecutive_overdue'] ?? '?',
                $this->formatIsk($payload['total_owed'] ?? null),
                $this->formatIsk($payload['total_paid'] ?? null)
            );

            $historyEvent = app(HistoryEventService::class)->record(
                'wallet_compliance_dropped',
                array_merge($payload, ['severity' => 'critical', 'reason' => $reason]),
                [
                    'character_id'    => $characterId,
                    'corporation_id'  => $corporationId,
                    'occurred_at'     => $payload['detected_at'] ?? now(),
                    'source_plugin'   => 'corp-wallet-manager',
                    'idempotency_key' => $this->idempotencyKey('wallet_compliance_dropped', $payload),
                ]
            );

            $this->bustAssessmentCache($characterId, $corporationId);
            $assessmentUpdated = $this->nudgeToAtRisk($characterId, $corporationId, $reason);

            // Throttle the Discord ping (operator setting: wallet_alert_repeat_hours,
            // under Settings -> Webhooks): once per episode by default, or on the
            // chosen repeat cadence. Stops CWM's per-cycle re-publish of the same
            // standing "dropped" level from re-pinging every cycle. (Per-webhook
            // toggle: notify_wallet_compliance_dropped.)
            if ($this->shouldSendWalletAlert('wallet_compliance_dropped', $characterId, $corporationId)) {
                $this->dispatchNotification(fn() => app(NotificationService::class)
                    ->notifyWalletComplianceDropped($characterId, $corporationId, $reason, $payload));
            }

            Log::info('[HR Manager] Wallet compliance-dropped event handled', [
                'character_id'      => $characterId,
                'corporation_id'    => $corporationId,
                'history_event_id'  => $historyEvent?->id,
                'compliance_pct'    => $payload['compliance_pct'] ?? null,
                'assessment_nudged' => $assessmentUpdated,
            ]);

            return [
                'handled'                    => true,
                'inserted_history_event_id'  => $historyEvent?->id,
                'assessment_updated'         => $assessmentUpdated,
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] WalletEventHandler::handleComplianceDropped failed: ' . $e->getMessage(), [
                'character_id'   => $characterId,
                'corporation_id' => $corporationId,
            ]);
            return [
                'handled'                    => false,
                'inserted_history_event_id'  => null,
                'assessment_updated'         => false,
            ];
        }
    }

    /**
     * Handle a `member.contribution.drop_detected` event.
     *
     * Distinct from `stalled` — CWM raises this when a character's recent
     * contribution average drops sharply vs their prior baseline, rather
     * than going to zero. Useful for catching a contributor slowing down
     * before they fully stall. Records a 'wallet_contribution_drop'
     * history event and nudges the player to at_risk.
     *
     * Payload shape (best-effort, CWM may add fields over time):
     *   character_id, corporation_id, prior_avg, recent_avg, drop_pct,
     *   detected_at
     */
    public function handleContributionDropDetected(array $payload): ?array
    {
        $characterId = $this->intOrNull($payload['character_id'] ?? null);
        $corporationId = $this->intOrNull($payload['corporation_id'] ?? null);

        if ($characterId === null || $corporationId === null) {
            Log::warning('[HR Manager] WalletEventHandler::handleContributionDropDetected rejected malformed payload', [
                'has_character_id'   => isset($payload['character_id']),
                'has_corporation_id' => isset($payload['corporation_id']),
            ]);
            return null;
        }

        try {
            $reason = sprintf(
                'Contribution drop detected: recent avg %s vs prior %s (%.0f%% drop).',
                $this->formatIsk($payload['recent_avg'] ?? null),
                $this->formatIsk($payload['prior_avg'] ?? null),
                (float) ($payload['drop_pct'] ?? 0)
            );

            $historyEvent = app(HistoryEventService::class)->record(
                'wallet_contribution_drop',
                array_merge($payload, ['severity' => 'warning', 'reason' => $reason]),
                [
                    'character_id'    => $characterId,
                    'corporation_id'  => $corporationId,
                    'occurred_at'     => $payload['detected_at'] ?? now(),
                    'source_plugin'   => 'corp-wallet-manager',
                    'idempotency_key' => $this->idempotencyKey('wallet_contribution_drop', $payload),
                ]
            );

            $this->bustAssessmentCache($characterId, $corporationId);
            $assessmentUpdated = $this->nudgeToAtRisk($characterId, $corporationId, $reason);

            Log::info('[HR Manager] Wallet contribution-drop event handled', [
                'character_id'      => $characterId,
                'corporation_id'    => $corporationId,
                'history_event_id'  => $historyEvent?->id,
                'drop_pct'          => $payload['drop_pct'] ?? null,
                'assessment_nudged' => $assessmentUpdated,
            ]);

            return [
                'handled'                   => true,
                'inserted_history_event_id' => $historyEvent?->id,
                'assessment_updated'        => $assessmentUpdated,
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] WalletEventHandler::handleContributionDropDetected failed: ' . $e->getMessage(), [
                'character_id'   => $characterId,
                'corporation_id' => $corporationId,
            ]);
            return [
                'handled'                   => false,
                'inserted_history_event_id' => null,
                'assessment_updated'        => false,
            ];
        }
    }

    /**
     * Handle a `wallet.unusual_recipient_detected` event.
     *
     * Corp-level anomaly: an unusual external recipient received ISK from
     * the corp wallet. Not character-specific — recorded as a corp-scoped
     * history event so directors can see the audit trail in the timeline.
     * Doesn't nudge any classification (not a per-member signal).
     *
     * Payload shape (best-effort):
     *   corporation_id, recipient_id, recipient_name, amount,
     *   division_name, ref_type, detected_at
     */
    public function handleUnusualRecipient(array $payload): ?array
    {
        $corporationId = $this->intOrNull($payload['corporation_id'] ?? null);

        if ($corporationId === null) {
            Log::warning('[HR Manager] WalletEventHandler::handleUnusualRecipient rejected malformed payload', [
                'has_corporation_id' => isset($payload['corporation_id']),
            ]);
            return null;
        }

        try {
            $reason = sprintf(
                'Unusual recipient: %s (%s ISK from %s).',
                $payload['recipient_name'] ?? ('#' . ($payload['recipient_id'] ?? '?')),
                $this->formatIsk($payload['amount'] ?? null),
                $payload['division_name'] ?? 'unknown division'
            );

            $historyEvent = app(HistoryEventService::class)->record(
                'wallet_unusual_recipient',
                array_merge($payload, ['severity' => 'warning', 'reason' => $reason]),
                [
                    // No character_id — corp-scoped history event.
                    'corporation_id'  => $corporationId,
                    'occurred_at'     => $payload['detected_at'] ?? now(),
                    'source_plugin'   => 'corp-wallet-manager',
                    'idempotency_key' => $this->idempotencyKey('wallet_unusual_recipient', $payload),
                ]
            );

            Log::info('[HR Manager] Wallet unusual-recipient event handled', [
                'corporation_id'   => $corporationId,
                'history_event_id' => $historyEvent?->id,
                'amount'           => $payload['amount'] ?? null,
                'recipient_id'     => $payload['recipient_id'] ?? null,
            ]);

            return [
                'handled'                   => true,
                'inserted_history_event_id' => $historyEvent?->id,
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] WalletEventHandler::handleUnusualRecipient failed: ' . $e->getMessage(), [
                'corporation_id' => $corporationId,
            ]);
            return [
                'handled'                   => false,
                'inserted_history_event_id' => null,
            ];
        }
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    /**
     * Null the cached_at timestamp on the affected assessment so the next
     * read forces AssessmentService::buildAssessment to recompute with the
     * fresh CWM signal folded in.
     */
    private function bustAssessmentCache(int $characterId, int $corporationId): void
    {
        MemberAssessment::where('character_id', $characterId)
            ->where('corporation_id', $corporationId)
            ->update(['cached_at' => null]);
    }

    /**
     * Promote the player's classification from 'active' to 'at_risk' so the
     * Corp Health page surfaces them immediately instead of waiting for the
     * nightly classifier run.
     *
     * Only touches rows in 'active' — 'inactive' and 'dead_weight' are HR's
     * own downgrade states and must be left alone (a wallet stall on a
     * player who's been gone three months should not silently demote them
     * back up the ladder).
     *
     * Returns true when a row was actually flipped. The classifier may run
     * later and reconsider on its own merits.
     */
    private function nudgeToAtRisk(int $characterId, int $corporationId, string $reason): bool
    {
        // Resolve character -> user via SeAT's refresh_tokens (the same
        // mapping CorpHealthController uses to roll up by player).
        $userId = DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->whereNull('deleted_at')
            ->value('user_id');

        if ($userId === null) {
            return false;
        }

        $affected = PlayerClassification::where('user_id', (int) $userId)
            ->where('corporation_id', $corporationId)
            ->where('category', PlayerClassification::CATEGORY_ACTIVE)
            ->update([
                'category'      => PlayerClassification::CATEGORY_AT_RISK,
                'classified_at' => now(),
                'updated_at'    => now(),
            ]);

        if ($affected > 0) {
            Log::info('[HR Manager] Player nudged active -> at_risk by wallet signal', [
                'user_id'        => (int) $userId,
                'character_id'   => $characterId,
                'corporation_id' => $corporationId,
                'reason'         => $reason,
            ]);
        }

        return $affected > 0;
    }

    /**
     * Build a stable idempotency key from the event subtype + payload so
     * MC EventBus replays of the same logical event collapse into one
     * history row via the hr_history_idempotency_unique constraint.
     */
    private function idempotencyKey(string $subtype, array $payload): string
    {
        $charId = $payload['character_id'] ?? '0';
        $corpId = $payload['corporation_id'] ?? '0';
        $when = $payload['detected_at'] ?? '';
        return sprintf('cwm:%s:%s:%s:%s', $subtype, $corpId, $charId, $when);
    }

    /**
     * Decide whether to fire a Discord alert for a recurring wallet condition,
     * and record that decision. This is the operator-tunable throttle behind the
     * "Wallet alert cadence" setting (`wallet_alert_repeat_hours`):
     *
     *   0   (default) -> ONCE per episode: alert on the first cycle, then stay
     *                    quiet for the whole standing episode; alert again only
     *                    after the condition has recovered (been quiet for
     *                    WALLET_RECOVERY_GAP_HOURS) and reappears.
     *   >0            -> REPEAT every that many hours while the condition persists
     *                    (e.g. a 24h reminder), then reset on recovery.
     *
     * last_seen_at is refreshed on EVERY call (so a long quiet gap reads as
     * recovery); last_notified_at is stamped only when we actually alert, so the
     * repeat cadence is measured from the last ping. Because every call updates
     * state, this also collapses EventBus double-deliveries of the same event.
     *
     * Fail-open (return true) on any error — for a critical signal a rare
     * duplicate beats a silently-dropped alert.
     */
    private function shouldSendWalletAlert(string $eventType, int $characterId, int $corporationId): bool
    {
        try {
            if (!Schema::hasTable('hr_manager_wallet_alert_state')) {
                return true; // no throttle store yet — don't suppress
            }

            $now         = now();
            $repeatHours = max(0, (int) Setting::getValue('wallet_alert_repeat_hours', 0));

            $state = WalletAlertState::firstOrNew([
                'corporation_id' => $corporationId,
                'character_id'   => $characterId,
                'event_type'     => $eventType,
            ]);

            // Brand new, or the condition was quiet long enough to count as
            // recovered: this is the first alert of a (new) episode.
            $newEpisode = !$state->exists
                || $state->last_seen_at === null
                || $state->last_seen_at->lt($now->copy()->subHours(self::WALLET_RECOVERY_GAP_HOURS));

            if ($newEpisode) {
                $state->last_seen_at     = $now;
                $state->last_notified_at = $now;
                $state->save();
                return true;
            }

            // Ongoing episode: repeat only if the operator opted in AND enough
            // time has passed since the last ping.
            $alert = $repeatHours > 0
                && ($state->last_notified_at === null
                    || $state->last_notified_at->lte($now->copy()->subHours($repeatHours)));

            $state->last_seen_at = $now;
            if ($alert) {
                $state->last_notified_at = $now;
            }
            $state->save();

            return $alert;
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] wallet alert throttle failed: ' . $e->getMessage());
            return true;
        }
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!is_numeric($value)) return null;
        return (int) $value;
    }

    private function formatIsk($value): string
    {
        if ($value === null || !is_numeric($value)) return '?';
        return number_format((float) $value, 0, '.', ',');
    }

    /**
     * Run a notification side-effect inside try/catch/log so a webhook
     * outage cannot poison the CWM event handler chain.
     */
    private function dispatchNotification(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] Wallet notification dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Publish hr.player.milestone_reached via MC's Topics facade so SeAT
     * Broadcast and other subscribers can render their own milestone
     * pings. Quietly no-ops when MC is absent — milestone history is
     * still persisted regardless.
     *
     * Migrated to Topics::publish 2026-06 (was raw EventBus::publish).
     * Topics handles the publisher_plugin lookup, idempotency key
     * composition (template: hr.milestone:{corp}:{char}:{milestone_isk}),
     * and required-field validation. If the registry rejects the payload
     * (missing milestone_isk), Topics logs a warning and skips — better
     * than silently shipping a malformed event.
     */
    private function publishMilestoneEvent(int $characterId, int $corporationId, array $payload): void
    {
        // Topics is the canonical entry point. It internally checks
        // ManagerCore::isReady() so the legacy class_exists guard is
        // redundant — Topics returns null cleanly when MC is missing.
        if (!class_exists('\\ManagerCore\\Topics')) {
            return;
        }

        try {
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->whereNull('deleted_at')
                ->value('user_id');

            \ManagerCore\Topics::publish(
                'hr.player.milestone_reached',
                [
                    'source_plugin'  => 'hr-manager',
                    'schema_version' => 1,
                    'event_id'       => 'hr-evt-' . Str::uuid()->toString(),
                    'user_id'        => $userId !== null ? (int) $userId : null,
                    'character_id'   => $characterId,
                    'corporation_id' => $corporationId,
                    'milestone_isk'  => $payload['milestone_isk']  ?? null,
                    'lifetime_total' => $payload['lifetime_total'] ?? null,
                    'months_active'  => $payload['months_active']  ?? null,
                    'detected_at'    => $payload['detected_at']    ?? null,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] Topics publish failed for hr.player.milestone_reached: ' . $e->getMessage());
        }
    }
}

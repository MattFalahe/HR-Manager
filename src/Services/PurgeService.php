<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\PlayerStatus;
use HrManager\Models\PurgeReminder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cron-driven purge reminder dispatcher. Scans player_status rows flagged
 * for purge with a scheduled date and fires reminders at T-7d / T-3d /
 * T-48h / T-0. The unique constraint on purge_reminders (player_status_id,
 * milestone) makes the cron safe to run multiple times daily without
 * duplicating notifications.
 *
 * The T-48h notification is the headline message — it lists every in-corp
 * character on the account so a human can remove Discord roles + queue the
 * in-game kick before the deadline. (No auto-removal; ESI can't kick, and
 * auto-stripping Discord is a footgun.)
 */
class PurgeService
{
    private NotificationService $notifications;
    private HistoryEventService $history;
    private PlayerService $players;

    public function __construct(
        NotificationService $notifications,
        HistoryEventService $history,
        PlayerService $players
    ) {
        $this->notifications = $notifications;
        $this->history = $history;
        $this->players = $players;
    }

    /**
     * Dispatch every due reminder. Returns counts by milestone for cron output.
     *
     * @return array<string, int>
     */
    public function dispatchDue(): array
    {
        $counts = array_fill_keys(PurgeReminder::ALL_MILESTONES, 0);

        $rows = PlayerStatus::where('status', PlayerStatus::STATUS_MARKED_FOR_PURGE)
            ->whereNotNull('purge_scheduled_for')
            ->get();

        foreach ($rows as $status) {
            $milestone = $this->computeMilestone($status->purge_scheduled_for);
            if (!$milestone) {
                continue; // not yet due for any reminder
            }

            if ($this->alreadySent($status->id, $milestone)) {
                continue;
            }

            try {
                $this->sendReminder($status, $milestone);
                $counts[$milestone]++;
            } catch (\Throwable $e) {
                Log::error('[HR Manager] PurgeService dispatch failed', [
                    'player_status_id' => $status->id,
                    'milestone'        => $milestone,
                    'error'            => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * Determine which milestone (if any) is currently due for a given
     * scheduled date. Returns the EARLIEST not-yet-passed milestone — so
     * a player flagged 8 days out gets a t7 reminder when day 7 arrives,
     * not t48 immediately.
     */
    public function computeMilestone(Carbon $scheduledFor): ?string
    {
        $daysAway = (int) now()->startOfDay()->diffInDays($scheduledFor->startOfDay(), false);

        if ($daysAway < 0) {
            return null; // past — no more milestones
        }
        if ($daysAway === 0) {
            return PurgeReminder::MILESTONE_T0;
        }
        if ($daysAway <= 2) {
            return PurgeReminder::MILESTONE_T48;
        }
        if ($daysAway <= 3) {
            return PurgeReminder::MILESTONE_T3;
        }
        if ($daysAway <= 7) {
            return PurgeReminder::MILESTONE_T7;
        }
        return null;
    }

    private function alreadySent(int $playerStatusId, string $milestone): bool
    {
        return PurgeReminder::where('player_status_id', $playerStatusId)
            ->where('milestone', $milestone)
            ->exists();
    }

    private function sendReminder(PlayerStatus $status, string $milestone): void
    {
        // Fire notification first. If it fails, don't record the dedup row -
        // next cron tick retries.
        $charactersInCorp = $this->charactersInCorpForPlayer($status->user_id, $status->corporation_id);

        $this->notifications->notifyPurgeReminder($status, $milestone, $charactersInCorp);

        // Publish to MC EventBus for external subscribers (Pings calendar etc.)
        // Fixed topic name + milestone in the payload (registry convention:
        // the tier is data, not part of the topic). The history->record call
        // below keeps the per-tier suffix so its once-per-day dedup stays
        // tier-distinct.
        $this->publishToEventBus('hr.purge.reminder', [
            'source_plugin'   => 'hr-manager',
            'schema_version'  => 1,
            'event_id'        => 'hr-evt-' . Str::uuid()->toString(),
            'player_status_id'=> $status->id,
            'user_id'         => $status->user_id,
            'corporation_id'  => $status->corporation_id,
            'milestone'       => $milestone,
            'scheduled_for'   => $status->purge_scheduled_for?->toIso8601String(),
            'reason'          => $status->reason,
            'characters_in_corp' => $charactersInCorp,
        ]);

        // Record persistent history + dedup row last
        $this->history->record("hr.purge.reminder_{$milestone}", [
            'player_status_id' => $status->id,
            'milestone'        => $milestone,
            'scheduled_for'    => $status->purge_scheduled_for?->toDateString(),
        ], [
            'user_id'        => $status->user_id,
            'corporation_id' => $status->corporation_id,
            'occurred_at'    => now(),
            'idempotency_key' => "purge-reminder:{$status->id}:{$milestone}",
        ]);

        PurgeReminder::create([
            'player_status_id' => $status->id,
            'milestone'        => $milestone,
            'dispatched_at'    => now(),
        ]);
    }

    /**
     * Mark a purge as actually executed by a director (button on profile).
     * Publishes the executed event and archives the status row.
     */
    public function markExecuted(PlayerStatus $status, int $executorUserId): void
    {
        $this->history->record('hr.purge.executed', [
            'player_status_id' => $status->id,
            'executed_by'      => $executorUserId,
            'scheduled_for'    => $status->purge_scheduled_for?->toDateString(),
            'reason'           => $status->reason,
        ], [
            'user_id'        => $status->user_id,
            'corporation_id' => $status->corporation_id,
            'occurred_at'    => now(),
        ]);

        PurgeReminder::create([
            'player_status_id' => $status->id,
            'milestone'        => PurgeReminder::MILESTONE_EXECUTED,
            'dispatched_at'    => now(),
        ]);

        $this->publishToEventBus('hr.purge.executed', [
            'source_plugin'   => 'hr-manager',
            'schema_version'  => 1,
            'event_id'        => 'hr-evt-' . Str::uuid()->toString(),
            'player_status_id'=> $status->id,
            'user_id'         => $status->user_id,
            'corporation_id'  => $status->corporation_id,
            'executed_by'     => $executorUserId,
        ]);

        // Status row is preserved as historical record; status flipped to active
        // and reason annotated.
        $status->update([
            'status'        => PlayerStatus::STATUS_ACTIVE,
            'reason'        => 'Purge executed ' . now()->toDateString() . ($status->reason ? ' — ' . $status->reason : ''),
            'status_set_by' => $executorUserId,
            'status_set_at' => now(),
        ]);
    }

    private function charactersInCorpForPlayer(int $userId, int $corporationId): array
    {
        return \Illuminate\Support\Facades\DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->leftJoin('character_infos', 'character_infos.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $userId)
            ->where('character_affiliations.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->get(['character_affiliations.character_id', 'character_infos.name'])
            ->map(fn($r) => ['character_id' => (int) $r->character_id, 'name' => $r->name ?: ('#' . $r->character_id)])
            ->all();
    }

    private function publishToEventBus(string $eventName, array $payload): void
    {
        // Topics::publish is the canonical publish path (registry validation +
        // idempotency-template composition + sanitization). No-ops cleanly
        // when MC is absent.
        if (!class_exists('\\ManagerCore\\Topics')) {
            return;
        }
        try {
            \ManagerCore\Topics::publish($eventName, $payload);
        } catch (\Throwable $e) {
            Log::warning("[HR Manager] Topics publish failed for {$eventName}: " . $e->getMessage());
        }
    }
}

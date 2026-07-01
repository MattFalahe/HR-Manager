<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\PlayerStatus;
use HrManager\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Seat\Eveapi\Models\Character\CharacterInfo;

/**
 * Detects when a tracked corporation member has revoked their SeAT
 * refresh token (delinked from SeAT). Drives the security-policy
 * workflow:
 *
 *   1. Records a player.token_revoked history event
 *   2. Fires a Discord webhook notification (when enabled per webhook)
 *   3. If security_token_loss_enabled is true on the install, schedules
 *      a T+N hour purge as a security guard
 *
 * Rationale: a former member who deliberately removes their SeAT
 * token is severing the install's visibility into them while they
 * still have corp access. That's a security gap — they could be
 * preparing to leave on bad terms, or be a quiet spy.
 *
 * Settings keys (all in hr_manager_settings):
 *   security_token_loss_enabled       bool (master toggle, default false)
 *   security_token_loss_purge_hours   int  (default 72)
 *   security_token_loss_last_scan_at  timestamp (internal scan watermark)
 */
class TokenLossService
{
    public const HISTORY_EVENT = 'player.token_revoked';

    /**
     * Run the detection sweep. Returns counts for the CLI summary.
     *
     * @return array{detected:int, history_inserted:int, purges_scheduled:int, last_scan:\Carbon\Carbon}
     */
    public function detect(): array
    {
        $enabled = (bool) Setting::getValue('security_token_loss_enabled', false);
        $purgeHours = max(0, (int) Setting::getValue('security_token_loss_purge_hours', 72));

        $lastScanRaw = Setting::getValue('security_token_loss_last_scan_at');
        $lastScan = $lastScanRaw
            ? Carbon::parse($lastScanRaw)
            : now()->subHours(6); // first run: look back 6h so we don't replay the whole soft-delete history

        $now = now();

        // Pull all refresh_tokens soft-deleted since the previous scan.
        // SeAT marks deleted_at when the user removes the character.
        $deletedTokens = DB::table('refresh_tokens')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '>', $lastScan)
            ->where('deleted_at', '<=', $now)
            ->get(['character_id', 'user_id', 'deleted_at']);

        $historyService = app(HistoryEventService::class);
        $detected = 0;
        $historyInserted = 0;
        $purgesScheduled = 0;

        foreach ($deletedTokens as $row) {
            $characterId = (int) $row->character_id;
            $userId      = (int) $row->user_id;
            $deletedAt   = Carbon::parse($row->deleted_at);

            // Was the character in a tracked corp at the time? Use the
            // last-known affiliation (or member-tracking) for the corp
            // the policy applies to.
            $corporationId = $this->resolveLastKnownCorp($characterId);
            if ($corporationId === null) {
                continue;
            }

            $detected++;

            $charName = CharacterInfo::where('character_id', $characterId)->value('name')
                ?? ('Character #' . $characterId);

            // History event keyed by stable idempotency so re-running
            // the cron on a windowed re-scan doesn't double-insert.
            $event = $historyService->record(
                self::HISTORY_EVENT,
                [
                    'character_id'   => $characterId,
                    'character_name' => $charName,
                    'deleted_at'     => $deletedAt->toIso8601String(),
                    'detected_at'    => $now->toIso8601String(),
                    'severity'       => 'critical',
                    'security_policy_applied' => $enabled,
                ],
                [
                    'user_id'        => $userId,
                    'character_id'   => $characterId,
                    'corporation_id' => $corporationId,
                    'occurred_at'    => $deletedAt,
                    'source_plugin'  => 'hr-manager',
                    'idempotency_key' => sprintf(
                        'hr:token_revoked:%s:%s:%s',
                        $corporationId,
                        $characterId,
                        $deletedAt->toIso8601String()
                    ),
                ]
            );

            if ($event !== null) {
                $historyInserted++;
            }

            // Notification — best-effort, isolated.
            try {
                app(NotificationService::class)->notifyTokenRevoked(
                    $userId,
                    $characterId,
                    $corporationId,
                    $charName
                );
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] TokenLoss notification failed: ' . $e->getMessage());
            }

            // Security policy action — only when enabled and master gate
            // is on. Schedules a T+N hour purge as a security guard.
            if ($enabled) {
                if ($purgeHours > 0) {
                    $purgeWhen = $now->copy()->addHours($purgeHours);
                    PlayerStatus::updateOrCreate(
                        ['user_id' => $userId, 'corporation_id' => $corporationId],
                        [
                            'status'              => PlayerStatus::STATUS_MARKED_FOR_PURGE,
                            'loa_until'           => null,
                            'purge_scheduled_for' => $purgeWhen,
                            'reason'              => 'AUTO: SeAT refresh token revoked. Security policy purge.',
                            'status_set_by'       => 0,
                            'status_set_at'       => $now,
                        ]
                    );
                    $purgesScheduled++;
                }
            }
        }

        // Watermark for next run.
        Setting::setValue('security_token_loss_last_scan_at', $now->toIso8601String(), 'string');

        return [
            'detected'         => $detected,
            'history_inserted' => $historyInserted,
            'purges_scheduled' => $purgesScheduled,
            'last_scan'        => $now,
        ];
    }

    /**
     * Last-known corporation for a character: try character_affiliations
     * first (most current), fall back to corporation_members /
     * corporation_member_trackings.
     */
    private function resolveLastKnownCorp(int $characterId): ?int
    {
        $corpId = DB::table('character_affiliations')
            ->where('character_id', $characterId)
            ->value('corporation_id');
        if ($corpId !== null) {
            return (int) $corpId;
        }

        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $corpId = DB::table($table)
                    ->where('character_id', $characterId)
                    ->value('corporation_id');
                if ($corpId !== null) {
                    return (int) $corpId;
                }
            }
        }

        return null;
    }
}

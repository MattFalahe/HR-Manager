<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\Note;
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

    // Which token loss escalates an account to an auto-purge.
    //   main     — only when the account's main character loses its token
    //   any      — any in-corp character (the original behaviour)
    //   coverage — when the lost fraction of in-corp characters ≥ the % knob
    // A single-character account always triggers, whatever the mode.
    public const TRIGGER_MAIN     = 'main';
    public const TRIGGER_ANY      = 'any';
    public const TRIGGER_COVERAGE = 'coverage';

    public const SETTING_ENABLED       = 'security_token_loss_enabled';
    public const SETTING_PURGE_HOURS   = 'security_token_loss_purge_hours';
    public const SETTING_TRIGGER_MODE  = 'security_token_loss_trigger_mode';
    public const SETTING_COVERAGE_PCT  = 'security_token_loss_coverage_pct';

    // Opt-in squad-drop on token loss. HR only ever REMOVES from squads (never
    // adds), so a dropped manual/hidden squad is NOT restored if the token comes
    // back — a director re-adds by hand. The grace window exists precisely
    // because the drop isn't auto-reversible.
    public const SETTING_SQUAD_DROP_ENABLED   = 'security_token_loss_squad_drop_enabled';
    public const SETTING_SQUAD_DROP_HOURS     = 'security_token_loss_squad_drop_hours';
    public const SETTING_SQUAD_DROP_DIRECTOR_IMMEDIATE = 'security_token_loss_squad_drop_director_immediate';

    /**
     * Run the detection sweep. Returns counts for the CLI summary.
     *
     * @return array{detected:int, history_inserted:int, purges_scheduled:int, last_scan:\Carbon\Carbon}
     */
    public function detect(): array
    {
        $enabled = (bool) Setting::getValue(self::SETTING_ENABLED, false);
        $purgeHours = max(0, (int) Setting::getValue(self::SETTING_PURGE_HOURS, 72));
        $mode = $this->triggerMode();
        $coveragePct = $this->coveragePct();

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

        // Accounts (per corp) touched this scan — the trigger is evaluated ONCE
        // per account against its current coverage, after the per-character
        // facts below are recorded, so a multi-alt account isn't purged for a
        // single peripheral revocation (unless the mode says so).
        $affected = [];

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
            $affected[$userId . '|' . $corporationId] = ['user_id' => $userId, 'corporation_id' => $corporationId];

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

            // Timeline breadcrumb on the player profile.
            $this->watchdogNote($userId, "SeAT refresh token revoked for **{$charName}** — HR loses visibility into this character while corp access may persist.");
        }

        // Security policy action — only when enabled. One decision per affected
        // account, using the configured trigger mode + its current coverage.
        if ($enabled && $purgeHours > 0) {
            foreach ($affected as $acct) {
                if ($this->maybeSchedulePurge((int) $acct['user_id'], (int) $acct['corporation_id'], $purgeHours, $mode, $coveragePct, $now)) {
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

    /** Configured trigger mode, defaulting to 'any' (the original behaviour). */
    public function triggerMode(): string
    {
        $mode = (string) Setting::getValue(self::SETTING_TRIGGER_MODE, self::TRIGGER_ANY);

        return in_array($mode, [self::TRIGGER_MAIN, self::TRIGGER_ANY, self::TRIGGER_COVERAGE], true)
            ? $mode
            : self::TRIGGER_ANY;
    }

    /** Coverage-mode loss threshold as a percentage (25 / 50 / 75 / 100). */
    public function coveragePct(): int
    {
        $pct = (int) Setting::getValue(self::SETTING_COVERAGE_PCT, 100);

        return in_array($pct, [25, 50, 75, 100], true) ? $pct : 100;
    }

    /**
     * Token coverage for one account inside one corp. Total counts every
     * character linked to the SeAT user (including soft-deleted / delinked
     * rows) whose last-known affiliation is this corp; lost = those with no
     * live token. main_lost = the account's main character is in this corp and
     * has lost its token.
     *
     * @return array{total:int, valid:int, lost:int, main_lost:bool, lost_character_ids:array<int>}
     */
    public function accountCorpCoverage(int $userId, int $corporationId): array
    {
        // Raw query builder deliberately bypasses the SoftDeletes scope so the
        // roster includes just-delinked characters (the denominator must not
        // shrink the moment a token is revoked).
        $rows = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $userId)
            ->where('character_affiliations.corporation_id', $corporationId)
            ->get(['refresh_tokens.character_id', 'refresh_tokens.deleted_at']);

        $total = $rows->count();
        $lostRows = $rows->whereNotNull('deleted_at');
        $lost  = $lostRows->count();
        $lostCharacterIds = $lostRows->map(fn($r) => (int) $r->character_id)->values()->all();

        $mainCharId = \Seat\Web\Models\User::where('id', $userId)->value('main_character_id');
        $mainLost = $mainCharId !== null && in_array((int) $mainCharId, $lostCharacterIds, true);

        return [
            'total'              => $total,
            'valid'             => $total - $lost,
            'lost'              => $lost,
            'main_lost'         => $mainLost,
            'lost_character_ids' => $lostCharacterIds,
        ];
    }

    /**
     * Does any of the account's just-lost in-corp characters hold the in-game
     * Director role? A director losing SeAT visibility while still holding full
     * corp access is the highest-severity case, so it triggers regardless of the
     * configured mode.
     *
     * @param array<int> $lostCharacterIds
     */
    public function hasDirectorTokenLoss(array $lostCharacterIds, int $corporationId): bool
    {
        $titles = app(CharacterTitleService::class);
        foreach ($lostCharacterIds as $charId) {
            if ($titles->holdsDirector((int) $charId, $corporationId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this account's current coverage trip the configured trigger?
     * A single in-corp character always trips (never let a one-character
     * account slip through a percentage gate).
     */
    public function coverageTriggers(array $coverage, string $mode, int $pct): bool
    {
        $total = (int) $coverage['total'];
        $lost  = (int) $coverage['lost'];

        if ($total <= 0 || $lost <= 0) {
            return false;
        }
        if ($total === 1) {
            return true;
        }

        return match ($mode) {
            self::TRIGGER_MAIN     => (bool) $coverage['main_lost'],
            self::TRIGGER_COVERAGE => ($lost / $total) * 100 >= $pct,
            default                => true, // 'any'
        };
    }

    /**
     * Schedule a T+N hour security purge for one account when the trigger trips,
     * stamped ORIGIN_TOKEN_LOSS so auto-cancel can later recognise it. A
     * director's manual purge, or an already-scheduled token-loss purge, is left
     * untouched. Returns true when a purge was newly scheduled.
     */
    private function maybeSchedulePurge(int $userId, int $corporationId, int $purgeHours, string $mode, int $pct, Carbon $now): bool
    {
        if (!$this->accountTriggersPurge($userId, $corporationId, $mode, $pct)) {
            return false;
        }

        $existing = PlayerStatus::where('user_id', $userId)
            ->where('corporation_id', $corporationId)
            ->first();

        // Never override a purge a director set by hand, and don't keep pushing
        // the date on one we already scheduled.
        if ($existing
            && $existing->status === PlayerStatus::STATUS_MARKED_FOR_PURGE
            && ($existing->purge_origin !== PlayerStatus::ORIGIN_TOKEN_LOSS || $existing->purge_scheduled_for !== null)) {
            return false;
        }

        $purgeWhen = $now->copy()->addHours($purgeHours);
        PlayerStatus::updateOrCreate(
            ['user_id' => $userId, 'corporation_id' => $corporationId],
            [
                'status'              => PlayerStatus::STATUS_MARKED_FOR_PURGE,
                'loa_until'           => null,
                'purge_scheduled_for' => $purgeWhen,
                'reason'              => 'AUTO: SeAT refresh token revoked. Security policy purge.',
                'purge_origin'        => PlayerStatus::ORIGIN_TOKEN_LOSS,
                'status_set_by'       => 0,
                'status_set_at'       => $now,
            ]
        );

        $this->watchdogNote($userId, "Security-policy purge auto-scheduled for **{$purgeWhen->toDateString()}** after SeAT token revocation. It cancels automatically if the token is restored before then.");

        return true;
    }

    /**
     * Whether an account's CURRENT state trips the auto-purge policy: either a
     * director among its lost characters (always escalates) or the configured
     * coverage trigger. Shared by scheduling (does this account warrant a purge?)
     * and by restoration reconciliation (does an existing token-loss purge still
     * apply, or has the situation resolved?).
     */
    public function accountTriggersPurge(int $userId, int $corporationId, string $mode, int $pct): bool
    {
        $coverage = $this->accountCorpCoverage($userId, $corporationId);

        if ($this->hasDirectorTokenLoss($coverage['lost_character_ids'], $corporationId)) {
            return true;
        }

        return $this->coverageTriggers($coverage, $mode, $pct);
    }

    /**
     * Reconcile token-loss purges against restored tokens. For every purge this
     * automation scheduled (ORIGIN_TOKEN_LOSS, still marked_for_purge), if the
     * account no longer trips the policy — the member re-linked enough of their
     * characters — clear the purge back to active and announce it. Only touches
     * the automation's OWN purges, never a director's manual one. Runs on every
     * detection tick, independent of the master toggle, so a restore always
     * closes the loop even if the operator later disabled the feature.
     *
     * @return int number of purges cancelled this run
     */
    public function cancelResolvedPurges(): int
    {
        $mode = $this->triggerMode();
        $pct  = $this->coveragePct();
        $now  = now();
        $cancelled = 0;

        $rows = PlayerStatus::where('status', PlayerStatus::STATUS_MARKED_FOR_PURGE)
            ->where('purge_origin', PlayerStatus::ORIGIN_TOKEN_LOSS)
            ->get();

        foreach ($rows as $status) {
            $userId = (int) $status->user_id;
            $corpId = (int) $status->corporation_id;

            // Still tripping under current policy? The security condition
            // persists — leave the scheduled purge alone.
            if ($this->accountTriggersPurge($userId, $corpId, $mode, $pct)) {
                continue;
            }

            // Was access already reduced (squads dropped) during this alert? We
            // can't re-add squads, so flag it in the note — and clear the marker
            // so a FUTURE token-loss episode for this member can drop again.
            $squadsWereDropped = $status->purge_squads_removed_at !== null;

            $status->update([
                'status'                  => PlayerStatus::STATUS_ACTIVE,
                'purge_scheduled_for'     => null,
                'purge_squads_removed_at' => null,
                'reason'                  => 'AUTO: SeAT token restored. Security purge cancelled.',
                'purge_origin'            => PlayerStatus::ORIGIN_MANUAL,
                'status_set_by'           => 0,
                'status_set_at'           => $now,
            ]);

            try {
                app(HistoryEventService::class)->record(
                    'player.token_restored',
                    [
                        'detected_at' => $now->toIso8601String(),
                        'severity'    => 'info',
                    ],
                    [
                        'user_id'         => $userId,
                        'corporation_id'  => $corpId,
                        'occurred_at'     => $now,
                        'source_plugin'   => 'hr-manager',
                        'idempotency_key' => sprintf('hr:token_restored:%s:%s:%s', $corpId, $userId, $now->toDateString()),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] TokenLoss restore history failed: ' . $e->getMessage());
            }

            try {
                app(NotificationService::class)->notifyTokenRestored($userId, $corpId);
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] TokenLoss restore notification failed: ' . $e->getMessage());
            }

            $restoreNote = 'SeAT token coverage restored — the security-policy auto-purge has been cancelled.';
            if ($squadsWereDropped) {
                $restoreNote .= ' Note: manual/hidden squads removed during the alert were NOT restored — re-add by hand if this member should regain that access.';
            }
            $this->watchdogNote($userId, $restoreNote);

            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * Opt-in access reduction: drop the removable (manual / hidden, non-excluded)
     * squads of members under a token-loss purge, so a suspected spy loses their
     * Connector-driven Discord access before the kick. HR only ever REMOVES —
     * these squads are NOT re-added if the token is later restored, which is why
     * a grace window (from the token-loss event) gates the drop; a director token
     * loss can optionally skip the grace and drop immediately. Fires once per
     * purge via the shared purge_squads_removed_at marker (so it never
     * double-drops with the purge-kick cleanup). Auto squads are untouched (SeAT
     * re-adds them until the member leaves) and the operator's
     * purge_squad_exclusions protected list is honoured.
     *
     * @return int number of purges whose squads were processed this run
     */
    public function processSquadDrops(): int
    {
        if (!(bool) Setting::getValue(self::SETTING_SQUAD_DROP_ENABLED, false)
            || !\Illuminate\Support\Facades\Schema::hasColumn('hr_manager_player_status', 'purge_squads_removed_at')) {
            return 0;
        }

        $graceHours = max(0, (int) Setting::getValue(self::SETTING_SQUAD_DROP_HOURS, 24));
        $directorImmediate = (bool) Setting::getValue(self::SETTING_SQUAD_DROP_DIRECTOR_IMMEDIATE, false);
        $now = now();
        $processed = 0;

        $rows = PlayerStatus::where('status', PlayerStatus::STATUS_MARKED_FOR_PURGE)
            ->where('purge_origin', PlayerStatus::ORIGIN_TOKEN_LOSS)
            ->whereNull('purge_squads_removed_at')
            ->get();

        foreach ($rows as $status) {
            $userId = (int) $status->user_id;
            $corpId = (int) $status->corporation_id;

            $due = false;

            // Director-immediate: a director token loss skips the grace window.
            if ($directorImmediate) {
                $coverage = $this->accountCorpCoverage($userId, $corpId);
                if ($this->hasDirectorTokenLoss($coverage['lost_character_ids'], $corpId)) {
                    $due = true;
                }
            }

            // Otherwise wait out the grace window from when the purge was flagged
            // (≈ the token-loss event), so a quick re-auth avoids the drop.
            if (!$due
                && $status->status_set_at !== null
                && $now->gte($status->status_set_at->copy()->addHours($graceHours))) {
                $due = true;
            }

            if (!$due) {
                continue;
            }

            try {
                // Reuse the purge squad-cleanup so the removal, the
                // purge_squads_removed_at stamp, and the hr.squad.removed history
                // all stay identical to the purge-time path.
                $removed = app(PurgeService::class)->removeSquadsForPurge($status, 'token_loss');

                if (!empty($removed)) {
                    $names = implode(', ', array_map(fn ($s) => $s['name'], $removed));
                    $this->watchdogNote($userId, "Removed from manual/hidden squad(s) after token loss to reduce access: {$names}. These are NOT re-added automatically if the token is restored — re-add by hand once cleared.");
                }

                $processed++;
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] TokenLoss squad-drop failed for status ' . $status->id . ': ' . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Drop an auto-authored "HR Watchdog" note on the player's timeline so the
     * security arc (revoked → auto-scheduled → restored) reads in one place on
     * the profile, not just in the history log. Author 0 is a system sentinel
     * the player view renders as "HR Watchdog"; public (is_private=false) so
     * every director who can see the player sees it. Best-effort — a note
     * failure never derails the security flow.
     */
    private function watchdogNote(int $userId, string $content): void
    {
        try {
            Note::create([
                'noteable_type' => 'player',
                'noteable_id'   => $userId,
                'author_id'     => 0,
                'content'       => $content,
                'is_private'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] Watchdog note failed: ' . $e->getMessage());
        }
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

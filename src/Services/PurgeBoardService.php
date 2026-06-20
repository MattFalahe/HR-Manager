<?php

namespace HrManager\Services;

use HrManager\Models\PlayerStatus;
use HrManager\Models\PurgeReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The Corp Health "Purge" board: a director worklist of every player marked
 * for purge, with the remove-by deadline, the reminder-ladder progress
 * (T-7 / T-3 / T-48 / T-0), an editable note, a per-step checklist (in-game
 * roles / removed from corp), and automatic corp-removal detection so
 * the list self-cleans as members actually leave.
 *
 * Removal detection cascade (no plugin-owned ESI tokens involved):
 *   1. corporation_members   — the director-token roster (authoritative when present)
 *   2. character_affiliations — SeAT's per-character current corp
 *   3. public ESI affiliation (PublicCorpLookupService) — tokenless fallback
 *      to tell "actually left" apart from "SeAT lost track" when 1+2 can't.
 *
 * Reads SeAT-synced tables + a best-effort public lookup; writes only HR's own
 * player_status purge columns. Standalone-safe: the board reports unavailable
 * until the purge-progress migration has run.
 */
class PurgeBoardService
{
    private PublicCorpLookupService $publicLookup;

    public function __construct(PublicCorpLookupService $publicLookup)
    {
        $this->publicLookup = $publicLookup;
    }

    /**
     * Build the board for a corp. Runs departure reconciliation first
     * so what renders reflects current reality.
     */
    public function getCorpBoard(int $corporationId): array
    {
        if (!Schema::hasTable('hr_manager_player_status')
            || !Schema::hasColumn('hr_manager_player_status', 'purge_left_corp_at')) {
            return ['available' => false, 'reason' => 'migration_pending', 'entries' => [], 'counts' => []];
        }

        $this->detectRemovals($corporationId);

        // Show EVERY marked-for-purge player, including ones flagged without a
        // scheduled date yet ("Purge flagged (no date)"). Dated purges sort
        // first (by deadline), undated ones after, departed members last.
        $rows = PlayerStatus::where('corporation_id', $corporationId)
            ->where('status', PlayerStatus::STATUS_MARKED_FOR_PURGE)
            ->orderByRaw('purge_left_corp_at IS NOT NULL') // active first, departed sink
            ->orderByRaw('purge_scheduled_for IS NULL')    // dated first, undated after
            ->orderBy('purge_scheduled_for')
            ->get();

        $entries = $rows->map(fn ($s) => $this->buildEntry($s))->all();

        return [
            'available' => true,
            'entries'   => $entries,
            'counts'    => [
                'total'   => count($entries),
                'overdue' => count(array_filter($entries, fn ($e) => $e['is_overdue'] && !$e['left_corp'])),
                'left'    => count(array_filter($entries, fn ($e) => $e['left_corp'])),
            ],
        ];
    }

    /**
     * Manual step check-off. Only the roles step is operator-set; the
     * corp-removal step is auto-detected.
     */
    public function markStep(int $statusId, string $step, bool $done): bool
    {
        $col = match ($step) {
            'roles'  => 'purge_roles_removed_at',
            default  => null,
        };
        if ($col === null || !Schema::hasColumn('hr_manager_player_status', $col)) {
            return false;
        }
        $status = PlayerStatus::find($statusId);
        if (!$status || $status->status !== PlayerStatus::STATUS_MARKED_FOR_PURGE) {
            return false;
        }
        $status->update([$col => $done ? now() : null]);
        return true;
    }

    public function updateNote(int $statusId, ?string $note): bool
    {
        if (!Schema::hasColumn('hr_manager_player_status', 'purge_notes')) {
            return false;
        }
        $status = PlayerStatus::find($statusId);
        if (!$status) {
            return false;
        }
        $status->update(['purge_notes' => ($note !== null && $note !== '') ? mb_substr($note, 0, 2000) : null]);
        return true;
    }

    /**
     * For every still-active purge in the corp, work out whether the player
     * has actually left and stamp purge_left_corp_at (+ destination corp) when
     * confirmed. Cheap: the public fallback only fires for the few players
     * SeAT can't place locally.
     */
    public function detectRemovals(int $corporationId): void
    {
        $rows = PlayerStatus::where('corporation_id', $corporationId)
            ->where('status', PlayerStatus::STATUS_MARKED_FOR_PURGE)
            ->whereNull('purge_left_corp_at')
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $s) {
            try {
                $charIds = $this->userCharacterIds($s->user_id);
                if (empty($charIds)) {
                    continue; // unregistered player; nothing to place
                }

                $placement = $this->corpPlacement($charIds, $corporationId);
                if ($placement === 'in') {
                    continue;
                }

                // 'out' (roster confirms absence) or 'unknown' (no roster): use
                // the public fallback to avoid a false positive when SeAT only
                // lost the token, and to capture where they went.
                $publicCorps = $this->publicLookup->currentCorps($charIds);
                if (!empty($publicCorps)) {
                    $stillHere = false;
                    $destination = null;
                    foreach ($publicCorps as $corp) {
                        if ((int) $corp === $corporationId) {
                            $stillHere = true;
                            break;
                        }
                        $destination = (int) $corp;
                    }
                    if ($stillHere) {
                        continue;
                    }
                    $this->recordLeft($s, $destination);
                    continue;
                }

                // No public answer: trust an authoritative SeAT 'out' only.
                if ($placement === 'out') {
                    $this->recordLeft($s, null);
                }
                // 'unknown' + no public answer => leave for the next run.
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] PurgeBoardService detect failed for status ' . $s->id . ': ' . $e->getMessage());
            }
        }
    }

    private function recordLeft(PlayerStatus $s, ?int $destinationCorp): void
    {
        $s->update([
            'purge_left_corp_at' => now(),
            'purge_left_corp_to' => $destinationCorp,
        ]);

        // Opt-in: once the player has actually left the corp there is no
        // cancellation risk, so clear their SeAT squads immediately (which, with
        // Connector, drops the matching Discord roles). No-op when the toggle is
        // off or the squads were already cleared.
        try {
            app(PurgeService::class)->maybeRemoveSquadsOnDeparture($s->fresh());
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] purge squad cleanup on departure failed for status ' . $s->id . ': ' . $e->getMessage());
        }
    }

    /** @return string 'in' | 'out' | 'unknown' */
    private function corpPlacement(array $charIds, int $corpId): string
    {
        $hasRoster = Schema::hasTable('corporation_members')
            && DB::table('corporation_members')->where('corporation_id', $corpId)->limit(1)->exists();

        if ($hasRoster) {
            $inRoster = DB::table('corporation_members')
                ->where('corporation_id', $corpId)
                ->whereIn('character_id', $charIds)
                ->exists();
            return $inRoster ? 'in' : 'out';
        }

        if (Schema::hasTable('character_affiliations')) {
            $affil = DB::table('character_affiliations')
                ->whereIn('character_id', $charIds)
                ->pluck('corporation_id', 'character_id');
            if ($affil->isEmpty()) {
                return 'unknown';
            }
            foreach ($affil as $corp) {
                if ((int) $corp === $corpId) {
                    return 'in';
                }
            }
            // Every known affiliation points elsewhere; only call it 'out' if we
            // actually have an affiliation row for every character.
            return $affil->count() === count($charIds) ? 'out' : 'unknown';
        }

        return 'unknown';
    }

    private function buildEntry(PlayerStatus $s): array
    {
        $fired = PurgeReminder::where('player_status_id', $s->id)
            ->pluck('milestone')->map(fn ($m) => (string) $m)->all();
        $deadline = $s->purge_scheduled_for;
        $chars = $this->charactersInCorp($s->user_id, $s->corporation_id);
        $charIds = array_map(fn ($c) => $c['character_id'], $chars);
        // Per-character in-game roles + titles: the kick list. EVE kicks and
        // strips roles ONE CHARACTER at a time, so the operator needs each
        // alt's own roles/titles, not just the account-level aggregate.
        $ingameByChar = !empty($charIds)
            ? app(AccessDepthService::class)->perCharacterIngameAccess($charIds, $s->corporation_id)
            : [];

        return [
            'status_id'      => (int) $s->id,
            'user_id'        => (int) $s->user_id,
            'player_name'    => $this->playerName($s->user_id, $chars),
            'characters'     => $chars,
            'ingame_by_character' => $ingameByChar,
            'marked_at'      => $s->status_set_at,
            'deadline'       => $deadline,
            'deadline_human' => $deadline ? $deadline->diffForHumans() : null,
            'is_overdue'     => $deadline ? $deadline->isPast() : false,
            'reason'         => $s->reason,
            'notes'          => $s->purge_notes,
            'reminders'      => [
                't7'       => $this->firedAny($fired, ['t7', PurgeReminder::MILESTONE_T7]),
                't3'       => $this->firedAny($fired, ['t3', PurgeReminder::MILESTONE_T3]),
                't48'      => $this->firedAny($fired, ['t48', PurgeReminder::MILESTONE_T48]),
                't0'       => $this->firedAny($fired, ['t0', PurgeReminder::MILESTONE_T0]),
                'executed' => $this->firedAny($fired, [PurgeReminder::MILESTONE_EXECUTED]),
            ],
            'steps'          => [
                'roles'  => $s->purge_roles_removed_at,
                'corp'   => $s->purge_left_corp_at,
            ],
            'left_corp'      => $s->purge_left_corp_at !== null,
            'left_corp_at'   => $s->purge_left_corp_at,
            'left_corp_to'   => $s->purge_left_corp_to,
        ];
    }

    private function firedAny(array $fired, array $candidates): bool
    {
        foreach ($candidates as $c) {
            if (in_array((string) $c, $fired, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int> */
    private function userCharacterIds(int $userId): array
    {
        if (!Schema::hasTable('refresh_tokens')) {
            return [];
        }
        return DB::table('refresh_tokens')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function charactersInCorp(int $userId, int $corporationId): array
    {
        if (!Schema::hasTable('refresh_tokens') || !Schema::hasTable('character_affiliations')) {
            return [];
        }
        return DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->leftJoin('character_infos', 'character_infos.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $userId)
            ->where('character_affiliations.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->get(['character_affiliations.character_id', 'character_infos.name'])
            ->map(fn ($r) => ['character_id' => (int) $r->character_id, 'name' => $r->name ?: ('#' . $r->character_id)])
            ->all();
    }

    private function playerName(int $userId, array $chars): string
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
        return $chars[0]['name'] ?? ('User #' . $userId);
    }
}

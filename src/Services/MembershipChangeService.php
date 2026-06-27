<?php

namespace HrManager\Services;

use HrManager\Models\Application;
use HrManager\Models\CorpMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Detects corp membership changes by diffing SeAT's live roster against HR's
 * own forward-only snapshot (hr_manager_corp_members), then classifies + routes
 * a notification for each change.
 *
 * Forward-only by design: the FIRST time a corp is seen, its whole roster is
 * seeded silently — existing members are never announced. Only joins / leaves
 * after that notify.
 *
 * Join classification (in precedence order):
 *   - known_alt      — the same account already has a character in the corp
 *                      (an existing member added an alt). NOT a security flag.
 *   - applied        — a valid (non-rejected / non-withdrawn) HR application
 *                      exists for the character or the account.
 *   - no_application — a NEW person with no valid application (the security flag).
 *
 * known_alt + applied route to notify_member_joined (the message names the main);
 * no_application routes to notify_join_no_application; leaves route to
 * notify_member_left. Each change fires exactly one category, so an "all
 * changes" channel just enables all three with no double-send.
 */
class MembershipChangeService
{
    private NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /** Run the diff across every monitored corp. */
    public function detect(bool $dryRun = false): array
    {
        $summary = ['corps' => 0, 'seeded' => 0, 'joined' => 0, 'left' => 0, 'no_application' => 0];

        if (!Schema::hasTable('hr_manager_corp_members')) {
            return $summary;
        }

        foreach ($this->monitoredCorporations() as $corpId) {
            $res = $this->detectForCorporation($corpId, $dryRun);
            foreach (['seeded', 'joined', 'left', 'no_application'] as $k) {
                $summary[$k] += $res[$k];
            }
            $summary['corps']++;
        }

        return $summary;
    }

    public function detectForCorporation(int $corporationId, bool $dryRun = false): array
    {
        $out = ['seeded' => 0, 'joined' => 0, 'left' => 0, 'no_application' => 0];

        $source = $this->rosterSource($corporationId);
        if ($source === null) {
            return $out; // no synced roster for this corp
        }

        $current  = $this->currentRoster($corporationId, $source); // [char_id => true]
        $knownIds = CorpMember::where('corporation_id', $corporationId)->pluck('character_id')
            ->map(fn ($c) => (int) $c)->all();

        // First sighting: seed silently so current members are never announced.
        if (empty($knownIds)) {
            if (!$dryRun) {
                $this->seed($corporationId, array_keys($current));
            }
            $out['seeded'] = count($current);
            return $out;
        }

        // Safety: a non-empty known roster but an empty live roster is almost
        // always a sync hiccup / lost token, not a real mass exodus. Skip rather
        // than fire a leave for every member.
        if (empty($current)) {
            Log::warning("[HR Manager] Membership diff skipped for corp {$corporationId}: live roster empty but snapshot has " . count($knownIds) . ' members.');
            return $out;
        }

        $knownSet = array_flip($knownIds);

        // Joins.
        foreach (array_keys($current) as $charId) {
            if (isset($knownSet[$charId])) {
                continue;
            }
            $cls = $this->classifyJoin($corporationId, (int) $charId, $knownSet);
            if (!$dryRun) {
                $this->recordMember($corporationId, (int) $charId, $cls['main_character_id']);
                $this->dispatchJoin($corporationId, (int) $charId, $cls);
            }
            $out['joined']++;
            if ($cls['type'] === 'no_application') {
                $out['no_application']++;
            }
        }

        // Leaves.
        $leftRows = CorpMember::where('corporation_id', $corporationId)
            ->whereNotIn('character_id', array_keys($current))->get();
        foreach ($leftRows as $row) {
            if (!$dryRun) {
                $this->dispatchLeave(
                    $corporationId,
                    (int) $row->character_id,
                    $row->main_character_id !== null ? (int) $row->main_character_id : null,
                    $current
                );
                $row->delete();
            }
            $out['left']++;
        }

        return $out;
    }

    /** Corps with a synced roster (the corps an operator manages in SeAT). */
    public function monitoredCorporations(): array
    {
        $ids = [];
        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (Schema::hasTable($table)) {
                $ids = array_merge($ids, DB::table($table)->distinct()->pluck('corporation_id')
                    ->map(fn ($c) => (int) $c)->all());
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    // -----------------------------------------------------------------

    private function classifyJoin(int $corporationId, int $charId, array $knownSet): array
    {
        $userId = $this->userIdForCharacter($charId);
        $mainId = $userId !== null ? $this->mainCharacterId($userId) : null;

        // Known alt: another character on the same account is already a member.
        if ($userId !== null) {
            foreach ($this->accountCharacterIds($userId) as $altId) {
                if ($altId !== $charId && isset($knownSet[$altId])) {
                    return ['type' => 'known_alt', 'main_character_id' => $mainId];
                }
            }
        }

        if ($this->hasValidApplication($charId, $userId)) {
            return ['type' => 'applied', 'main_character_id' => $mainId];
        }

        return ['type' => 'no_application', 'main_character_id' => $mainId];
    }

    private function dispatchJoin(int $corporationId, int $charId, array $cls): void
    {
        try {
            if ($cls['type'] === 'no_application') {
                $this->notifications->notifyJoinNoApplication($corporationId, $charId, $cls['main_character_id']);
            } else {
                $this->notifications->notifyMemberJoined($corporationId, $charId, $cls['type'], $cls['main_character_id']);
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] membership join notify failed: ' . $e->getMessage());
        }
    }

    private function dispatchLeave(int $corporationId, int $charId, ?int $mainId, array $currentSet): void
    {
        $userId = $this->userIdForCharacter($charId);
        $playerStillPresent = false;
        if ($userId !== null) {
            foreach ($this->accountCharacterIds($userId) as $altId) {
                if ($altId !== $charId && isset($currentSet[$altId])) {
                    $playerStillPresent = true;
                    break;
                }
            }
        }

        try {
            $this->notifications->notifyMemberLeft($corporationId, $charId, $mainId, $playerStillPresent);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] membership leave notify failed: ' . $e->getMessage());
        }
    }

    private function seed(int $corporationId, array $charIds): void
    {
        $now = now();
        foreach (array_chunk($charIds, 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $charId) {
                $userId = $this->userIdForCharacter((int) $charId);
                $rows[] = [
                    'corporation_id'    => $corporationId,
                    'character_id'      => (int) $charId,
                    'main_character_id' => $userId !== null ? $this->mainCharacterId($userId) : null,
                    'first_seen_at'     => $now,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
            DB::table('hr_manager_corp_members')->insertOrIgnore($rows);
        }
    }

    private function recordMember(int $corporationId, int $charId, ?int $mainId): void
    {
        CorpMember::updateOrCreate(
            ['corporation_id' => $corporationId, 'character_id' => $charId],
            ['main_character_id' => $mainId, 'first_seen_at' => now()]
        );
    }

    private function rosterSource(int $corporationId): ?string
    {
        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (Schema::hasTable($table)
                && DB::table($table)->where('corporation_id', $corporationId)->limit(1)->exists()) {
                return $table;
            }
        }
        return null;
    }

    private function currentRoster(int $corporationId, string $source): array
    {
        return DB::table($source)->where('corporation_id', $corporationId)
            ->pluck('character_id')
            ->mapWithKeys(fn ($c) => [(int) $c => true])
            ->all();
    }

    private function userIdForCharacter(int $charId): ?int
    {
        try {
            $uid = DB::table('refresh_tokens')->where('character_id', $charId)
                ->whereNull('deleted_at')->value('user_id');
            return $uid !== null ? (int) $uid : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function mainCharacterId(int $userId): ?int
    {
        try {
            $main = optional(\Seat\Web\Models\User::find($userId))->main_character_id;
            return $main !== null ? (int) $main : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<int> every character id linked to the account */
    private function accountCharacterIds(int $userId): array
    {
        try {
            return DB::table('refresh_tokens')->where('user_id', $userId)
                ->whereNull('deleted_at')->pluck('character_id')
                ->map(fn ($c) => (int) $c)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function hasValidApplication(int $charId, ?int $userId): bool
    {
        try {
            $charIds = $userId !== null ? $this->accountCharacterIds($userId) : [];
            if (!in_array($charId, $charIds, true)) {
                $charIds[] = $charId;
            }
            return Application::whereIn('character_id', $charIds)
                ->whereNotIn('status', ['rejected', 'withdrawn'])
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

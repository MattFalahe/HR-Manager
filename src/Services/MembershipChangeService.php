<?php

namespace HrManager\Services;

use HrManager\Models\Application;
use HrManager\Models\CorpMember;
use HrManager\Models\MembershipEvent;
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
        $summary = ['corps' => 0, 'seeded' => 0, 'joined' => 0, 'left' => 0, 'no_application' => 0, 'unregistered' => 0, 'registered_cleared' => 0];

        if (!Schema::hasTable('hr_manager_corp_members')) {
            return $summary;
        }

        foreach ($this->monitoredCorporations() as $corpId) {
            $res = $this->detectForCorporation($corpId, $dryRun);
            foreach (['seeded', 'joined', 'left', 'no_application', 'unregistered', 'registered_cleared'] as $k) {
                $summary[$k] += $res[$k];
            }
            $summary['corps']++;
        }

        return $summary;
    }

    public function detectForCorporation(int $corporationId, bool $dryRun = false): array
    {
        $out = ['seeded' => 0, 'joined' => 0, 'left' => 0, 'no_application' => 0, 'unregistered' => 0, 'registered_cleared' => 0];

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
            $registered = $cls['type'] !== 'unregistered';
            if (!$dryRun) {
                // Always own the snapshot state; only notify + log when the fast
                // path (MC notification handler) hasn't already done so.
                $this->recordMember($corporationId, (int) $charId, $cls['main_character_id'], $registered);
                if (!$this->recentlyHandled($corporationId, (int) $charId, [MembershipEvent::CHANGE_JOINED])) {
                    $this->dispatchJoin($corporationId, (int) $charId, $cls);
                }
            }
            $out['joined']++;
            if ($cls['type'] === 'no_application') {
                $out['no_application']++;
            } elseif ($cls['type'] === 'unregistered') {
                $out['unregistered']++;
            }
        }

        // Leaves.
        $leftRows = CorpMember::where('corporation_id', $corporationId)
            ->whereNotIn('character_id', array_keys($current))->get();
        foreach ($leftRows as $row) {
            if (!$dryRun) {
                if (!$this->recentlyHandled($corporationId, (int) $row->character_id, [MembershipEvent::CHANGE_LEFT])) {
                    $this->dispatchLeave(
                        $corporationId,
                        (int) $row->character_id,
                        $row->main_character_id !== null ? (int) $row->main_character_id : null,
                        $current
                    );
                }
                $row->delete();
            }
            $out['left']++;
        }

        // Clear the unregistered flag for any current member who has since
        // linked a SeAT account (registered as a main or an alt).
        if (!$dryRun) {
            $out['registered_cleared'] = $this->clearResolvedRegistrations($corporationId, $current);
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

    /**
     * Fast-path join from an MC ESI notification (CorpAppAcceptMsg). Notifies +
     * logs immediately (~2 min) WITHOUT touching the corp_members snapshot — the
     * roster-diff still owns membership state and skips re-notifying via
     * recentlyHandled(). No-op for an untracked corp, an already-known member,
     * or a duplicate notification.
     */
    public function handleJoinNotification(int $corporationId, int $charId): bool
    {
        if ($corporationId <= 0 || $charId <= 0 || !Schema::hasTable('hr_manager_corp_members')) {
            return false;
        }
        // A director's feed can carry other corps; only act on ones HR tracks.
        if (!in_array($corporationId, $this->monitoredCorporations(), true)) {
            return false;
        }

        $known = CorpMember::where('corporation_id', $corporationId)->pluck('character_id')
            ->map(fn ($c) => (int) $c)->all();
        if (in_array($charId, $known, true)
            || $this->recentlyHandled($corporationId, $charId, [MembershipEvent::CHANGE_JOINED])) {
            return false; // already a member, or already handled
        }

        $cls = $this->classifyJoin($corporationId, $charId, array_flip($known));
        $this->dispatchJoin($corporationId, $charId, $cls); // notify + log; snapshot left to the roster-diff
        return true;
    }

    /**
     * Fast-path voluntary leave from an MC ESI notification (CharLeftCorpMsg).
     * A kick generates no notification, so those are caught by the roster-diff
     * only. Notifies + logs without deleting the snapshot row (the roster-diff
     * deletes it once SeAT's member list catches up, skipping the re-notify).
     */
    public function handleLeaveNotification(int $corporationId, int $charId): bool
    {
        if ($corporationId <= 0 || $charId <= 0 || !Schema::hasTable('hr_manager_corp_members')) {
            return false;
        }

        $row = CorpMember::where('corporation_id', $corporationId)->where('character_id', $charId)->first();
        if ($row === null
            || $this->recentlyHandled($corporationId, $charId, [MembershipEvent::CHANGE_LEFT])) {
            return false; // not a tracked member, or already handled
        }

        $currentSet = array_flip(
            CorpMember::where('corporation_id', $corporationId)->where('character_id', '!=', $charId)
                ->pluck('character_id')->map(fn ($c) => (int) $c)->all()
        );
        $this->dispatchLeave($corporationId, $charId, $row->main_character_id !== null ? (int) $row->main_character_id : null, $currentSet);
        return true;
    }

    /**
     * Has a change of one of these types for this character been logged recently
     * (default 90 min — generous cover for the gap between the fast notification
     * and SeAT's slower member-list sync)? Keeps the fast path and the
     * roster-diff from double-notifying the same change.
     */
    private function recentlyHandled(int $corporationId, int $charId, array $changeTypes, int $minutes = 90): bool
    {
        if (!Schema::hasTable('hr_manager_membership_events')) {
            return false;
        }
        return MembershipEvent::where('corporation_id', $corporationId)
            ->where('character_id', $charId)
            ->whereIn('change_type', $changeTypes)
            ->where('occurred_at', '>=', now()->subMinutes(max(1, $minutes)))
            ->exists();
    }

    /**
     * Logged membership changes for the Corp Health → Membership tab: a recent
     * history plus the unreviewed no-application review queue, with resolved
     * character / main names.
     */
    public function corporationLog(int $corporationId, int $recentDays = 90, int $recentLimit = 50): array
    {
        if (!Schema::hasTable('hr_manager_membership_events')) {
            return ['available' => false];
        }

        $recent = MembershipEvent::where('corporation_id', $corporationId)
            ->where('occurred_at', '>=', now()->subDays(max(1, $recentDays)))
            ->orderByDesc('occurred_at')
            ->limit($recentLimit)
            ->get();

        $queue = MembershipEvent::where('corporation_id', $corporationId)
            ->needsReview()
            ->orderByDesc('occurred_at')
            ->get();

        // Current members not yet linked to a SeAT account — auto-clears once
        // they register. Includes pre-existing members surfaced at seed time
        // (unlike the no-application queue, which is forward-only).
        $pending = Schema::hasTable('hr_manager_corp_members')
            ? CorpMember::where('corporation_id', $corporationId)->unregistered()
                ->orderBy('first_seen_at')->get()
            : collect();

        $ids = $recent->pluck('character_id')
            ->merge($recent->pluck('main_character_id'))
            ->merge($queue->pluck('character_id'))
            ->merge($queue->pluck('main_character_id'))
            ->merge($pending->pluck('character_id'))
            ->filter()->map(fn ($c) => (int) $c)->unique()->all();

        return [
            'available'     => true,
            'recent'        => $recent,
            'review_queue'  => $queue,
            'review_count'  => $queue->count(),
            'pending'       => $pending,
            'pending_count' => $pending->count(),
            'names'         => $this->characterNames($ids),
            'recent_days'   => $recentDays,
        ];
    }

    /** Cheap count of unreviewed no-application flags, for the nav badge. */
    public function reviewCount(int $corporationId): int
    {
        if (!Schema::hasTable('hr_manager_membership_events')) {
            return 0;
        }
        return MembershipEvent::where('corporation_id', $corporationId)->needsReview()->count();
    }

    private function characterNames(array $charIds): array
    {
        $charIds = array_values(array_unique(array_filter(array_map('intval', $charIds))));
        if (empty($charIds)) {
            return [];
        }
        try {
            return app(NameResolutionService::class)->getCharacterNamesWithFallback($charIds);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // -----------------------------------------------------------------

    private function classifyJoin(int $corporationId, int $charId, array $knownSet): array
    {
        $userId = $this->userIdForCharacter($charId);

        // Not linked to any SeAT account = unregistered. The most severe class
        // (no visibility into the character at all), and a precondition the
        // others can't meet — a known alt or an applicant must be registered.
        if ($userId === null) {
            return ['type' => 'unregistered', 'main_character_id' => null];
        }

        $mainId = $this->mainCharacterId($userId);

        // Known alt: another character on the same account is already a member.
        foreach ($this->accountCharacterIds($userId) as $altId) {
            if ($altId !== $charId && isset($knownSet[$altId])) {
                return ['type' => 'known_alt', 'main_character_id' => $mainId];
            }
        }

        if ($this->hasValidApplication($charId, $userId)) {
            return ['type' => 'applied', 'main_character_id' => $mainId];
        }

        return ['type' => 'no_application', 'main_character_id' => $mainId];
    }

    private function dispatchJoin(int $corporationId, int $charId, array $cls): void
    {
        // Log first so the change is recorded even if the webhook send fails.
        $this->logEvent($corporationId, $charId, MembershipEvent::CHANGE_JOINED, $cls['type'], $cls['main_character_id'], false);

        try {
            if ($cls['type'] === 'unregistered') {
                $this->notifications->notifyMemberUnregistered($corporationId, $charId);
            } elseif ($cls['type'] === 'no_application') {
                $this->notifications->notifyJoinNoApplication($corporationId, $charId, $cls['main_character_id']);
            } else {
                $this->notifications->notifyMemberJoined($corporationId, $charId, $cls['type'], $cls['main_character_id']);
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] membership join notify failed: ' . $e->getMessage());
        }
    }

    /**
     * Re-check current members still flagged unregistered: when one has since
     * linked a SeAT account (their own main, or as an alt of any account), clear
     * the flag, capture the resolved main, and log a 'registered' event. No
     * webhook — the cleared flag dropping off the pending-registration list is
     * the signal. Returns the number cleared.
     */
    private function clearResolvedRegistrations(int $corporationId, array $currentSet): int
    {
        $cleared = 0;
        $pending = CorpMember::where('corporation_id', $corporationId)->unregistered()->get();

        foreach ($pending as $row) {
            $charId = (int) $row->character_id;
            // Only act on members still in the corp (a leave is handled above).
            if (!isset($currentSet[$charId])) {
                continue;
            }
            $userId = $this->userIdForCharacter($charId);
            if ($userId === null) {
                continue; // still not registered
            }

            $mainId = $this->mainCharacterId($userId);
            $row->update([
                'is_registered'     => true,
                'registered_at'     => now(),
                'main_character_id' => $mainId,
            ]);
            $this->logEvent($corporationId, $charId, MembershipEvent::CHANGE_REGISTERED, null, $mainId, false);
            $cleared++;
        }

        return $cleared;
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

        $this->logEvent($corporationId, $charId, MembershipEvent::CHANGE_LEFT, null, $mainId, $playerStillPresent);

        try {
            $this->notifications->notifyMemberLeft($corporationId, $charId, $mainId, $playerStillPresent);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] membership leave notify failed: ' . $e->getMessage());
        }
    }

    private function logEvent(int $corporationId, int $charId, string $changeType, ?string $classification, ?int $mainId, bool $playerStillPresent): void
    {
        try {
            if (!Schema::hasTable('hr_manager_membership_events')) {
                return;
            }
            MembershipEvent::create([
                'corporation_id'       => $corporationId,
                'character_id'         => $charId,
                'main_character_id'    => $mainId,
                'change_type'          => $changeType,
                'classification'       => $classification,
                'player_still_present' => $playerStillPresent,
                'occurred_at'          => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] membership event log failed: ' . $e->getMessage());
        }
    }

    private function seed(int $corporationId, array $charIds): void
    {
        $now = now();
        foreach (array_chunk($charIds, 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $charId) {
                $userId = $this->userIdForCharacter((int) $charId);
                $registered = $userId !== null;
                $rows[] = [
                    'corporation_id'    => $corporationId,
                    'character_id'      => (int) $charId,
                    'main_character_id' => $registered ? $this->mainCharacterId($userId) : null,
                    'is_registered'     => $registered,
                    'registered_at'     => $registered ? $now : null,
                    'first_seen_at'     => $now,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
            DB::table('hr_manager_corp_members')->insertOrIgnore($rows);
        }
    }

    private function recordMember(int $corporationId, int $charId, ?int $mainId, bool $registered): void
    {
        CorpMember::updateOrCreate(
            ['corporation_id' => $corporationId, 'character_id' => $charId],
            [
                'main_character_id' => $mainId,
                'is_registered'     => $registered,
                'registered_at'     => $registered ? now() : null,
                'first_seen_at'     => now(),
            ]
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

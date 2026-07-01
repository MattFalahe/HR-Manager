<?php

namespace HrManager\Services;

use HrManager\Models\FcActivity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FC activity — records SeAT Broadcast's pings.broadcast.sent events
 * into HR's own hr_manager_fc_activity table, and computes the
 * fleet-command profile (fleets led, coverage window, cadence, type
 * mix) from that accumulated table.
 *
 * EventBus-first: the ONLY data source is the events HR has received.
 * No reads of SeAT Broadcast's tables. Forward-only — the profile
 * reflects activity since HR subscribed (operator-chosen; no backfill).
 */
class FcActivityService
{
    private const CACHE_TTL = 600; // 10 min

    /**
     * Record one pings.broadcast.sent event. Idempotent via event_id.
     * Resolves the FC's main character for portrait attribution.
     */
    public function record(array $payload): void
    {
        $eventId = $payload['event_id'] ?? null;
        $userId  = (int) ($payload['user_id'] ?? 0);
        if (!$eventId || $userId <= 0) {
            return; // can't attribute or dedup — drop
        }

        try {
            // Resolve the FC's main character for display.
            $characterId = DB::table('users')->where('id', $userId)->value('main_character_id');

            FcActivity::updateOrCreate(
                ['event_id' => (string) $eventId],
                [
                    'kind'               => 'broadcast',
                    'user_id'            => $userId,
                    'character_id'       => $characterId ? (int) $characterId : null,
                    'corporation_id'     => isset($payload['corporation_id']) ? (int) $payload['corporation_id'] : null,
                    'broadcast_type'     => $payload['broadcast_type'] ?? null,
                    'mention_type'       => $payload['mention_type'] ?? null,
                    'is_structure_alert' => (bool) ($payload['is_structure_alert'] ?? false),
                    'is_scheduled'       => (bool) ($payload['is_scheduled'] ?? false),
                    'occurred_at'        => $this->parseTimestamp($payload['timestamp'] ?? null),
                ]
            );

            $this->bustCache($userId);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] FcActivityService::record failed: ' . $e->getMessage());
        }
    }

    /**
     * Record one pings.formup.scheduled event — an FC scheduling a fleet for
     * a tactical event (proactive planning, a stronger leadership signal than
     * a reactive broadcast). Idempotent via event_id. Stores the tactical
     * context (event type, category, severity, structure, system, scheduled
     * time) so the player profile + Corp Health can show WHAT the FC plans.
     */
    public function recordFormup(array $payload): void
    {
        $eventId = $payload['event_id'] ?? null;
        $userId  = (int) ($payload['user_id'] ?? 0);
        if (!$eventId || $userId <= 0) {
            return;
        }

        try {
            $characterId = DB::table('users')->where('id', $userId)->value('main_character_id');
            $te = is_array($payload['tactical_event'] ?? null) ? $payload['tactical_event'] : [];

            $corpId = isset($payload['webhook_corporation_id']) && $payload['webhook_corporation_id'] !== null
                ? (int) $payload['webhook_corporation_id']
                : (isset($te['corporation_id']) && $te['corporation_id'] !== null ? (int) $te['corporation_id'] : null);

            FcActivity::updateOrCreate(
                ['event_id' => (string) $eventId],
                [
                    'kind'               => 'formup',
                    'user_id'            => $userId,
                    'character_id'       => $characterId ? (int) $characterId : null,
                    'corporation_id'     => $corpId,
                    'broadcast_type'     => $te['event_type'] ?? null,   // tactical event type
                    'category_group'     => $te['category_group'] ?? null,
                    'severity'           => $te['severity'] ?? null,
                    'structure_name'     => $te['structure_name'] ?? null,
                    'system_name'        => $te['system_name'] ?? null,
                    'scheduled_for'      => isset($payload['scheduled_at']) ? $this->parseTimestamp($payload['scheduled_at']) : null,
                    'is_structure_alert' => false,
                    'is_scheduled'       => true,
                    // occurred_at = when the FC scheduled it (the publish
                    // timestamp), distinct from scheduled_for (the fleet's set
                    // time). Falls back to now() if the envelope omits it.
                    'occurred_at'        => $this->parseTimestamp($payload['timestamp'] ?? null),
                ]
            );

            $this->bustCache($userId);
            if ($corpId) {
                $this->bustCorpCache($corpId);
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] FcActivityService::recordFormup failed: ' . $e->getMessage());
        }
    }

    /**
     * FC profile for one SeAT user (human-level — broadcasts are sent
     * by a user, not a character). Excludes automated structure-defense
     * pings from the fleet counts.
     *
     * @return array{
     *   available: bool, is_fc: bool, total: int, window_days: ?int,
     *   first_at: ?string, last_at: ?string, per_week: float,
     *   by_type: array, recent: array
     * }
     */
    public function getForUser(int $userId, int $months = 6): array
    {
        if ($userId <= 0) {
            return ['available' => false, 'is_fc' => false, 'total' => 0];
        }

        return Cache::remember(
            "hr-fc-{$userId}-{$months}",
            self::CACHE_TTL,
            fn() => $this->buildForUser($userId, $months)
        );
    }

    public function bustCache(int $userId, int $months = 6): void
    {
        Cache::forget("hr-fc-{$userId}-{$months}");
    }

    // -----------------------------------------------------------------

    private function buildForUser(int $userId, int $months): array
    {
        $since = now()->subMonths($months);

        // --- Reactive broadcasts (pings.broadcast.sent) ---
        $rows = FcActivity::forUser($userId)
            ->broadcasts()
            ->fleetActivity()
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->get(['broadcast_type', 'occurred_at']);
        $total = $rows->count();

        // --- Proactive formups (pings.formup.scheduled) ---
        $formups = FcActivity::forUser($userId)
            ->formups()
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->get(['category_group', 'severity', 'structure_name', 'system_name', 'scheduled_for', 'occurred_at']);
        $formupTotal = $formups->count();

        if ($total === 0 && $formupTotal === 0) {
            return ['available' => true, 'is_fc' => false, 'total' => 0, 'formups_total' => 0];
        }

        // Broadcast cadence + window.
        $firstAt = $total ? $rows->min('occurred_at') : null;
        $lastAt  = $total ? $rows->max('occurred_at') : null;
        $windowDays = ($firstAt && $lastAt)
            ? max(1, (int) \Carbon\Carbon::parse($firstAt)->diffInDays(\Carbon\Carbon::parse($lastAt)))
            : 1;
        $perWeek = $total ? round($total / max(1, $windowDays / 7), 1) : 0.0;

        // Broadcast type mix.
        $byType = [];
        foreach ($rows->groupBy('broadcast_type') as $type => $group) {
            $byType[] = ['type' => $type ?: 'message', 'count' => $group->count()];
        }
        usort($byType, fn($a, $b) => $b['count'] <=> $a['count']);

        // Formup category split (defense / offense / etc.).
        $byCategory = [];
        foreach ($formups->groupBy('category_group') as $cat => $group) {
            $byCategory[] = ['category' => $cat ?: 'other', 'count' => $group->count()];
        }
        usort($byCategory, fn($a, $b) => $b['count'] <=> $a['count']);

        // Upcoming planned ops (scheduled_for in the future).
        $upcoming = $formups
            ->filter(fn ($r) => $r->scheduled_for && \Carbon\Carbon::parse($r->scheduled_for)->isFuture())
            ->sortBy('scheduled_for')
            ->take(5)
            ->map(fn ($r) => [
                'category'      => $r->category_group,
                'severity'      => $r->severity,
                'structure'     => $r->structure_name,
                'system'        => $r->system_name,
                'scheduled_for' => $r->scheduled_for instanceof \Carbon\Carbon ? $r->scheduled_for->toIso8601String() : (string) $r->scheduled_for,
            ])->values()->all();

        $lastFormupAt = $formupTotal ? $formups->max('occurred_at') : null;

        return [
            'available'   => true,
            // FC if they reactively broadcast (3+) OR proactively plan (2+).
            'is_fc'       => $total >= 3 || $formupTotal >= 2,
            'total'       => $total,
            'window_days' => $windowDays,
            'first_at'    => $firstAt instanceof \Carbon\Carbon ? $firstAt->toIso8601String() : ($firstAt ? (string) $firstAt : null),
            'last_at'     => $lastAt instanceof \Carbon\Carbon ? $lastAt->toIso8601String() : ($lastAt ? (string) $lastAt : null),
            'per_week'    => $perWeek,
            'by_type'     => $byType,
            // Planning (formups).
            'formups_total'       => $formupTotal,
            'formups_by_category' => $byCategory,
            'upcoming_formups'    => $upcoming,
            'last_formup_at'      => $lastFormupAt instanceof \Carbon\Carbon ? $lastFormupAt->toIso8601String() : ($lastFormupAt ? (string) $lastFormupAt : null),
        ];
    }

    /**
     * Corp-level FC roster + status. Buckets every FC (3+ broadcasts in
     * window) whose main character is in the corp into:
     *   - active   : broadcast within the last 30 days
     *   - inactive : qualified FC but no broadcast in 30 days (died out)
     *   - new      : first broadcast within the last 30 days (just started)
     *                — a subset/overlay flag, also counted in active
     *
     * Plus a per-FC frequency (broadcasts/month) and a ranking by total.
     *
     * All from HR's own EventBus-accumulated table. Forward-only caveat:
     * "new FC" detection is only meaningful once HR has accumulated
     * history — in the first ~30 days post-install everyone reads as new.
     *
     * @return array{available: bool, active: array, inactive: array,
     *               new_count: int, total_fcs: int, window_months: int}
     */
    public function getCorpFcStatus(int $corporationId, int $months = 6): array
    {
        return Cache::remember(
            "hr-fc-corp-{$corporationId}-{$months}",
            self::CACHE_TTL,
            fn() => $this->buildCorpFcStatus($corporationId, $months)
        );
    }

    public function bustCorpCache(int $corporationId, int $months = 6): void
    {
        Cache::forget("hr-fc-corp-{$corporationId}-{$months}");
    }

    private function buildCorpFcStatus(int $corporationId, int $months): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('hr_manager_fc_activity')) {
            return ['available' => false];
        }

        $since = now()->subMonths($months);
        $activeCutoff = now()->subDays(30);

        // Reactive broadcasts grouped per FC (the leaderboard).
        $bRows = FcActivity::broadcasts()->fleetActivity()
            ->where('occurred_at', '>=', $since)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as total, MIN(occurred_at) as first_at, MAX(occurred_at) as last_at')
            ->havingRaw('COUNT(*) >= 3') // FC threshold
            ->get();

        // Proactive formups grouped per FC (the organizers).
        $fRows = FcActivity::formups()
            ->where('occurred_at', '>=', $since)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as total, MAX(occurred_at) as last_at, MAX(scheduled_for) as next_at')
            ->get();

        if ($bRows->isEmpty() && $fRows->isEmpty()) {
            return ['available' => true, 'active' => [], 'inactive' => [], 'organizers' => [],
                    'new_count' => 0, 'total_fcs' => 0, 'total_formups' => 0, 'window_months' => $months];
        }

        // Resolve corp + name once for the union of broadcasters + organizers.
        $userIds = $bRows->pluck('user_id')->merge($fRows->pluck('user_id'))->unique()->values()->all();
        $userMeta = DB::table('users')
            ->whereIn('users.id', $userIds)
            ->leftJoin('character_affiliations as ca', 'ca.character_id', '=', 'users.main_character_id')
            ->leftJoin('character_infos as ci', 'ci.character_id', '=', 'users.main_character_id')
            ->get(['users.id as user_id', 'users.main_character_id', 'ca.corporation_id', 'ci.name'])
            ->keyBy('user_id');

        $formupByUser = $fRows->keyBy('user_id');
        $windowDays = max(1, (int) $since->diffInDays(now()));

        $active = [];
        $inactive = [];
        $newCount = 0;

        foreach ($bRows as $r) {
            $meta = $userMeta[$r->user_id] ?? null;
            if (!$meta || (int) ($meta->corporation_id ?? 0) !== $corporationId) {
                continue; // FC not in this corp
            }

            $lastAt  = \Carbon\Carbon::parse($r->last_at);
            $firstAt = \Carbon\Carbon::parse($r->first_at);
            $isActive = $lastAt->greaterThanOrEqualTo($activeCutoff);
            $isNew    = $firstAt->greaterThanOrEqualTo($activeCutoff);
            $perMonth = round($r->total / max(1, $windowDays / 30), 1);

            $entry = [
                'user_id'      => (int) $r->user_id,
                'character_id' => $meta->main_character_id ? (int) $meta->main_character_id : null,
                'name'         => $meta->name ?? ('User #' . $r->user_id),
                'total'        => (int) $r->total,
                'per_month'    => $perMonth,
                'last_at'      => $lastAt->toIso8601String(),
                'first_at'     => $firstAt->toIso8601String(),
                'is_new'       => $isNew,
                'formups'      => isset($formupByUser[$r->user_id]) ? (int) $formupByUser[$r->user_id]->total : 0,
            ];

            if ($isActive) {
                $active[] = $entry;
                if ($isNew) $newCount++;
            } else {
                $inactive[] = $entry;
            }
        }

        usort($active, fn($a, $b) => $b['total'] <=> $a['total']);
        usort($inactive, fn($a, $b) => strcmp($b['last_at'], $a['last_at']));

        // Organizers — FCs who scheduled formups (proactive planning), ranked
        // by count. Includes planners who never reactively broadcast.
        $organizers = [];
        $totalFormups = 0;
        foreach ($fRows as $f) {
            $meta = $userMeta[$f->user_id] ?? null;
            if (!$meta || (int) ($meta->corporation_id ?? 0) !== $corporationId) {
                continue;
            }
            $totalFormups += (int) $f->total;
            $organizers[] = [
                'user_id'      => (int) $f->user_id,
                'character_id' => $meta->main_character_id ? (int) $meta->main_character_id : null,
                'name'         => $meta->name ?? ('User #' . $f->user_id),
                'formups'      => (int) $f->total,
                'last_at'      => $f->last_at ? \Carbon\Carbon::parse($f->last_at)->toIso8601String() : null,
                'next_at'      => $f->next_at ? \Carbon\Carbon::parse($f->next_at)->toIso8601String() : null,
            ];
        }
        usort($organizers, fn($a, $b) => $b['formups'] <=> $a['formups']);

        return [
            'available'     => true,
            'active'        => $active,
            'inactive'      => $inactive,
            'organizers'    => $organizers,
            'new_count'     => $newCount,
            'total_fcs'     => count($active) + count($inactive),
            'total_formups' => $totalFormups,
            'window_months' => $months,
        ];
    }

    private function parseTimestamp($ts): \Carbon\Carbon
    {
        try {
            return $ts ? \Carbon\Carbon::parse($ts) : now();
        } catch (\Throwable $e) {
            return now();
        }
    }
}

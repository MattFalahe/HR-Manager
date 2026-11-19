<?php

namespace HrManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Turns voice samples into sessions, and reads them back.
 *
 * A sample stands for its whole poll interval: a session starts half an
 * interval before the first sample that found the member and ends half an
 * interval after the last one. One missed sample (a skipped run, a worker
 * restart) does not split a session; two do.
 */
class VoiceSessionService
{
    private const TABLE = 'hr_manager_voice_sessions';

    /** Samples a session survives without a sighting before it is closed. */
    private const STALE_AFTER_INTERVALS = 2.5;

    public function isAvailable(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * What one sample does to the member's open session. Pure.
     *
     * @param  object|null  $open    the member's open session row, if any
     * @param  array{state:string, channel_id:?string}  $sample
     * @return array<int, array{0:string, 1:array<string,mixed>}>  ops: close / touch / open
     */
    public static function planSample(?object $open, array $sample, Carbon $now, int $intervalMinutes): array
    {
        $state = $sample['state'] ?? 'error';

        // We learned nothing about this member; leave their session alone.
        if ($state === 'error') {
            return [];
        }

        $half = (int) round($intervalMinutes * 30);
        $ops = [];

        if ($open !== null) {
            $lastSeen = self::stamp($open->last_seen_at ?? null) ?? self::stamp($open->started_at ?? null);
            $staleBefore = $now->copy()->subSeconds((int) round($intervalMinutes * 60 * self::STALE_AFTER_INTERVALS));
            if ($lastSeen === null || $lastSeen->lt($staleBefore)) {
                $ops[] = ['close', ['ended_at' => self::endAfter($lastSeen, $open, $half)]];
                $open = null;
            }
        }

        $inVoice = in_array($state, ['in_voice', 'hidden'], true);

        if (!$inVoice) {
            if ($open !== null) {
                $ops[] = ['close', ['ended_at' => self::endAfter(self::stamp($open->last_seen_at ?? null), $open, $half)]];
            }

            return $ops;
        }

        $hidden = $state === 'hidden';
        $channelId = $hidden ? null : ($sample['channel_id'] ?? null);

        if ($open !== null && (bool) ($open->hidden_channel ?? false) === $hidden && ($open->channel_id ?? null) === $channelId) {
            $ops[] = ['touch', ['last_seen_at' => $now->copy(), 'samples' => ((int) ($open->samples ?? 1)) + 1]];

            return $ops;
        }

        if ($open !== null) {
            // Moved channel: the old session ends where the new one starts.
            $ops[] = ['close', ['ended_at' => $now->copy()->subSeconds($half)]];
        }

        $ops[] = ['open', [
            'channel_id'     => $channelId,
            'hidden_channel' => $hidden,
            'started_at'     => $now->copy()->subSeconds($half),
            'last_seen_at'   => $now->copy(),
            'ended_at'       => null,
            'samples'        => 1,
        ]];

        return $ops;
    }

    /**
     * Seconds in voice inside [$since, $until] for each session row given.
     * Open sessions count up to their last sample. Pure.
     *
     * @param  iterable<object>  $rows
     */
    public static function secondsInWindow(iterable $rows, Carbon $since, Carbon $until): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $start = self::stamp($row->started_at ?? null);
            $end = self::stamp($row->ended_at ?? null) ?? self::stamp($row->last_seen_at ?? null);
            if ($start === null || $end === null) {
                continue;
            }
            $s = $start->lt($since) ? $since : $start;
            $e = $end->gt($until) ? $until : $end;
            if ($e->gt($s)) {
                $total += $e->getTimestamp() - $s->getTimestamp();
            }
        }

        return $total;
    }

    /**
     * Write one run's samples.
     *
     * @param  array<int, array{user_id:int, discord_id:string, state:string, channel_id:?string}>  $samples
     * @param  array<string, string>  $channelNames
     * @return array{opened:int, closed:int, touched:int}
     */
    public function applySamples(array $samples, Carbon $now, int $intervalMinutes, array $channelNames = []): array
    {
        $counts = ['opened' => 0, 'closed' => 0, 'touched' => 0];
        if (empty($samples) || !$this->isAvailable()) {
            return $counts;
        }

        $userIds = array_values(array_unique(array_map(fn ($s) => (int) $s['user_id'], $samples)));
        $open = [];
        foreach (array_chunk($userIds, 1000) as $chunk) {
            foreach (DB::table(self::TABLE)->whereIn('user_id', $chunk)->whereNull('ended_at')->get() as $row) {
                // Should be one per member; if a crash ever left two, the newest wins
                // and the stale sweep closes the other.
                $uid = (int) $row->user_id;
                if (!isset($open[$uid]) || (string) $row->started_at > (string) $open[$uid]->started_at) {
                    $open[$uid] = $row;
                }
            }
        }

        foreach ($samples as $sample) {
            $uid = (int) $sample['user_id'];
            $current = $open[$uid] ?? null;

            foreach (self::planSample($current, $sample, $now, $intervalMinutes) as [$kind, $fields]) {
                try {
                    $fields = array_map(fn ($v) => $v instanceof Carbon ? $v->toDateTimeString() : $v, $fields);

                    if ($kind === 'open') {
                        $channelId = $fields['channel_id'];
                        $channelName = null;
                        if ($channelId !== null && ($channelNames[$channelId] ?? '') !== '') {
                            $channelName = mb_substr((string) $channelNames[$channelId], 0, 100);
                        }
                        DB::table(self::TABLE)->insert(array_merge($fields, [
                            'user_id'         => $uid,
                            'discord_user_id' => (string) $sample['discord_id'],
                            'channel_name'    => $channelName,
                            'created_at'      => $now->toDateTimeString(),
                            'updated_at'      => $now->toDateTimeString(),
                        ]));
                        $counts['opened']++;
                        continue;
                    }

                    if ($current === null) {
                        continue;
                    }

                    DB::table(self::TABLE)->where('id', $current->id)
                        ->update(array_merge($fields, ['updated_at' => $now->toDateTimeString()]));
                    $counts[$kind === 'close' ? 'closed' : 'touched']++;
                } catch (\Throwable $e) {
                    Log::warning('[HR Manager] voice session write failed for user ' . $uid . ': ' . $e->getMessage());
                }
            }
        }

        return $counts;
    }

    /**
     * Close sessions nobody has sampled for a while: the member was unlinked,
     * left the guild, or tracking was switched off mid-session.
     */
    public function closeStale(Carbon $now, int $intervalMinutes): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $half = (int) round($intervalMinutes * 30);
        $cutoff = $now->copy()->subSeconds((int) round($intervalMinutes * 60 * self::STALE_AFTER_INTERVALS));

        return DB::table(self::TABLE)
            ->whereNull('ended_at')
            ->where('last_seen_at', '<', $cutoff->toDateTimeString())
            ->update([
                'ended_at'   => DB::raw('DATE_ADD(last_seen_at, INTERVAL ' . $half . ' SECOND)'),
                'updated_at' => $now->toDateTimeString(),
            ]);
    }

    /**
     * Seconds in voice since $since for one account.
     */
    public function secondsSince(int $userId, Carbon $since, ?Carbon $now = null): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $now = $now ?? now();

        return self::secondsInWindow($this->rowsSince([$userId], $since), $since, $now);
    }

    /**
     * Last time each account was in voice, and whether they are now.
     *
     * @param  array<int>  $userIds
     * @return array<int, array{last:?Carbon, now:bool, channel:?string, hidden:bool}>
     */
    public function lastInVoice(array $userIds, int $intervalMinutes, ?Carbon $now = null): array
    {
        $out = [];
        if (empty($userIds) || !$this->isAvailable()) {
            return $out;
        }
        $now = $now ?? now();
        $liveAfter = $now->copy()->subSeconds((int) round($intervalMinutes * 60 * self::STALE_AFTER_INTERVALS));

        foreach (array_chunk(array_values(array_unique($userIds)), 1000) as $chunk) {
            $rows = DB::table(self::TABLE)
                ->whereIn('user_id', $chunk)
                ->groupBy('user_id')
                ->selectRaw('user_id, MAX(COALESCE(ended_at, last_seen_at)) AS last_at')
                ->get();
            foreach ($rows as $row) {
                $out[(int) $row->user_id] = ['last' => self::stamp($row->last_at), 'now' => false, 'channel' => null, 'hidden' => false];
            }

            $live = DB::table(self::TABLE)
                ->whereIn('user_id', $chunk)
                ->whereNull('ended_at')
                ->where('last_seen_at', '>=', $liveAfter->toDateTimeString())
                ->get(['user_id', 'channel_name', 'hidden_channel']);
            foreach ($live as $row) {
                $uid = (int) $row->user_id;
                $out[$uid]['now'] = true;
                $out[$uid]['channel'] = $row->channel_name;
                $out[$uid]['hidden'] = (bool) $row->hidden_channel;
                $out[$uid]['last'] = $out[$uid]['last'] ?? $now->copy();
            }
        }

        return $out;
    }

    /**
     * Per-account voice summary for the player profile.
     *
     * @return array{available:bool, seconds_7d:int, seconds_30d:int, sessions_30d:int, top_channels:array<int, array{name:string, seconds:int}>, hidden_seconds_30d:int}
     */
    public function summaryForUser(int $userId, ?Carbon $now = null): array
    {
        $empty = ['available' => false, 'seconds_7d' => 0, 'seconds_30d' => 0, 'sessions_30d' => 0, 'top_channels' => [], 'hidden_seconds_30d' => 0];
        if (!$this->isAvailable()) {
            return $empty;
        }
        $now = $now ?? now();
        $since30 = $now->copy()->subDays(30);
        $since7 = $now->copy()->subDays(7);

        $rows = $this->rowsSince([$userId], $since30);

        $channels = [];
        $hidden = 0;
        foreach ($rows as $row) {
            $seconds = self::secondsInWindow([$row], $since30, $now);
            if ((bool) $row->hidden_channel) {
                $hidden += $seconds;
                continue;
            }
            $name = (string) ($row->channel_name ?: ('#' . ($row->channel_id ?? '?')));
            $channels[$name] = ($channels[$name] ?? 0) + $seconds;
        }
        arsort($channels);

        $top = [];
        foreach (array_slice($channels, 0, 3, true) as $name => $seconds) {
            $top[] = ['name' => $name, 'seconds' => $seconds];
        }

        return [
            'available'          => true,
            'seconds_7d'         => self::secondsInWindow($rows, $since7, $now),
            'seconds_30d'        => self::secondsInWindow($rows, $since30, $now),
            'sessions_30d'       => count($rows),
            'top_channels'       => $top,
            'hidden_seconds_30d' => $hidden,
        ];
    }

    public function trackingSince(): ?Carbon
    {
        if (!$this->isAvailable()) {
            return null;
        }

        return self::stamp(DB::table(self::TABLE)->min('created_at'));
    }

    public function prune(Carbon $before, bool $dryRun = false): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $query = DB::table(self::TABLE)->whereNotNull('ended_at')->where('ended_at', '<', $before->toDateTimeString());

        return $dryRun ? $query->count() : $query->delete();
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<int>  $userIds
     */
    private function rowsSince(array $userIds, Carbon $since)
    {
        return DB::table(self::TABLE)
            ->whereIn('user_id', $userIds)
            ->where(function ($q) use ($since) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $since->toDateTimeString());
            })
            ->get(['id', 'user_id', 'channel_id', 'channel_name', 'hidden_channel', 'started_at', 'last_seen_at', 'ended_at']);
    }

    private static function endAfter(?Carbon $lastSeen, object $open, int $half): Carbon
    {
        $base = $lastSeen ?? self::stamp($open->started_at ?? null) ?? Carbon::now();

        return $base->copy()->addSeconds($half);
    }

    private static function stamp($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value->copy();
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * EVE play sessions, copied out of SeAT's character_onlines.
 *
 * SeAT overwrites last_login / last_logout on every refresh, so this keeps the
 * sessions it sees in hr_manager_eve_sessions. Nothing here calls ESI: SeAT
 * already fetched the data with the member's own token, and HR only reads it.
 * A character without the esi-location.read_online.v1 scope has no row in
 * character_onlines and simply never gets sessions.
 */
class EveSessionService
{
    public const SETTING_ENABLED = 'activity_eve_sessions_enabled';

    /**
     * SeAT refreshes a character about once an hour. A snapshot older than
     * this still saying "online" is a dead token, not a long session.
     */
    public const STALE_ONLINE_HOURS = 3;

    private const CHUNK = 500;

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_ENABLED, true);
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('character_onlines') && Schema::hasTable('hr_manager_eve_sessions');
    }

    /**
     * What one character_onlines snapshot does to the character's latest
     * stored session. Pure: no reads, no writes, so the rules can be tested on
     * their own.
     *
     * Each op is [kind, fields]: 'open' inserts a session, 'close' and 'touch'
     * update the latest one.
     *
     * @param  object|null  $latest    newest hr_manager_eve_sessions row for the character
     * @param  object       $snapshot  the character_onlines row
     * @return array<int, array{0:string, 1:array<string,mixed>}>
     */
    public static function plan(?object $latest, object $snapshot, Carbon $now): array
    {
        $login = self::stamp($snapshot->last_login ?? null);
        if ($login === null) {
            return [];
        }

        $logout = self::stamp($snapshot->last_logout ?? null);
        $seenAt = self::stamp($snapshot->updated_at ?? null) ?? $now->copy();
        $fresh  = $seenAt->gte($now->copy()->subHours(self::STALE_ONLINE_HOURS));
        $online = !empty($snapshot->online) && $fresh;
        $logins = isset($snapshot->logins) ? (int) $snapshot->logins : null;

        $latestStart = $latest ? self::stamp($latest->started_at ?? null) : null;

        // A snapshot older than what we already hold: SeAT re-read a stale
        // value, nothing to learn from it.
        if ($latestStart !== null && $login->lt($latestStart)) {
            return [];
        }

        if ($latestStart !== null && $login->eq($latestStart)) {
            if (($latest->ended_at ?? null) !== null) {
                return [];
            }
            if ($online) {
                return [['touch', ['last_seen_at' => $seenAt]]];
            }

            return [['close', self::endOfSameSession($latest, $login, $logout)]];
        }

        $ops = [];

        // A login we have not seen yet. If the previous session is still open,
        // it ended before this login, and the newest logout is its end unless
        // that logout belongs to the new session.
        if ($latest !== null && ($latest->ended_at ?? null) === null) {
            $ops[] = ['close', self::endBeforeLogin($latest, $login, $logout, $logins)];
        }

        $row = [
            'started_at'      => $login,
            'ended_at'        => null,
            'last_seen_at'    => null,
            'logins_at_start' => $logins,
            'merged_logins'   => 0,
            'end_estimated'   => false,
        ];

        if ($online) {
            $row['last_seen_at'] = $seenAt;
        } elseif ($logout !== null && $logout->gte($login)) {
            $row['ended_at']     = $logout;
            $row['last_seen_at'] = $logout;
        } else {
            // Offline with no logout after the login (a crash ESI never
            // recorded). Nothing says how long it lasted, so it counts as
            // nothing rather than as a guess.
            $row['ended_at']      = $login;
            $row['end_estimated'] = true;
        }

        $ops[] = ['open', $row];

        return $ops;
    }

    /**
     * Union of the given intervals, clipped to [from, to], in seconds.
     * Two alts played side by side count once: this is time the person was in
     * the game, not character-hours.
     *
     * @param  array<int, array{0:Carbon, 1:Carbon}>  $intervals
     */
    public static function mergedSeconds(array $intervals, Carbon $from, Carbon $to): int
    {
        $clipped = [];
        foreach ($intervals as [$start, $end]) {
            $s = $start->lt($from) ? $from : $start;
            $e = $end->gt($to) ? $to : $end;
            if ($e->gt($s)) {
                $clipped[] = [$s->getTimestamp(), $e->getTimestamp()];
            }
        }
        if (empty($clipped)) {
            return 0;
        }

        usort($clipped, fn ($a, $b) => $a[0] <=> $b[0]);

        $total = 0;
        [$curStart, $curEnd] = $clipped[0];
        foreach (array_slice($clipped, 1) as [$s, $e]) {
            if ($s <= $curEnd) {
                $curEnd = max($curEnd, $e);
                continue;
            }
            $total += $curEnd - $curStart;
            [$curStart, $curEnd] = [$s, $e];
        }

        return $total + ($curEnd - $curStart);
    }

    /**
     * Read character_onlines and record what changed. Returns counts for the
     * command's output.
     *
     * @return array{available:bool, characters:int, opened:int, closed:int, touched:int}
     */
    public function record(?Carbon $now = null): array
    {
        $stats = ['available' => false, 'characters' => 0, 'opened' => 0, 'closed' => 0, 'touched' => 0];
        if (!$this->isAvailable()) {
            return $stats;
        }
        $stats['available'] = true;
        $now = $now ?? now();

        DB::table('character_onlines')
            ->orderBy('character_id')
            ->chunk(self::CHUNK, function ($rows) use (&$stats, $now) {
                $ids = $rows->pluck('character_id')->map(fn ($id) => (int) $id)->all();
                $latest = $this->latestSessions($ids);

                foreach ($rows as $snapshot) {
                    $characterId = (int) $snapshot->character_id;
                    $stats['characters']++;
                    $current = $latest[$characterId] ?? null;

                    foreach (self::plan($current, $snapshot, $now) as [$kind, $fields]) {
                        try {
                            $this->apply($characterId, $current, $kind, $fields, $now);
                            $stats[$kind === 'open' ? 'opened' : ($kind === 'close' ? 'closed' : 'touched')]++;
                        } catch (\Throwable $e) {
                            Log::warning('[HR Manager] EVE session write failed for character ' . $characterId . ': ' . $e->getMessage());
                        }
                    }
                }
            });

        return $stats;
    }

    /**
     * Last time each character was in the game, straight from SeAT's snapshot,
     * so it works before HR has recorded a single session.
     *
     * @param  array<int>  $characterIds
     * @return array<int, array{last:?Carbon, online:bool}>
     */
    public function lastInGame(array $characterIds, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $out = [];
        if (empty($characterIds) || !Schema::hasTable('character_onlines')) {
            return $out;
        }

        foreach (array_chunk(array_values(array_unique($characterIds)), 1000) as $chunk) {
            $rows = DB::table('character_onlines')
                ->whereIn('character_id', $chunk)
                ->get(['character_id', 'online', 'last_login', 'last_logout', 'updated_at']);

            foreach ($rows as $row) {
                $login  = self::stamp($row->last_login ?? null);
                $logout = self::stamp($row->last_logout ?? null);
                $seenAt = self::stamp($row->updated_at ?? null);
                $online = !empty($row->online)
                    && $seenAt !== null
                    && $seenAt->gte($now->copy()->subHours(self::STALE_ONLINE_HOURS));

                $last = $login;
                if ($logout !== null && ($last === null || $logout->gt($last))) {
                    $last = $logout;
                }
                if ($online) {
                    $last = $seenAt;
                }

                $out[(int) $row->character_id] = ['last' => $last, 'online' => $online];
            }
        }

        return $out;
    }

    /**
     * Seconds in the game since $since, merged across the given characters.
     *
     * @param  array<int>  $characterIds
     */
    public function secondsSince(array $characterIds, Carbon $since, ?Carbon $now = null): int
    {
        $now = $now ?? now();

        return self::mergedSeconds($this->intervals($characterIds, $since), $since, $now);
    }

    /**
     * Stored sessions overlapping [$since, now] as [start, end] pairs. An open
     * session ends at the last time SeAT confirmed the character online.
     *
     * @param  array<int>  $characterIds
     * @return array<int, array{0:Carbon, 1:Carbon}>
     */
    public function intervals(array $characterIds, Carbon $since): array
    {
        $out = [];
        if (empty($characterIds) || !Schema::hasTable('hr_manager_eve_sessions')) {
            return $out;
        }

        foreach (array_chunk(array_values(array_unique($characterIds)), 1000) as $chunk) {
            // Bounded on started_at so the index does the work. A session
            // that began more than two days before the window and is still
            // overlapping it is not a play session anyone has.
            $rows = DB::table('hr_manager_eve_sessions')
                ->whereIn('character_id', $chunk)
                ->where('started_at', '>=', $since->copy()->subDays(2))
                ->get(['started_at', 'ended_at', 'last_seen_at']);

            foreach ($rows as $row) {
                $start = self::stamp($row->started_at ?? null);
                $end   = self::stamp($row->ended_at ?? null) ?? self::stamp($row->last_seen_at ?? null);
                if ($start !== null && $end !== null && $end->gte($since)) {
                    $out[] = [$start, $end];
                }
            }
        }

        return $out;
    }

    /**
     * When HR started keeping sessions, so a panel can say "since 3 days ago"
     * instead of passing off a short history as a whole month.
     */
    public function trackingSince(): ?Carbon
    {
        if (!Schema::hasTable('hr_manager_eve_sessions')) {
            return null;
        }

        return self::stamp(DB::table('hr_manager_eve_sessions')->min('created_at'));
    }

    /**
     * Drop sessions that ended before the cut-off. Returns the rows removed.
     */
    public function prune(Carbon $before, bool $dryRun = false): int
    {
        if (!Schema::hasTable('hr_manager_eve_sessions')) {
            return 0;
        }

        $query = DB::table('hr_manager_eve_sessions')
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $before);

        return $dryRun ? $query->count() : $query->delete();
    }

    // -----------------------------------------------------------------

    private static function endOfSameSession(object $latest, Carbon $login, ?Carbon $logout): array
    {
        if ($logout !== null && $logout->gte($login)) {
            return ['ended_at' => $logout, 'end_estimated' => false];
        }

        // Offline now, but no logout after the login. The last time we saw
        // them online is the latest moment we can vouch for.
        $lastSeen = self::stamp($latest->last_seen_at ?? null);

        return [
            'ended_at'      => ($lastSeen !== null && $lastSeen->gt($login)) ? $lastSeen : $login,
            'end_estimated' => true,
        ];
    }

    private static function endBeforeLogin(object $latest, Carbon $newLogin, ?Carbon $logout, ?int $logins): array
    {
        $start = self::stamp($latest->started_at ?? null);

        $merged = 0;
        if ($logins !== null && isset($latest->logins_at_start) && $latest->logins_at_start !== null) {
            $merged = max(0, $logins - (int) $latest->logins_at_start - 1);
        }

        if ($logout !== null && $start !== null && $logout->gte($start) && $logout->lte($newLogin)) {
            return ['ended_at' => $logout, 'merged_logins' => $merged, 'end_estimated' => false];
        }

        // The logout between the two sessions was overwritten before we saw
        // it. Fall back to the last time the old one was confirmed online.
        $lastSeen = self::stamp($latest->last_seen_at ?? null);
        $end = ($lastSeen !== null && $start !== null && $lastSeen->gt($start)) ? $lastSeen : $start;

        return ['ended_at' => $end, 'merged_logins' => $merged, 'end_estimated' => true];
    }

    /**
     * Newest stored session per character.
     *
     * @param  array<int>  $characterIds
     * @return array<int, object>
     */
    private function latestSessions(array $characterIds): array
    {
        if (empty($characterIds)) {
            return [];
        }

        $newest = DB::table('hr_manager_eve_sessions')
            ->whereIn('character_id', $characterIds)
            ->groupBy('character_id')
            ->selectRaw('character_id, MAX(started_at) AS newest_start');

        $rows = DB::table('hr_manager_eve_sessions as s')
            ->joinSub($newest, 'n', function ($join) {
                $join->on('n.character_id', '=', 's.character_id')
                    ->on('n.newest_start', '=', 's.started_at');
            })
            ->get(['s.id', 's.character_id', 's.started_at', 's.ended_at', 's.last_seen_at', 's.logins_at_start']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->character_id] = $row;
        }

        return $out;
    }

    private function apply(int $characterId, ?object $current, string $kind, array $fields, Carbon $now): void
    {
        $fields = array_map(fn ($v) => $v instanceof Carbon ? $v->toDateTimeString() : $v, $fields);

        if ($kind === 'open') {
            DB::table('hr_manager_eve_sessions')->insertOrIgnore(array_merge($fields, [
                'character_id' => $characterId,
                'created_at'   => $now->toDateTimeString(),
                'updated_at'   => $now->toDateTimeString(),
            ]));

            return;
        }

        if ($current === null) {
            return;
        }

        DB::table('hr_manager_eve_sessions')
            ->where('id', $current->id)
            ->update(array_merge($fields, ['updated_at' => $now->toDateTimeString()]));
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

<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Settings, readiness and sizing for EVE + Discord voice tracking, and the
 * combined per-player and per-corp views the pages read.
 *
 * Voice tracking rides on SeAT Connector's Discord driver: Connector already
 * knows which Discord account belongs to which SeAT account, and its driver
 * already holds a bot token in the guild. HR reuses both and owns neither, so
 * every read here degrades to "not available" when Connector is missing.
 */
class ActivityTrackingService
{
    public const SETTING_VOICE_ENABLED   = 'activity_voice_enabled';
    public const SETTING_VOICE_INTERVAL  = 'activity_voice_interval';
    public const SETTING_RETENTION_DAYS  = 'activity_retention_days';
    public const SETTING_VOICE_CLASSIFIER = 'activity_voice_classifier';

    public const INTERVALS = [5, 10, 15, 20, 30, 60];
    public const DEFAULT_INTERVAL = 5;

    public const RETENTION_OPTIONS = [90, 180, 365, 730];
    public const DEFAULT_RETENTION = 180;

    /**
     * Share of one run's reach the advice aims to use, so a slow minute or a
     * busy Connector sync still fits.
     */
    public const HEADROOM = 0.6;

    /** SeAT Connector's Discord driver keeps its settings under this global key. */
    private const DRIVER_SETTING = 'seat-connector.drivers.discord';

    private const RUN_STATUS_KEY = 'hr-manager:voice:last-run';
    private const CHANNELS_KEY   = 'hr-manager:voice:channels';
    private const NOT_IN_GUILD_KEY = 'hr-manager:voice:not-in-guild:';
    private const INVALID_KEY    = 'hr-manager:voice:invalid:';

    // Somebody who left the guild answers the same way for hours; skip them
    // for a while instead of spending a request every interval.
    private const NOT_IN_GUILD_SECONDS = 6 * 3600;

    public function voiceEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_VOICE_ENABLED, false);
    }

    public function voiceClassifierEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_VOICE_CLASSIFIER, true);
    }

    public function interval(): int
    {
        $value = (int) Setting::getValue(self::SETTING_VOICE_INTERVAL, self::DEFAULT_INTERVAL);

        return in_array($value, self::INTERVALS, true) ? $value : self::DEFAULT_INTERVAL;
    }

    public function retentionDays(): int
    {
        $value = (int) Setting::getValue(self::SETTING_RETENTION_DAYS, self::DEFAULT_RETENTION);

        return in_array($value, self::RETENTION_OPTIONS, true) ? $value : self::DEFAULT_RETENTION;
    }

    public function connectorInstalled(): bool
    {
        return Schema::hasTable('seat_connector_users');
    }

    /**
     * Guild id and bot token from the Discord driver's settings, or null when
     * the driver is not installed or not configured. The token is handed to
     * the transport and nowhere else: never log it, never put it on a page.
     *
     * @return array{guild_id:string, bot_token:string}|null
     */
    public function discordConfig(): ?array
    {
        if (!function_exists('setting')) {
            return null;
        }

        try {
            $settings = setting(self::DRIVER_SETTING, true);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_object($settings)) {
            return null;
        }

        $guild = trim((string) ($settings->guild_id ?? ''));
        $token = trim((string) ($settings->bot_token ?? ''));
        if ($guild === '' || $token === '') {
            return null;
        }

        return ['guild_id' => $guild, 'bot_token' => $token];
    }

    /**
     * Every SeAT account with a linked Discord account.
     *
     * @return array<int, array{user_id:int, discord_id:string}>
     */
    public function linkedMembers(): array
    {
        if (!$this->connectorInstalled()) {
            return [];
        }

        $query = DB::table('seat_connector_users')->whereNotNull('connector_id');
        if (Schema::hasColumn('seat_connector_users', 'connector_type')) {
            $query->where('connector_type', 'discord');
        }

        $out = [];
        foreach ($query->get(['user_id', 'connector_id']) as $row) {
            $discordId = trim((string) $row->connector_id);
            if ($discordId === '' || !ctype_digit($discordId) || (int) $row->user_id <= 0) {
                continue;
            }
            // One Discord account per SeAT account; if Connector holds two,
            // the first wins rather than polling the same person twice.
            $out[(int) $row->user_id] ??= ['user_id' => (int) $row->user_id, 'discord_id' => $discordId];
        }

        return array_values($out);
    }

    public function linkedMemberCount(): int
    {
        return count($this->linkedMembers());
    }

    /**
     * The minute's slice: each member is polled once per interval, at the
     * minute their Discord id hashes to, so the requests spread evenly across
     * the interval instead of arriving in one burst.
     *
     * @param  array<int, array{user_id:int, discord_id:string}>  $members
     * @return array<int, array{user_id:int, discord_id:string}>
     */
    public static function sliceFor(array $members, int $slot, int $interval): array
    {
        if ($interval <= 1) {
            return array_values($members);
        }

        return array_values(array_filter(
            $members,
            fn ($m) => (crc32((string) $m['discord_id']) % $interval) === $slot
        ));
    }

    public static function slotFor(Carbon $now, int $interval): int
    {
        return $interval <= 1 ? 0 : intdiv($now->getTimestamp(), 60) % $interval;
    }

    /**
     * This minute's members, minus anyone recently found not to be in the guild.
     *
     * @return array<int, array{user_id:int, discord_id:string}>
     */
    public function membersForSlot(int $slot, int $interval): array
    {
        return array_values(array_filter(
            self::sliceFor($this->linkedMembers(), $slot, $interval),
            fn ($m) => !Cache::get(self::NOT_IN_GUILD_KEY . $m['discord_id'])
        ));
    }

    public function rememberNotInGuild(array $samples): void
    {
        foreach ($samples as $sample) {
            if (($sample['state'] ?? null) === 'not_in_guild') {
                Cache::put(self::NOT_IN_GUILD_KEY . $sample['discord_id'], 1, self::NOT_IN_GUILD_SECONDS);
            }
        }
    }

    /**
     * Running count of invalid requests (401 / 403 / 429) in the current
     * ten-minute window, across runs. Discord's ban threshold is counted
     * per IP over ten minutes; this approximates it with fixed windows.
     */
    public function countInvalid(int $add): int
    {
        $key = self::INVALID_KEY . intdiv(time(), 600);
        if ($add > 0) {
            // add() only writes when the key is absent, so the expiry is set
            // once per window and increment() never races it away.
            Cache::add($key, 0, 900);
            Cache::increment($key, $add);
        }

        return (int) Cache::get($key, 0);
    }

    /**
     * Channel names, refreshed at most hourly. One request.
     *
     * @return array<string, string>
     */
    public function channelNames(DiscordVoiceTransport $transport, string $guildId): array
    {
        $cached = Cache::get(self::CHANNELS_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $names = $transport->channelNames($guildId);
        // An empty answer is cached briefly so a failing call is not retried every minute.
        Cache::put(self::CHANNELS_KEY, $names, empty($names) ? 600 : 3600);

        return $names;
    }

    public function recordRun(array $result, int $slot, int $interval): void
    {
        $previous = $this->lastRun() ?? [];

        $bucket = [
            'bucket'        => $result['stats']['bucket'] ?? ($previous['bucket'] ?? null),
            'bucket_limit'  => $result['stats']['bucket_limit'] ?? ($previous['bucket_limit'] ?? null),
            'bucket_window' => $result['stats']['bucket_window'] ?? ($previous['bucket_window'] ?? null),
        ];

        $stopped = $result['stopped'] ?? null;

        Cache::forever(self::RUN_STATUS_KEY, array_merge($result['stats'] ?? [], $bucket, [
            'at'            => now()->toIso8601String(),
            'slot'          => $slot,
            'interval'      => $interval,
            'stopped'       => $stopped,
            'stopped_count' => $stopped !== null ? ((int) ($previous['stopped_count'] ?? 0)) + 1 : 0,
        ]));
    }

    /**
     * A run that never reached Discord. Kept apart from the last real run so
     * its figures are not passed off as fresh.
     */
    public function recordSkippedRun(string $reason): void
    {
        $previous = $this->lastRun() ?? [];
        $previous['skipped'] = ['at' => now()->toIso8601String(), 'reason' => $reason];
        Cache::forever(self::RUN_STATUS_KEY, $previous);
    }

    public function lastRun(): ?array
    {
        $value = Cache::get(self::RUN_STATUS_KEY);

        return is_array($value) ? $value : null;
    }

    /**
     * How the configured interval fits the number of linked members, and what
     * to pick instead. Discord does not publish this endpoint's own limit, so
     * the rate starts from HR's own ceiling and comes down to what Discord's
     * headers reported once a run has seen them.
     *
     * @return array{
     *   linked:int, interval:int, per_minute:int, per_hour:int,
     *   rate:float, rate_source:string, capacity_per_minute:int, safe_per_minute:int,
     *   verdict:string, recommended:int, max_members:array<int,int>,
     *   hidden_worst_10min:int, hidden_ok:bool
     * }
     */
    public static function sizing(int $linked, int $interval, ?int $bucketLimit = null, ?float $bucketWindow = null): array
    {
        $interval = max(1, $interval);
        $rate = (float) VoicePoller::MAX_REQUESTS_PER_SECOND;
        $source = 'hr_ceiling';
        if ($bucketLimit !== null && $bucketLimit > 0 && $bucketWindow !== null && $bucketWindow > 0) {
            $measured = $bucketLimit / $bucketWindow;
            if ($measured < $rate) {
                $rate = $measured;
                $source = 'discord';
            }
        }

        $capacity = (int) floor($rate * VoicePoller::RUN_BUDGET_SECONDS);
        $safe = max(1, (int) floor($capacity * self::HEADROOM));
        $perMinute = (int) ceil($linked / $interval);

        $verdict = $perMinute <= $safe ? 'ok' : ($perMinute <= $capacity ? 'tight' : 'over');

        $recommended = self::INTERVALS[count(self::INTERVALS) - 1];
        foreach (self::INTERVALS as $candidate) {
            if ((int) ceil($linked / $candidate) <= $safe) {
                $recommended = $candidate;
                break;
            }
        }

        $maxMembers = [];
        foreach (self::INTERVALS as $candidate) {
            $maxMembers[$candidate] = $safe * $candidate;
        }

        // Worst case for the invalid-request ceiling: every linked member in a
        // channel the bot cannot see, answering 403 on each poll.
        $hiddenWorst = (int) ceil($linked * 10 / $interval);

        return [
            'linked'              => $linked,
            'interval'            => $interval,
            'per_minute'          => $perMinute,
            'per_hour'            => (int) ceil($linked * 60 / $interval),
            'rate'                => round($rate, 2),
            'rate_source'         => $source,
            'capacity_per_minute' => $capacity,
            'safe_per_minute'     => $safe,
            'verdict'             => $verdict,
            'recommended'         => $recommended,
            'max_members'         => $maxMembers,
            'hidden_worst_10min'  => $hiddenWorst,
            'hidden_ok'           => $hiddenWorst < VoicePoller::INVALID_REQUEST_CEILING,
        ];
    }

    /**
     * The sizing for this install as it stands.
     */
    public function currentSizing(): array
    {
        $run = $this->lastRun() ?? [];

        return self::sizing(
            $this->linkedMemberCount(),
            $this->interval(),
            isset($run['bucket_limit']) ? (int) $run['bucket_limit'] : null,
            isset($run['bucket_window']) ? (float) $run['bucket_window'] : null
        );
    }

    /**
     * Everything the player profile panel shows. EVE figures merge every
     * character the account owns; voice is per account.
     *
     * @param  array<int>  $characterIds
     */
    public function playerSummary(int $userId, array $characterIds): array
    {
        $now = now();
        $eve = app(EveSessionService::class);
        $voice = app(VoiceSessionService::class);

        $out = [
            'eve_available'   => $eve->isAvailable(),
            'voice_enabled'   => $this->voiceEnabled(),
            'voice_available' => $this->voiceEnabled() && $this->connectorInstalled() && $voice->isAvailable(),
            'linked'          => false,
            'last_in_game'    => null,
            'online_now'      => false,
            'eve_seconds_7d'  => 0,
            'eve_seconds_30d' => 0,
            'last_in_voice'   => null,
            'in_voice_now'    => false,
            'voice_channel'   => null,
            'voice_hidden'    => false,
            'voice'           => null,
            'eve_since'       => null,
            'voice_since'     => null,
            'interval'        => $this->interval(),
        ];

        try {
            if ($out['eve_available'] && !empty($characterIds)) {
                foreach ($eve->lastInGame($characterIds, $now) as $row) {
                    if ($row['online']) {
                        $out['online_now'] = true;
                    }
                    if ($row['last'] !== null && ($out['last_in_game'] === null || $row['last']->gt($out['last_in_game']))) {
                        $out['last_in_game'] = $row['last'];
                    }
                }
                $out['eve_seconds_7d'] = $eve->secondsSince($characterIds, $now->copy()->subDays(7), $now);
                $out['eve_seconds_30d'] = $eve->secondsSince($characterIds, $now->copy()->subDays(30), $now);
                $out['eve_since'] = $eve->trackingSince();
            }

            if ($out['voice_available']) {
                $out['linked'] = DB::table('seat_connector_users')
                    ->where('user_id', $userId)
                    ->when(Schema::hasColumn('seat_connector_users', 'connector_type'), fn ($q) => $q->where('connector_type', 'discord'))
                    ->exists();

                $last = $voice->lastInVoice([$userId], $this->interval(), $now)[$userId] ?? null;
                if ($last !== null) {
                    $out['last_in_voice'] = $last['last'];
                    $out['in_voice_now'] = $last['now'];
                    $out['voice_channel'] = $last['channel'];
                    $out['voice_hidden'] = $last['hidden'];
                }
                $out['voice'] = $voice->summaryForUser($userId, $now);
                $out['voice_since'] = $voice->trackingSince();
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] activity summary failed for user ' . $userId . ': ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Corp Health's four-way split over the last 30 days: in the game and on
     * comms, in the game only, on comms only, neither. Presence, not hours,
     * so it is two grouped reads however large the corp is.
     *
     * @param  array<int, array<int>>  $charactersByUser  user id => character ids, current members only
     */
    public function corpPresence(array $charactersByUser, int $days = 30): array
    {
        $now = now();
        $since = $now->copy()->subDays($days);
        $out = [
            'available'       => false,
            'voice_available' => false,
            'days'            => $days,
            'buckets'         => ['both' => [], 'game_only' => [], 'voice_only' => [], 'neither' => []],
            'linked'          => 0,
            'players'         => count($charactersByUser),
        ];

        $eve = app(EveSessionService::class);
        if (!Schema::hasTable('character_onlines') || empty($charactersByUser)) {
            return $out;
        }
        $out['available'] = true;

        $allChars = [];
        foreach ($charactersByUser as $ids) {
            foreach ($ids as $id) {
                $allChars[] = (int) $id;
            }
        }
        $lastGame = $eve->lastInGame($allChars, $now);

        $voiceRead = [];
        $linkedUsers = [];
        if ($this->voiceEnabled() && $this->connectorInstalled()) {
            $out['voice_available'] = true;
            $voiceRead = app(VoiceSessionService::class)->lastInVoice(array_keys($charactersByUser), $this->interval(), $now);
            foreach ($this->linkedMembers() as $m) {
                $linkedUsers[$m['user_id']] = true;
            }
        }

        foreach ($charactersByUser as $userId => $ids) {
            $game = null;
            foreach ($ids as $id) {
                $stamp = $lastGame[(int) $id]['last'] ?? null;
                if ($stamp !== null && ($game === null || $stamp->gt($game))) {
                    $game = $stamp;
                }
            }
            $inGame = $game !== null && $game->gte($since);
            $voiceAt = $voiceRead[$userId]['last'] ?? null;
            $onComms = $voiceAt !== null && $voiceAt->gte($since);
            if (isset($linkedUsers[$userId])) {
                $out['linked']++;
            }

            $bucket = $inGame ? ($onComms ? 'both' : 'game_only') : ($onComms ? 'voice_only' : 'neither');
            $out['buckets'][$bucket][] = [
                'user_id'      => (int) $userId,
                'last_in_game' => $game,
                'last_voice'   => $voiceAt,
                'linked'       => isset($linkedUsers[$userId]),
            ];
        }

        return $out;
    }

    /**
     * corpPresence() for one corp's registered members, with display names,
     * cached briefly so moving between Corp Health tabs does not re-read it.
     */
    public function corpPresenceForCorporation(int $corporationId, int $days = 30): array
    {
        return Cache::remember('hr-manager:activity:corp-presence:' . $corporationId . ':' . $days, 600, function () use ($corporationId, $days) {
            if (!Schema::hasTable('character_affiliations') || !Schema::hasTable('refresh_tokens')) {
                return ['available' => false];
            }

            $userIds = DB::table('character_affiliations as a')
                ->join('refresh_tokens as t', 't.character_id', '=', 'a.character_id')
                ->where('a.corporation_id', $corporationId)
                ->whereNull('t.deleted_at')
                ->distinct()
                ->pluck('t.user_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->all();

            if (empty($userIds)) {
                return ['available' => false];
            }

            // Every character the account owns, in this corp or not: playing
            // an alt elsewhere is still playing. Revoked tokens included, their
            // last login is still a fact.
            $charactersByUser = [];
            foreach (array_chunk($userIds, 1000) as $chunk) {
                foreach (DB::table('refresh_tokens')->whereIn('user_id', $chunk)->get(['user_id', 'character_id']) as $row) {
                    $charactersByUser[(int) $row->user_id][] = (int) $row->character_id;
                }
            }

            $out = $this->corpPresence($charactersByUser, $days);

            $names = [];
            try {
                $names = app(NameResolutionService::class)->getUserNames($userIds);
            } catch (\Throwable $e) {
                Log::info('[HR Manager] corp presence names unavailable: ' . $e->getMessage());
            }
            foreach ($out['buckets'] as $bucket => $rows) {
                foreach ($rows as $i => $row) {
                    $out['buckets'][$bucket][$i]['name'] = $names[$row['user_id']] ?? ('#' . $row['user_id']);
                }
                // Most recent first, so the people a director should talk to
                // this week are at the top.
                usort($out['buckets'][$bucket], function ($a, $b) {
                    $ta = max($a['last_in_game']?->getTimestamp() ?? 0, $a['last_voice']?->getTimestamp() ?? 0);
                    $tb = max($b['last_in_game']?->getTimestamp() ?? 0, $b['last_voice']?->getTimestamp() ?? 0);

                    return $tb <=> $ta;
                });
            }

            return $out;
        });
    }

    /**
     * Diagnostic results, in the page's standard shape.
     *
     * @return array<int, array{label:string, status:string, message:string}>
     */
    public function diagnosticChecks(): array
    {
        $checks = [];
        $eve = app(EveSessionService::class);

        if (!Schema::hasTable('character_onlines')) {
            $checks[] = ['label' => 'EVE session tracking', 'status' => 'warn', 'message' => 'SeAT has no character_onlines table, so there is nothing to read'];
        } elseif (!$eve->isEnabled()) {
            $checks[] = ['label' => 'EVE session tracking', 'status' => 'info', 'message' => 'Switched off in Settings, Activity Tracking'];
        } else {
            $withData = (int) DB::table('character_onlines')->count();
            $tokens = Schema::hasTable('refresh_tokens') ? (int) DB::table('refresh_tokens')->whereNull('deleted_at')->count() : 0;
            $checks[] = [
                'label'   => 'EVE session tracking',
                'status'  => ($tokens === 0 || $withData > 0) ? 'ok' : 'warn',
                'message' => $withData . ' characters have online data in SeAT, of ' . $tokens . ' with a live token. A character missing here lacks the esi-location.read_online.v1 scope.',
            ];
        }

        if (!$this->voiceEnabled()) {
            $checks[] = ['label' => 'Discord voice tracking', 'status' => 'info', 'message' => 'Switched off in Settings, Activity Tracking'];

            return $checks;
        }

        if (!$this->connectorInstalled()) {
            $checks[] = ['label' => 'Discord voice tracking', 'status' => 'warn', 'message' => 'Switched on, but SeAT Connector is not installed, so there are no linked Discord accounts to poll'];

            return $checks;
        }

        if ($this->discordConfig() === null) {
            $checks[] = ['label' => 'Discord voice tracking', 'status' => 'warn', 'message' => 'Switched on, but SeAT Connector\'s Discord driver has no guild or bot token configured'];

            return $checks;
        }

        $sizing = $this->currentSizing();
        $checks[] = [
            'label'   => 'Discord voice tracking',
            'status'  => $sizing['verdict'] === 'over' ? 'warn' : 'ok',
            'message' => $sizing['linked'] . ' linked accounts, polled every ' . $sizing['interval'] . ' min (' . $sizing['per_minute'] . ' requests a minute). '
                . ($sizing['verdict'] === 'ok'
                    ? 'Fits comfortably.'
                    : 'Too many for this interval; ' . $sizing['recommended'] . ' min is recommended.'),
        ];

        $run = $this->lastRun();
        $skipped = $run['skipped'] ?? null;
        if ($skipped !== null && (!isset($run['at']) || Carbon::parse($skipped['at'])->gt(Carbon::parse($run['at'])))) {
            $checks[] = [
                'label'   => 'Last voice poll skipped',
                'status'  => 'warn',
                'message' => 'The last run ' . Carbon::parse($skipped['at'])->diffForHumans() . ' did not reach Discord: ' . $skipped['reason'] . '.',
            ];
        }
        if ($run === null || !isset($run['at'])) {
            $checks[] = ['label' => 'Last voice poll', 'status' => 'warn', 'message' => 'No poll has reached Discord yet. The scheduler runs hr-manager:poll-voice every minute and a queue worker picks the job up.'];

            return $checks;
        }

        $at = Carbon::parse($run['at']);
        $stale = $at->lt(now()->subMinutes(5));
        $stopped = $run['stopped'] ?? null;
        $message = 'Ran ' . $at->diffForHumans() . ': ' . (int) ($run['requests'] ?? 0) . ' requests, '
            . (int) ($run['in_voice'] ?? 0) . ' in voice, ' . (int) ($run['hidden'] ?? 0) . ' in channels the bot cannot see.';
        if ($stopped !== null) {
            $message .= ' Stopped early: ' . $stopped . '.';
        }
        $checks[] = [
            'label'   => 'Last voice poll',
            'status'  => ($stale || in_array($stopped, ['unauthorized', 'invalid_ceiling', 'not_configured'], true)) ? 'warn' : 'ok',
            'message' => $message,
        ];

        if (isset($run['bucket_limit'], $run['bucket_window']) && $run['bucket_window'] > 0) {
            $checks[] = [
                'label'   => 'Discord rate limit (measured)',
                'status'  => 'info',
                'message' => 'Discord reports ' . (int) $run['bucket_limit'] . ' requests per ' . round((float) $run['bucket_window'], 1) . ' s on the voice-state route for this bot.',
            ];
        }

        if ((int) ($run['hidden'] ?? 0) > 0) {
            $checks[] = [
                'label'   => 'Voice channels the bot cannot see',
                'status'  => 'warn',
                'message' => 'Discord refused to say which channel for ' . (int) $run['hidden'] . ' members last run. They still count as in voice, but each refusal counts toward Discord\'s invalid-request limit. Give the bot\'s role View Channel and Connect on those channels.',
            ];
        }

        return $checks;
    }

    /**
     * Drop sessions older than the retention setting.
     *
     * @return array{eve:int, voice:int}
     */
    public function prune(bool $dryRun = false): array
    {
        $before = now()->subDays($this->retentionDays());

        return [
            'eve'   => app(EveSessionService::class)->prune($before, $dryRun),
            'voice' => app(VoiceSessionService::class)->prune($before, $dryRun),
        ];
    }
}

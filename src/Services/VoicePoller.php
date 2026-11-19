<?php

namespace HrManager\Services;

/**
 * Asks Discord, one member at a time, whether each is in voice.
 *
 * The bot token is shared with SeAT Connector, which needs it for role syncs,
 * so the poller deliberately leaves most of Discord's capacity alone: it never
 * goes faster than MAX_REQUESTS_PER_SECOND (Discord allows a bot 50 a second
 * in total), waits whenever Discord's rate-limit headers say a bucket is
 * empty, and stops the run rather than queue up long waits. A member it does
 * not reach is simply sampled on the next pass.
 *
 * Discord also temporarily bans an IP after 10,000 invalid requests (401, 403
 * or 429) in ten minutes, and a member sitting in a channel the bot cannot
 * connect to answers 403 on every poll. The poller counts those across runs
 * and stops at half Discord's ceiling, because that ban would take Connector
 * down with it.
 *
 * No database access and no clock or sleep of its own, so a test can drive the
 * real loop against a stand-in Discord.
 */
class VoicePoller
{
    public const MAX_REQUESTS_PER_SECOND = 10;

    /** One run must finish inside the minute it was scheduled for. */
    public const RUN_BUDGET_SECONDS = 50;

    /** A wait longer than this ends the run instead of holding a worker. */
    public const LONGEST_WAIT_SECONDS = 10;

    /** Half of Discord's 10,000 invalid requests per 10 minutes. */
    public const INVALID_REQUEST_CEILING = 5000;

    private const MAX_CONSECUTIVE_ERRORS = 5;

    // Discord JSON error codes.
    private const UNKNOWN_MEMBER = 10007;

    private DiscordVoiceTransport $transport;

    /** @var callable(float):void */
    private $sleep;

    /** @var callable():float */
    private $clock;

    /** @var callable(int):int  adds n invalid requests, returns the ten-minute total */
    private $invalid;

    public function __construct(
        DiscordVoiceTransport $transport,
        ?callable $sleep = null,
        ?callable $clock = null,
        ?callable $invalid = null
    ) {
        $this->transport = $transport;
        $this->sleep   = $sleep ?? function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->clock   = $clock ?? fn (): float => microtime(true);
        $this->invalid = $invalid ?? fn (int $add): int => $add;
    }

    /**
     * @param  array<int, array{user_id:int, discord_id:string}>  $members
     * @return array{
     *   samples: array<int, array{user_id:int, discord_id:string, state:string, channel_id:?string}>,
     *   stats: array<string, mixed>,
     *   stopped: ?string
     * }
     *   state is one of in_voice, hidden, not_in_voice, not_in_guild, error.
     *   stopped names why the run ended early, null when every member was asked.
     */
    public function poll(string $guildId, array $members): array
    {
        $members = array_values($members);
        $samples = [];
        $stats = [
            'members'           => count($members),
            'requests'          => 0,
            'in_voice'          => 0,
            'hidden'            => 0,
            'not_in_voice'      => 0,
            'not_in_guild'      => 0,
            'errors'            => 0,
            'rate_limited'      => 0,
            'unreached'         => 0,
            'waited_seconds'    => 0.0,
            'bucket'            => null,
            'bucket_limit'      => null,
            'bucket_window'     => null,
            'invalid_10min'     => ($this->invalid)(0),
        ];
        $stopped = null;

        $started  = ($this->clock)();
        $minGap   = 1 / self::MAX_REQUESTS_PER_SECOND;
        $lastSent = null;
        $consecutiveErrors = 0;

        for ($i = 0, $n = count($members); $i < $n; $i++) {
            $member = $members[$i];

            if (($this->clock)() - $started >= self::RUN_BUDGET_SECONDS) {
                $stopped = 'budget';
                break;
            }
            if ($stats['invalid_10min'] >= self::INVALID_REQUEST_CEILING) {
                $stopped = 'invalid_ceiling';
                break;
            }

            for ($attempt = 0; $attempt < 2; $attempt++) {
                if ($lastSent !== null) {
                    $gap = $minGap - (($this->clock)() - $lastSent);
                    if ($gap > 0) {
                        $this->wait($gap, $stats);
                    }
                }

                // Paced from when the request went out, so Discord's response
                // time is part of the gap rather than added on top of it, and
                // a run reaches the capacity the sizing advice assumes.
                $lastSent = ($this->clock)();
                $response = $this->transport->voiceState($guildId, (string) $member['discord_id']);
                $stats['requests']++;

                $this->readBucket($response['headers'] ?? [], $stats);
                $status = (int) ($response['status'] ?? 0);

                if ($status !== 429) {
                    break;
                }

                // Rate limited. A shared-scope 429 is Discord's own load and
                // does not count against us.
                $stats['rate_limited']++;
                if (($response['headers']['x-ratelimit-scope'] ?? '') !== 'shared') {
                    $stats['invalid_10min'] = ($this->invalid)(1);
                }
                $retryAfter = $this->retryAfter($response);
                $global = !empty($response['body']['global']);
                if ($global || $attempt > 0 || $retryAfter > self::LONGEST_WAIT_SECONDS) {
                    $stopped = 'rate_limited';
                    break 2;
                }
                $this->wait($retryAfter, $stats);
            }

            $state = null;
            $channelId = null;

            switch (true) {
                case $status === 200:
                    $channelId = isset($response['body']['channel_id']) ? (string) $response['body']['channel_id'] : null;
                    $state = $channelId !== null ? 'in_voice' : 'not_in_voice';
                    break;

                case $status === 404:
                    $code = (int) ($response['body']['code'] ?? 0);
                    $state = $code === self::UNKNOWN_MEMBER ? 'not_in_guild' : 'not_in_voice';
                    break;

                case $status === 403:
                    // Discord only refuses when the member IS connected, to a
                    // channel the bot lacks Connect on. They are in voice; we
                    // just cannot say where.
                    $state = 'hidden';
                    $stats['invalid_10min'] = ($this->invalid)(1);
                    break;

                case $status === 401:
                    $stats['invalid_10min'] = ($this->invalid)(1);
                    $stopped = 'unauthorized';
                    break 2;

                default:
                    $state = 'error';
                    break;
            }

            if ($state === 'error') {
                $stats['errors']++;
                $consecutiveErrors++;
            } else {
                $consecutiveErrors = 0;
                $stats[$state]++;
            }

            $samples[] = [
                'user_id'    => (int) $member['user_id'],
                'discord_id' => (string) $member['discord_id'],
                'state'      => $state,
                'channel_id' => $channelId,
            ];

            if ($consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                $stopped = 'errors';
                break;
            }

            // Bucket empty: wait for it to refill, or give up on this run.
            $remaining = $response['headers']['x-ratelimit-remaining'] ?? null;
            $resetAfter = (float) ($response['headers']['x-ratelimit-reset-after'] ?? 0);
            if ($remaining !== null && (int) $remaining === 0 && $resetAfter > 0 && $i < $n - 1) {
                if ($resetAfter > self::LONGEST_WAIT_SECONDS) {
                    $stopped = 'bucket_exhausted';
                    break;
                }
                $this->wait($resetAfter, $stats);
            }
        }

        $stats['unreached'] = count($members) - count($samples);
        $stats['elapsed_seconds'] = round(($this->clock)() - $started, 2);
        $stats['waited_seconds'] = round($stats['waited_seconds'], 2);

        return ['samples' => $samples, 'stats' => $stats, 'stopped' => $stopped];
    }

    /**
     * Discord's bucket for this route, as last reported. The window is the
     * longest reset-after seen, which is the full window on a fresh bucket.
     */
    private function readBucket(array $headers, array &$stats): void
    {
        if (isset($headers['x-ratelimit-bucket'])) {
            $stats['bucket'] = (string) $headers['x-ratelimit-bucket'];
        }
        if (isset($headers['x-ratelimit-limit'])) {
            $stats['bucket_limit'] = (int) $headers['x-ratelimit-limit'];
        }
        if (isset($headers['x-ratelimit-reset-after'])) {
            $window = (float) $headers['x-ratelimit-reset-after'];
            if ($stats['bucket_window'] === null || $window > $stats['bucket_window']) {
                $stats['bucket_window'] = $window;
            }
        }
    }

    private function retryAfter(array $response): float
    {
        $value = $response['body']['retry_after'] ?? ($response['headers']['retry-after'] ?? 1);

        return max(0.0, (float) $value);
    }

    private function wait(float $seconds, array &$stats): void
    {
        ($this->sleep)($seconds);
        $stats['waited_seconds'] += $seconds;
    }
}

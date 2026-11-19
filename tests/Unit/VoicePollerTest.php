<?php

namespace HrManager\Tests\Unit;

use HrManager\Services\DiscordVoiceTransport;
use HrManager\Services\VoicePoller;
use HrManager\Tests\TestCase;

/**
 * The poll loop, driven against a stand-in Discord.
 *
 * The bot token belongs to SeAT Connector as much as to HR, and Discord bans
 * the server's IP after enough refused requests, so how this loop paces
 * itself and when it gives up matters more than any single answer.
 */
class VoicePollerTest extends TestCase
{
    private float $clock = 0.0;

    /** @var array<int, float> when each request went out */
    private array $sentAt = [];

    private int $invalid = 0;

    /**
     * @param  callable(string, int):array  $answer  discord id and call number to a response
     */
    private function poller(callable $answer, int $invalidStart = 0): VoicePoller
    {
        $this->clock = 0.0;
        $this->sentAt = [];
        $this->invalid = $invalidStart;

        $transport = new class ($answer, $this) implements DiscordVoiceTransport {
            private $answer;
            private $test;
            private int $calls = 0;

            public function __construct(callable $answer, $test)
            {
                $this->answer = $answer;
                $this->test = $test;
            }

            public function voiceState(string $guildId, string $discordUserId): array
            {
                $this->test->recordSend();

                return ($this->answer)($discordUserId, $this->calls++);
            }

            public function channelNames(string $guildId): array
            {
                return [];
            }
        };

        return new VoicePoller(
            $transport,
            function (float $seconds): void {
                $this->clock += $seconds;
            },
            fn (): float => $this->clock,
            function (int $add): int {
                $this->invalid += $add;

                return $this->invalid;
            }
        );
    }

    public function recordSend(): void
    {
        $this->sentAt[] = $this->clock;
        // Every request takes a little time of its own.
        $this->clock += 0.02;
    }

    private function members(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['user_id' => $i, 'discord_id' => (string) (100000000000000000 + $i)];
        }

        return $out;
    }

    private static function inVoice(string $channel): array
    {
        return ['status' => 200, 'headers' => [], 'body' => ['channel_id' => $channel]];
    }

    public function test_each_answer_maps_to_the_right_state(): void
    {
        $answers = [
            '1' => ['status' => 200, 'headers' => [], 'body' => ['channel_id' => '777', 'user_id' => '1']],
            '2' => ['status' => 200, 'headers' => [], 'body' => ['channel_id' => null]],
            '3' => ['status' => 404, 'headers' => [], 'body' => ['code' => 10065, 'message' => 'Unknown Voice State']],
            '4' => ['status' => 404, 'headers' => [], 'body' => ['code' => 10007, 'message' => 'Unknown Member']],
            '5' => ['status' => 403, 'headers' => [], 'body' => ['code' => 50001, 'message' => 'Missing Access']],
            '6' => ['status' => 500, 'headers' => [], 'body' => null],
            '7' => ['status' => 0, 'headers' => [], 'body' => null],
        ];
        $members = [];
        foreach (array_keys($answers) as $id) {
            $members[] = ['user_id' => (int) $id, 'discord_id' => (string) $id];
        }

        $run = $this->poller(fn ($id) => $answers[$id])->poll('42', $members);

        $this->assertNull($run['stopped']);
        $this->assertSame(
            ['in_voice', 'not_in_voice', 'not_in_voice', 'not_in_guild', 'hidden', 'error', 'error'],
            array_column($run['samples'], 'state')
        );
        $this->assertSame('777', $run['samples'][0]['channel_id']);
        $this->assertSame(1, $run['stats']['hidden']);
        $this->assertSame(2, $run['stats']['errors']);
        $this->assertSame(1, $this->invalid, 'only the refusal counts toward Discord\'s invalid-request limit');
    }

    public function test_it_never_goes_faster_than_its_ceiling(): void
    {
        $run = $this->poller(fn () => self::inVoice('1'))->poll('42', $this->members(40));

        $this->assertSame(40, $run['stats']['requests']);
        $gaps = [];
        for ($i = 1; $i < count($this->sentAt); $i++) {
            $gaps[] = $this->sentAt[$i] - $this->sentAt[$i - 1];
        }
        $this->assertGreaterThanOrEqual(1 / VoicePoller::MAX_REQUESTS_PER_SECOND - 1e-9, min($gaps));
        // Discord's response time is inside the gap, not added to it, or a
        // run would fall short of the capacity the sizing advice assumes.
        $this->assertLessThan(1 / VoicePoller::MAX_REQUESTS_PER_SECOND + 1e-6, max($gaps));
    }

    /** A slice too big for one minute stops on time; the rest wait for the next pass. */
    public function test_a_run_stops_at_its_time_budget(): void
    {
        $run = $this->poller(fn () => self::inVoice('1'))->poll('42', $this->members(800));

        $this->assertSame('budget', $run['stopped']);
        $this->assertLessThanOrEqual(VoicePoller::MAX_REQUESTS_PER_SECOND * VoicePoller::RUN_BUDGET_SECONDS + 1, $run['stats']['requests']);
        $this->assertSame(800 - count($run['samples']), $run['stats']['unreached']);
    }

    public function test_a_rejected_token_stops_the_run_at_once(): void
    {
        $run = $this->poller(fn () => ['status' => 401, 'headers' => [], 'body' => ['code' => 0]])->poll('42', $this->members(20));

        $this->assertSame('unauthorized', $run['stopped']);
        $this->assertSame(1, $run['stats']['requests']);
        $this->assertSame([], $run['samples']);
    }

    public function test_a_route_rate_limit_is_waited_out_once_and_retried(): void
    {
        $answer = fn ($id, $call) => $call === 1
            ? ['status' => 429, 'headers' => ['x-ratelimit-scope' => 'user'], 'body' => ['retry_after' => 1.5, 'global' => false]]
            : self::inVoice('1');

        $run = $this->poller($answer)->poll('42', $this->members(3));

        $this->assertNull($run['stopped']);
        $this->assertCount(3, $run['samples']);
        $this->assertSame(4, $run['stats']['requests']);
        $this->assertSame(1, $run['stats']['rate_limited']);
        $this->assertGreaterThanOrEqual(1.5, $run['stats']['waited_seconds']);
        $this->assertSame(1, $this->invalid);
    }

    public function test_a_second_rate_limit_in_a_row_ends_the_run(): void
    {
        $answer = fn ($id, $call) => $call >= 1
            ? ['status' => 429, 'headers' => [], 'body' => ['retry_after' => 0.5, 'global' => false]]
            : self::inVoice('1');

        $run = $this->poller($answer)->poll('42', $this->members(5));

        $this->assertSame('rate_limited', $run['stopped']);
        $this->assertCount(1, $run['samples']);
    }

    public function test_a_global_rate_limit_ends_the_run_without_waiting(): void
    {
        $answer = fn () => ['status' => 429, 'headers' => ['x-ratelimit-global' => 'true'], 'body' => ['retry_after' => 0.2, 'global' => true]];

        $run = $this->poller($answer)->poll('42', $this->members(5));

        $this->assertSame('rate_limited', $run['stopped']);
        $this->assertSame(1, $run['stats']['requests']);
        $this->assertSame(0.0, (float) $run['stats']['waited_seconds']);
    }

    /** Discord's own load is not our invalid request. */
    public function test_a_shared_rate_limit_does_not_count_toward_the_ban(): void
    {
        $answer = fn ($id, $call) => $call === 0
            ? ['status' => 429, 'headers' => ['x-ratelimit-scope' => 'shared'], 'body' => ['retry_after' => 0.3, 'global' => false]]
            : self::inVoice('1');

        $this->poller($answer)->poll('42', $this->members(2));

        $this->assertSame(0, $this->invalid);
    }

    public function test_polling_pauses_at_half_of_discords_invalid_request_limit(): void
    {
        $answer = fn () => ['status' => 403, 'headers' => [], 'body' => ['code' => 50001]];

        $run = $this->poller($answer, VoicePoller::INVALID_REQUEST_CEILING - 2)->poll('42', $this->members(10));

        $this->assertSame('invalid_ceiling', $run['stopped']);
        $this->assertSame(2, $run['stats']['requests']);
        $this->assertSame(VoicePoller::INVALID_REQUEST_CEILING, $this->invalid);
    }

    public function test_an_install_already_over_the_limit_sends_nothing(): void
    {
        $run = $this->poller(fn () => self::inVoice('1'), VoicePoller::INVALID_REQUEST_CEILING)->poll('42', $this->members(3));

        $this->assertSame('invalid_ceiling', $run['stopped']);
        $this->assertSame(0, $run['stats']['requests']);
    }

    public function test_an_empty_bucket_is_waited_for_when_the_wait_is_short(): void
    {
        $answer = fn ($id, $call) => [
            'status'  => 200,
            'headers' => ['x-ratelimit-limit' => '5', 'x-ratelimit-remaining' => $call === 1 ? '0' : '3', 'x-ratelimit-reset-after' => '2.5', 'x-ratelimit-bucket' => 'abc'],
            'body'    => ['channel_id' => '1'],
        ];

        $run = $this->poller($answer)->poll('42', $this->members(4));

        $this->assertNull($run['stopped']);
        $this->assertCount(4, $run['samples']);
        $this->assertGreaterThanOrEqual(2.5, $run['stats']['waited_seconds']);
        $this->assertSame(5, $run['stats']['bucket_limit']);
        $this->assertSame(2.5, $run['stats']['bucket_window']);
        $this->assertSame('abc', $run['stats']['bucket']);
    }

    public function test_an_empty_bucket_with_a_long_reset_ends_the_run(): void
    {
        $answer = fn () => [
            'status'  => 200,
            'headers' => ['x-ratelimit-limit' => '5', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset-after' => '30'],
            'body'    => ['channel_id' => '1'],
        ];

        $run = $this->poller($answer)->poll('42', $this->members(4));

        $this->assertSame('bucket_exhausted', $run['stopped']);
        $this->assertCount(1, $run['samples']);
        $this->assertSame(3, $run['stats']['unreached']);
    }

    public function test_repeated_failures_end_the_run(): void
    {
        $run = $this->poller(fn () => ['status' => 502, 'headers' => [], 'body' => null])->poll('42', $this->members(20));

        $this->assertSame('errors', $run['stopped']);
        $this->assertSame(5, $run['stats']['requests']);
    }
}

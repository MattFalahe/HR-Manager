<?php

namespace HrManager\Tests\Unit;

use Carbon\Carbon;
use HrManager\Services\VoiceSessionService;
use HrManager\Tests\TestCase;

/**
 * Voice sessions, built from one sample per member per poll interval.
 *
 * A sample stands for its whole interval, so a session runs from half an
 * interval before the first sighting to half an interval after the last. The
 * hours a director reads come straight out of these rules.
 */
class VoiceSessionTest extends TestCase
{
    private const INTERVAL = 5;

    private function plan(?object $open, string $state, ?string $channel = null): array
    {
        return VoiceSessionService::planSample($open, ['state' => $state, 'channel_id' => $channel], Carbon::now(), self::INTERVAL);
    }

    private function open(array $fields = []): object
    {
        return (object) array_merge([
            'channel_id'     => '500',
            'hidden_channel' => false,
            'started_at'     => '2026-11-08 11:27:30',
            'last_seen_at'   => '2026-11-08 11:55:00',
            'ended_at'       => null,
            'samples'        => 6,
        ], $fields);
    }

    private function stamp(array $fields, string $key): ?string
    {
        return isset($fields[$key]) ? $fields[$key]->toDateTimeString() : null;
    }

    public function test_a_first_sighting_opens_a_session_half_an_interval_back(): void
    {
        $ops = $this->plan(null, 'in_voice', '500');

        $this->assertSame(['open'], array_column($ops, 0));
        $this->assertSame('2026-11-08 11:57:30', $this->stamp($ops[0][1], 'started_at'));
        $this->assertSame('2026-11-08 12:00:00', $this->stamp($ops[0][1], 'last_seen_at'));
        $this->assertSame('500', $ops[0][1]['channel_id']);
        $this->assertSame(1, $ops[0][1]['samples']);
    }

    public function test_the_same_channel_extends_the_session(): void
    {
        $ops = $this->plan($this->open(), 'in_voice', '500');

        $this->assertSame(['touch'], array_column($ops, 0));
        $this->assertSame('2026-11-08 12:00:00', $this->stamp($ops[0][1], 'last_seen_at'));
        $this->assertSame(7, $ops[0][1]['samples']);
    }

    /** A skipped run or a worker restart costs one sample, not the session. */
    public function test_one_missed_sample_does_not_split_a_session(): void
    {
        $ops = $this->plan($this->open(['last_seen_at' => '2026-11-08 11:50:00']), 'in_voice', '500');

        $this->assertSame(['touch'], array_column($ops, 0));
    }

    public function test_two_missed_samples_end_the_session_and_start_another(): void
    {
        $ops = $this->plan($this->open(['last_seen_at' => '2026-11-08 11:45:00']), 'in_voice', '500');

        $this->assertSame(['close', 'open'], array_column($ops, 0));
        $this->assertSame('2026-11-08 11:47:30', $this->stamp($ops[0][1], 'ended_at'));
        $this->assertSame('2026-11-08 11:57:30', $this->stamp($ops[1][1], 'started_at'));
    }

    public function test_leaving_voice_ends_the_session_half_an_interval_after_the_last_sighting(): void
    {
        $ops = $this->plan($this->open(), 'not_in_voice');

        $this->assertSame(['close'], array_column($ops, 0));
        $this->assertSame('2026-11-08 11:57:30', $this->stamp($ops[0][1], 'ended_at'));
    }

    public function test_leaving_the_guild_ends_the_session_the_same_way(): void
    {
        $ops = $this->plan($this->open(), 'not_in_guild');

        $this->assertSame(['close'], array_column($ops, 0));
    }

    public function test_moving_channel_ends_one_session_where_the_next_begins(): void
    {
        $ops = $this->plan($this->open(), 'in_voice', '600');

        $this->assertSame(['close', 'open'], array_column($ops, 0));
        $this->assertSame('2026-11-08 11:57:30', $this->stamp($ops[0][1], 'ended_at'));
        $this->assertSame('2026-11-08 11:57:30', $this->stamp($ops[1][1], 'started_at'));
        $this->assertSame('600', $ops[1][1]['channel_id']);
    }

    public function test_a_channel_the_bot_cannot_see_still_counts_as_in_voice(): void
    {
        $ops = $this->plan(null, 'hidden', '999');

        $this->assertSame(['open'], array_column($ops, 0));
        $this->assertTrue($ops[0][1]['hidden_channel']);
        $this->assertNull($ops[0][1]['channel_id'], 'a channel Discord would not name is not recorded');

        $again = $this->plan($this->open(['channel_id' => null, 'hidden_channel' => true]), 'hidden');
        $this->assertSame(['touch'], array_column($again, 0));
    }

    /** A Discord error says nothing about the member, so nothing changes. */
    public function test_a_failed_request_leaves_the_session_alone(): void
    {
        $this->assertSame([], $this->plan($this->open(), 'error'));
        $this->assertSame([], $this->plan(null, 'error'));
    }

    public function test_nobody_in_voice_with_nothing_open_writes_nothing(): void
    {
        $this->assertSame([], $this->plan(null, 'not_in_voice'));
    }

    public function test_seconds_in_a_window_count_open_sessions_up_to_their_last_sample(): void
    {
        $rows = [
            (object) ['started_at' => '2026-11-08 09:00:00', 'ended_at' => '2026-11-08 10:00:00', 'last_seen_at' => '2026-11-08 09:57:30'],
            (object) ['started_at' => '2026-11-08 11:30:00', 'ended_at' => null, 'last_seen_at' => '2026-11-08 11:50:00'],
            (object) ['started_at' => '2026-11-07 23:30:00', 'ended_at' => '2026-11-08 00:30:00', 'last_seen_at' => '2026-11-08 00:27:30'],
            (object) ['started_at' => null, 'ended_at' => null, 'last_seen_at' => null],
        ];

        $seconds = VoiceSessionService::secondsInWindow($rows, Carbon::parse('2026-11-08 00:00:00'), Carbon::parse('2026-11-08 12:00:00'));

        $this->assertSame(3600 + 1200 + 1800, $seconds);
    }
}

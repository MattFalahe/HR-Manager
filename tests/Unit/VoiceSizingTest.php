<?php

namespace HrManager\Tests\Unit;

use Carbon\Carbon;
use HrManager\Services\ActivityTrackingService;
use HrManager\Services\VoicePoller;
use HrManager\Tests\TestCase;

/**
 * How many members each poll interval can carry, and how they are spread
 * across it. Help, the README and the settings page all quote these figures
 * to admins choosing an interval, so they are pinned here.
 */
class VoiceSizingTest extends TestCase
{
    public function test_the_advice_table_admins_are_shown(): void
    {
        $sizing = ActivityTrackingService::sizing(100, 5);

        $this->assertSame(300, $sizing['safe_per_minute']);
        $this->assertSame(500, $sizing['capacity_per_minute']);
        $this->assertSame([5 => 1500, 10 => 3000, 15 => 4500, 20 => 6000, 30 => 9000, 60 => 18000], $sizing['max_members']);
        $this->assertSame('hr_ceiling', $sizing['rate_source']);
    }

    public function test_verdicts_and_the_recommended_interval(): void
    {
        $fits = ActivityTrackingService::sizing(1500, 5);
        $this->assertSame('ok', $fits['verdict']);
        $this->assertSame(5, $fits['recommended']);
        $this->assertSame(300, $fits['per_minute']);
        $this->assertSame(18000, $fits['per_hour']);

        $tight = ActivityTrackingService::sizing(2400, 5);
        $this->assertSame('tight', $tight['verdict']);
        $this->assertSame(10, $tight['recommended']);

        $over = ActivityTrackingService::sizing(2600, 5);
        $this->assertSame('over', $over['verdict']);
        $this->assertSame(10, $over['recommended']);

        $huge = ActivityTrackingService::sizing(50000, 5);
        $this->assertSame(60, $huge['recommended'], 'past every interval, the longest is still the best on offer');
    }

    public function test_a_lower_limit_measured_from_discord_takes_over(): void
    {
        $sizing = ActivityTrackingService::sizing(1000, 5, 5, 1.0);

        $this->assertSame('discord', $sizing['rate_source']);
        $this->assertSame(150, $sizing['safe_per_minute']);
        $this->assertSame(200, $sizing['per_minute']);
        $this->assertSame(10, $sizing['recommended']);
    }

    /** HR's own ceiling is there to leave room for SeAT Connector, whatever Discord allows. */
    public function test_a_higher_limit_from_discord_does_not_raise_hrs_ceiling(): void
    {
        $sizing = ActivityTrackingService::sizing(1000, 5, 50, 1.0);

        $this->assertSame('hr_ceiling', $sizing['rate_source']);
        $this->assertSame((float) VoicePoller::MAX_REQUESTS_PER_SECOND, (float) $sizing['rate']);
    }

    /**
     * Help says an install inside the table cannot reach Discord's
     * invalid-request ban even if every member sat in a hidden channel.
     */
    public function test_no_interval_in_the_table_can_reach_the_invalid_request_ceiling(): void
    {
        foreach (ActivityTrackingService::sizing(1, 5)['max_members'] as $interval => $max) {
            $sizing = ActivityTrackingService::sizing($max, $interval);
            $this->assertTrue($sizing['hidden_ok'], "{$max} members every {$interval} minutes");
            $this->assertLessThan(VoicePoller::INVALID_REQUEST_CEILING, $sizing['hidden_worst_10min']);
        }
    }

    public function test_every_member_lands_in_exactly_one_slice(): void
    {
        $members = [];
        for ($i = 0; $i < 1000; $i++) {
            $members[] = ['user_id' => $i + 1, 'discord_id' => (string) (180000000000000000 + $i * 7919)];
        }

        $seen = [];
        $sizes = [];
        for ($slot = 0; $slot < 5; $slot++) {
            $slice = ActivityTrackingService::sliceFor($members, $slot, 5);
            $sizes[] = count($slice);
            foreach ($slice as $m) {
                $seen[$m['user_id']] = ($seen[$m['user_id']] ?? 0) + 1;
            }
        }

        $this->assertCount(1000, $seen);
        $this->assertSame([1], array_values(array_unique($seen)));
        $this->assertGreaterThan(150, min($sizes), 'requests are spread across the interval, not bunched');
        $this->assertLessThan(250, max($sizes));
    }

    public function test_an_interval_of_one_polls_everybody_every_minute(): void
    {
        $members = [['user_id' => 1, 'discord_id' => '11'], ['user_id' => 2, 'discord_id' => '22']];

        $this->assertCount(2, ActivityTrackingService::sliceFor($members, 0, 1));
    }

    public function test_consecutive_minutes_walk_through_every_slot(): void
    {
        $slots = [];
        for ($minute = 0; $minute < 10; $minute++) {
            $slots[] = ActivityTrackingService::slotFor(Carbon::parse('2026-11-08 12:00:00')->addMinutes($minute), 5);
        }

        $this->assertCount(5, array_unique($slots));
        for ($i = 1; $i < 10; $i++) {
            $this->assertSame(($slots[$i - 1] + 1) % 5, $slots[$i]);
        }
    }
}

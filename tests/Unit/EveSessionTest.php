<?php

namespace HrManager\Tests\Unit;

use Carbon\Carbon;
use HrManager\Services\EveSessionService;
use HrManager\Tests\Fake\Rows;
use HrManager\Tests\TestCase;

/**
 * EVE play sessions, rebuilt from the one row per character SeAT keeps.
 *
 * SeAT holds only the latest login, the latest logout and an online flag, so
 * every session HR stores is inferred from successive snapshots of that row.
 * Get an inference wrong and a member's hours in game are wrong with nothing
 * on the page to show it.
 */
class EveSessionTest extends TestCase
{
    private function snapshot(array $fields): object
    {
        return (object) array_merge([
            'character_id' => 1001,
            'online'       => false,
            'last_login'   => null,
            'last_logout'  => null,
            'logins'       => 10,
            'updated_at'   => self::NOW,
        ], $fields);
    }

    private function session(array $fields): object
    {
        return (object) array_merge([
            'started_at'      => null,
            'ended_at'        => null,
            'last_seen_at'    => null,
            'logins_at_start' => 10,
        ], $fields);
    }

    private function plan(?object $latest, object $snapshot): array
    {
        return EveSessionService::plan($latest, $snapshot, Carbon::now());
    }

    private function at(array $fields, string $key): ?string
    {
        return isset($fields[$key]) ? $fields[$key]->toDateTimeString() : null;
    }

    public function test_a_character_that_never_logged_in_records_nothing(): void
    {
        $this->assertSame([], $this->plan(null, $this->snapshot([])));
    }

    public function test_a_login_while_online_opens_a_session(): void
    {
        $ops = $this->plan(null, $this->snapshot([
            'online'     => true,
            'last_login' => '2026-11-08 10:00:00',
            'updated_at' => '2026-11-08 11:45:00',
        ]));

        $this->assertCount(1, $ops);
        [$kind, $fields] = $ops[0];
        $this->assertSame('open', $kind);
        $this->assertSame('2026-11-08 10:00:00', $this->at($fields, 'started_at'));
        $this->assertNull($fields['ended_at']);
        $this->assertSame('2026-11-08 11:45:00', $this->at($fields, 'last_seen_at'));
        $this->assertSame(10, $fields['logins_at_start']);
    }

    public function test_a_session_that_finished_before_it_was_seen_is_stored_whole(): void
    {
        $ops = $this->plan(null, $this->snapshot([
            'last_login'  => '2026-11-08 08:00:00',
            'last_logout' => '2026-11-08 09:30:00',
        ]));

        [$kind, $fields] = $ops[0];
        $this->assertSame('open', $kind);
        $this->assertSame('2026-11-08 09:30:00', $this->at($fields, 'ended_at'));
        $this->assertFalse($fields['end_estimated']);
    }

    /**
     * A revoked token leaves the last snapshot frozen, online flag and all.
     * Trusting it would book an ever-growing session for somebody who left
     * weeks ago.
     */
    public function test_an_online_flag_on_a_stale_snapshot_is_not_trusted(): void
    {
        $ops = $this->plan(null, $this->snapshot([
            'online'      => true,
            'last_login'  => '2026-11-08 06:00:00',
            'last_logout' => '2026-11-07 23:00:00',
            'updated_at'  => '2026-11-08 07:00:00',
        ]));

        [$kind, $fields] = $ops[0];
        $this->assertSame('open', $kind);
        $this->assertSame('2026-11-08 06:00:00', $this->at($fields, 'ended_at'), 'nothing vouches for any length, so it counts as none');
        $this->assertTrue($fields['end_estimated']);
    }

    public function test_the_same_login_still_online_only_moves_the_last_sighting(): void
    {
        $latest = $this->session(['started_at' => '2026-11-08 10:00:00', 'last_seen_at' => '2026-11-08 11:00:00']);

        $ops = $this->plan($latest, $this->snapshot([
            'online'     => true,
            'last_login' => '2026-11-08 10:00:00',
            'updated_at' => '2026-11-08 11:50:00',
        ]));

        $this->assertCount(1, $ops);
        [$kind, $fields] = $ops[0];
        $this->assertSame('touch', $kind);
        $this->assertSame(['last_seen_at'], array_keys($fields));
        $this->assertSame('2026-11-08 11:50:00', $this->at($fields, 'last_seen_at'));
    }

    public function test_the_same_login_gone_offline_closes_at_the_logout(): void
    {
        $latest = $this->session(['started_at' => '2026-11-08 10:00:00', 'last_seen_at' => '2026-11-08 11:00:00']);

        $ops = $this->plan($latest, $this->snapshot([
            'last_login'  => '2026-11-08 10:00:00',
            'last_logout' => '2026-11-08 11:20:00',
        ]));

        [$kind, $fields] = $ops[0];
        $this->assertSame('close', $kind);
        $this->assertSame('2026-11-08 11:20:00', $this->at($fields, 'ended_at'));
        $this->assertFalse($fields['end_estimated']);
    }

    public function test_offline_without_a_logout_ends_at_the_last_sighting(): void
    {
        $latest = $this->session(['started_at' => '2026-11-08 10:00:00', 'last_seen_at' => '2026-11-08 11:00:00']);

        $ops = $this->plan($latest, $this->snapshot([
            'last_login'  => '2026-11-08 10:00:00',
            'last_logout' => '2026-11-07 22:00:00',
        ]));

        [$kind, $fields] = $ops[0];
        $this->assertSame('close', $kind);
        $this->assertSame('2026-11-08 11:00:00', $this->at($fields, 'ended_at'));
        $this->assertTrue($fields['end_estimated']);
    }

    public function test_a_closed_session_is_left_alone_on_the_next_read(): void
    {
        $latest = $this->session(['started_at' => '2026-11-08 10:00:00', 'ended_at' => '2026-11-08 11:20:00']);

        $this->assertSame([], $this->plan($latest, $this->snapshot([
            'last_login'  => '2026-11-08 10:00:00',
            'last_logout' => '2026-11-08 11:20:00',
        ])));
    }

    public function test_a_snapshot_older_than_the_stored_session_is_ignored(): void
    {
        $latest = $this->session(['started_at' => '2026-11-08 10:00:00']);

        $this->assertSame([], $this->plan($latest, $this->snapshot(['last_login' => '2026-11-07 10:00:00'])));
    }

    public function test_a_new_login_closes_the_open_session_at_the_logout_between_them(): void
    {
        $latest = $this->session(['started_at' => '2026-11-08 08:00:00', 'last_seen_at' => '2026-11-08 09:00:00', 'logins_at_start' => 10]);

        $ops = $this->plan($latest, $this->snapshot([
            'online'      => true,
            'last_login'  => '2026-11-08 11:00:00',
            'last_logout' => '2026-11-08 09:40:00',
            'logins'      => 11,
            'updated_at'  => '2026-11-08 11:30:00',
        ]));

        $this->assertSame(['close', 'open'], array_column($ops, 0));
        $this->assertSame('2026-11-08 09:40:00', $this->at($ops[0][1], 'ended_at'));
        $this->assertSame(0, $ops[0][1]['merged_logins']);
        $this->assertFalse($ops[0][1]['end_estimated']);
        $this->assertSame('2026-11-08 11:00:00', $this->at($ops[1][1], 'started_at'));
        $this->assertSame(11, $ops[1][1]['logins_at_start']);
    }

    /**
     * SeAT's logins counter keeps going between snapshots, so a session that
     * started and ended unseen still shows up as a gap in it. The stored
     * session then covers more than one real session and says so.
     */
    public function test_logins_missed_between_snapshots_are_counted_on_the_closed_session(): void
    {
        $latest = $this->session(['started_at' => '2026-11-08 08:00:00', 'logins_at_start' => 10]);

        $ops = $this->plan($latest, $this->snapshot([
            'online'      => true,
            'last_login'  => '2026-11-08 11:00:00',
            'last_logout' => '2026-11-08 10:15:00',
            'logins'      => 13,
            'updated_at'  => '2026-11-08 11:30:00',
        ]));

        $this->assertSame(2, $ops[0][1]['merged_logins']);
    }

    public function test_a_logout_that_belongs_to_the_new_session_does_not_end_the_old_one(): void
    {
        $latest = $this->session(['started_at' => '2026-11-08 08:00:00', 'last_seen_at' => '2026-11-08 08:50:00']);

        $ops = $this->plan($latest, $this->snapshot([
            'last_login'  => '2026-11-08 10:00:00',
            'last_logout' => '2026-11-08 10:30:00',
        ]));

        $this->assertSame(['close', 'open'], array_column($ops, 0));
        $this->assertSame('2026-11-08 08:50:00', $this->at($ops[0][1], 'ended_at'));
        $this->assertTrue($ops[0][1]['end_estimated']);
        $this->assertSame('2026-11-08 10:30:00', $this->at($ops[1][1], 'ended_at'));
    }

    public function test_two_alts_played_side_by_side_count_once(): void
    {
        $from = Carbon::parse('2026-11-08 00:00:00');
        $to = Carbon::parse('2026-11-08 12:00:00');

        $seconds = EveSessionService::mergedSeconds([
            [Carbon::parse('2026-11-08 08:00:00'), Carbon::parse('2026-11-08 10:00:00')],
            [Carbon::parse('2026-11-08 09:00:00'), Carbon::parse('2026-11-08 11:00:00')],
            [Carbon::parse('2026-11-08 09:15:00'), Carbon::parse('2026-11-08 09:45:00')],
        ], $from, $to);

        $this->assertSame(3 * 3600, $seconds);
    }

    public function test_separate_sessions_add_up_and_are_clipped_to_the_window(): void
    {
        $from = Carbon::parse('2026-11-08 00:00:00');
        $to = Carbon::parse('2026-11-08 12:00:00');

        $seconds = EveSessionService::mergedSeconds([
            [Carbon::parse('2026-11-07 23:00:00'), Carbon::parse('2026-11-08 01:00:00')],
            [Carbon::parse('2026-11-08 05:00:00'), Carbon::parse('2026-11-08 05:30:00')],
            [Carbon::parse('2026-11-08 11:00:00'), Carbon::parse('2026-11-08 14:00:00')],
            [Carbon::parse('2026-11-06 10:00:00'), Carbon::parse('2026-11-06 11:00:00')],
        ], $from, $to);

        $this->assertSame(3600 + 1800 + 3600, $seconds);
        $this->assertSame(0, EveSessionService::mergedSeconds([], $from, $to));
    }

    public function test_last_in_game_reads_the_snapshot_and_ignores_a_stale_online_flag(): void
    {
        Rows::table('character_onlines', [
            ['character_id' => 1001, 'online' => 1, 'last_login' => '2026-11-08 10:00:00', 'last_logout' => '2026-11-07 22:00:00', 'updated_at' => '2026-11-08 11:40:00'],
            ['character_id' => 1002, 'online' => 1, 'last_login' => '2026-11-01 10:00:00', 'last_logout' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-02 08:00:00'],
            ['character_id' => 1003, 'online' => 0, 'last_login' => '2026-11-05 10:00:00', 'last_logout' => '2026-11-05 13:00:00', 'updated_at' => '2026-11-08 11:00:00'],
        ]);

        $read = (new EveSessionService())->lastInGame([1001, 1002, 1003, 1004], Carbon::now());

        $this->assertTrue($read[1001]['online']);
        $this->assertSame('2026-11-08 11:40:00', $read[1001]['last']->toDateTimeString());
        $this->assertFalse($read[1002]['online'], 'a frozen snapshot is not somebody online for a week');
        $this->assertSame('2026-11-01 10:00:00', $read[1002]['last']->toDateTimeString());
        $this->assertSame('2026-11-05 13:00:00', $read[1003]['last']->toDateTimeString());
        $this->assertArrayNotHasKey(1004, $read);
    }

    public function test_last_in_game_is_empty_without_the_table(): void
    {
        $this->assertSame([], (new EveSessionService())->lastInGame([1001]));
    }
}

<?php

namespace HrManager\Tests\Unit;

use HrManager\Services\PlayerService;
use HrManager\Services\TierService;
use HrManager\Tests\Fake\Rows;
use HrManager\Tests\TestCase;

/**
 * When a character was last active, and which rows may say so.
 *
 * This figure decides whether somebody is Active, At Risk, Inactive or Dead
 * Weight, and dead weight gets a director a webhook, so a source quietly
 * dropping out of it marks a real member down for nothing.
 */
class LastActivityTest extends TestCase
{
    private const RAT_TYPES = ['bounty_prizes', 'bounty_prize', 'agent_mission_reward', 'agent_mission_time_bonus_reward'];

    private function service(): PlayerService
    {
        return new PlayerService(new TierService());
    }

    /**
     * One corp, one character per case. Every table the read looks at exists,
     * so a case that comes back empty did so on the rows, not on a missing
     * table.
     */
    private function seed(array $tables = []): void
    {
        Rows::table('character_affiliations', $tables['character_affiliations'] ?? []);
        Rows::table('corporation_member_trackings', $tables['corporation_member_trackings'] ?? []);
        Rows::table('character_wallet_journals', $tables['character_wallet_journals'] ?? []);
        Rows::table('mining_ledger', $tables['mining_ledger'] ?? []);
        Rows::table('corporation_wallet_journals', $tables['corporation_wallet_journals'] ?? []);
    }

    private function activity(int $characterId): ?string
    {
        return $this->service()->lastActivityForMany([$characterId])[$characterId]?->toDateTimeString();
    }

    public function test_the_newest_of_every_source_wins(): void
    {
        $this->seed([
            'character_affiliations'       => [['character_id' => 1001, 'corporation_id' => 2000]],
            'corporation_member_trackings' => [['character_id' => 1001, 'logon_date' => '2026-11-07 18:00:00']],
            'character_wallet_journals'    => [
                ['character_id' => 1001, 'date' => '2026-11-01 09:00:00'],
                ['character_id' => 1001, 'date' => '2026-10-02 09:00:00'],
            ],
        ]);

        $this->assertSame('2026-11-07 18:00:00', $this->activity(1001));
    }

    /**
     * The corp journal is not a duplicate of the character's own. A member
     * whose wallet scope is missing or revoked has no personal rows at all,
     * and the corp paying them a bounty is then the only record that they were
     * out ratting. Dropping this source ages exactly those members toward dead
     * weight for no reason.
     */
    public function test_a_corp_bounty_counts_when_the_character_has_no_wallet_of_their_own(): void
    {
        $this->seed([
            'character_affiliations'      => [['character_id' => 1002, 'corporation_id' => 2000]],
            'corporation_wallet_journals' => [
                ['corporation_id' => 2000, 'second_party_id' => 1002, 'ref_type' => 'bounty_prizes', 'date' => '2026-10-20 22:00:00'],
            ],
        ]);

        $this->assertSame('2026-10-20 22:00:00', $this->activity(1002));
    }

    public function test_an_agent_mission_reward_counts_as_activity(): void
    {
        $this->seed([
            'character_affiliations'      => [['character_id' => 1007, 'corporation_id' => 2000]],
            'corporation_wallet_journals' => [
                ['corporation_id' => 2000, 'second_party_id' => 1007, 'ref_type' => 'agent_mission_reward', 'date' => '2026-11-03 22:00:00'],
            ],
        ]);

        $this->assertSame('2026-11-03 22:00:00', $this->activity(1007));
    }

    /** Someone paying the corp is not the corp paying them for shooting things. */
    public function test_a_corp_journal_row_that_is_not_ratting_is_not_activity(): void
    {
        $this->seed([
            'character_affiliations'      => [['character_id' => 1008, 'corporation_id' => 2000]],
            'corporation_wallet_journals' => [
                ['corporation_id' => 2000, 'second_party_id' => 1008, 'ref_type' => 'player_donation', 'date' => '2026-11-08 08:00:00'],
            ],
        ]);

        $this->assertNull($this->activity(1008));
    }

    /**
     * Scoped to the corp the character is in now. A payout from a corp they
     * left is not their current corp's record of them, and the capability this
     * replaced was only ever asked about one corp.
     */
    /**
     * A batch spans whichever corps its characters are in, so the read asks
     * about all of them at once and then has to hand each row to the right
     * character. 1005 has left 2999 and 1009 is still there, so 2999's rows
     * are in front of the read and only 1009 may have them.
     */
    public function test_a_payout_from_a_corp_they_have_left_is_not_counted(): void
    {
        $this->seed([
            'character_affiliations' => [
                ['character_id' => 1005, 'corporation_id' => 2000],
                ['character_id' => 1009, 'corporation_id' => 2999],
            ],
            'character_wallet_journals'   => [['character_id' => 1005, 'date' => '2026-09-01 09:00:00']],
            'corporation_wallet_journals' => [
                ['corporation_id' => 2999, 'second_party_id' => 1005, 'ref_type' => 'bounty_prizes', 'date' => '2026-11-06 22:00:00'],
                ['corporation_id' => 2999, 'second_party_id' => 1009, 'ref_type' => 'bounty_prizes', 'date' => '2026-11-05 22:00:00'],
            ],
        ]);

        $activity = $this->service()->lastActivityForMany([1005, 1009]);

        $this->assertSame('2026-09-01 09:00:00', $activity[1005]->toDateTimeString(), 'the payout from the corp they left must not count');
        $this->assertSame('2026-11-05 22:00:00', $activity[1009]->toDateTimeString(), 'the character still in that corp keeps theirs');
    }

    public function test_a_bounty_older_than_the_window_is_not_counted(): void
    {
        $this->seed([
            'character_affiliations'      => [['character_id' => 1006, 'corporation_id' => 2000]],
            'corporation_wallet_journals' => [
                ['corporation_id' => 2000, 'second_party_id' => 1006, 'ref_type' => 'bounty_prizes', 'date' => '2025-01-01 22:00:00'],
            ],
        ]);

        $this->assertNull($this->activity(1006));
    }

    /**
     * Mining Manager sets aside the second copy of mining that both its ESI
     * sources reported. A set-aside row is not a separate day's mining, and
     * reading it would date activity to whenever the duplicate was stamped.
     */
    public function test_a_set_aside_mining_row_is_not_counted(): void
    {
        $this->seed([
            'character_affiliations' => [['character_id' => 1004, 'corporation_id' => 2000]],
            'mining_ledger'          => [
                ['character_id' => 1004, 'date' => '2026-11-05', 'deleted_at' => null],
                ['character_id' => 1004, 'date' => '2026-11-08', 'deleted_at' => '2026-11-08 01:00:00'],
            ],
        ]);

        $this->assertSame('2026-11-05 00:00:00', $this->activity(1004));
    }

    public function test_a_character_with_nothing_anywhere_has_no_activity(): void
    {
        $this->seed(['character_affiliations' => [['character_id' => 1003, 'corporation_id' => 2000]]]);

        $this->assertNull($this->activity(1003));
    }

    /** An install without Mining Manager must not read for a table that is not there. */
    public function test_a_missing_table_is_skipped_rather_than_fatal(): void
    {
        Rows::reset();
        Rows::table('character_affiliations', [['character_id' => 1001, 'corporation_id' => 2000]]);
        Rows::table('corporation_member_trackings', [['character_id' => 1001, 'logon_date' => '2026-11-07 18:00:00']]);

        $this->assertSame('2026-11-07 18:00:00', $this->activity(1001));
    }

    // -----------------------------------------------------------------
    // What the batch is for. A read per character and a read per batch both
    // give the right answer; the whole point is the difference in cost.
    // -----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function roster(int $size): array
    {
        $affiliations = [];
        $logins = [];
        for ($i = 1; $i <= $size; $i++) {
            $affiliations[] = ['character_id' => 3000 + $i, 'corporation_id' => 2000];
            $logins[] = ['character_id' => 3000 + $i, 'logon_date' => '2026-11-0' . (($i % 7) + 1) . '12:00:00'];
        }

        $this->seed([
            'character_affiliations'       => $affiliations,
            'corporation_member_trackings' => $logins,
        ]);

        return $affiliations;
    }

    public function test_the_cost_does_not_grow_with_the_number_of_characters(): void
    {
        $this->roster(5);
        Rows::forgetReads();
        $this->service()->lastActivityForMany(range(3001, 3005));
        $five = Rows::countReads();

        $this->roster(50);
        Rows::forgetReads();
        $this->service()->lastActivityForMany(range(3001, 3050));
        $fifty = Rows::countReads();

        $this->assertSame($five, $fifty, 'ten times the roster must not cost ten times the reads');
        $this->assertLessThan(10, $fifty);
    }

    public function test_asking_twice_reads_once(): void
    {
        $this->roster(5);
        $service = $this->service();
        $service->lastActivityForMany(range(3001, 3005));

        Rows::forgetReads();
        $second = $service->lastActivityForMany(range(3001, 3005));

        $this->assertSame(0, Rows::countReads());
        $this->assertSame('2026-11-02 12:00:00', $second[3001]->toDateTimeString());
    }

    /**
     * "Looked and found nothing" has to be remembered as an answer. Holding it
     * as an absent key instead would send every inactive member back to the
     * database on every corp in the pass, which is most of the roster on the
     * nights it matters.
     */
    public function test_finding_no_activity_is_remembered_as_an_answer(): void
    {
        $this->seed(['character_affiliations' => [['character_id' => 1003, 'corporation_id' => 2000]]]);

        $service = $this->service();
        $this->assertNull($service->lastActivityForMany([1003])[1003]);

        Rows::forgetReads();
        $this->assertNull($service->lastActivityForMany([1003])[1003]);
        $this->assertSame(0, Rows::countReads());
    }

    public function test_priming_the_whole_roster_answers_each_player_in_turn(): void
    {
        $this->roster(20);
        $service = $this->service();

        $service->primeActivityCache(range(3001, 3020));
        Rows::forgetReads();

        foreach (range(3001, 3020) as $characterId) {
            $this->assertNotNull($service->lastActivityForMany([$characterId])[$characterId]);
        }

        $this->assertSame(0, Rows::countReads(), 'a primed roster must not read again per player');
    }

    public function test_nothing_asked_for_reads_nothing(): void
    {
        $this->seed();

        $this->assertSame([], $this->service()->lastActivityForMany([]));
        $this->assertSame(0, Rows::countReads());
    }

    /** An id that cannot be a character is dropped rather than queried for. */
    public function test_an_unusable_id_is_discarded(): void
    {
        $this->seed(['character_affiliations' => [['character_id' => 1001, 'corporation_id' => 2000]]]);

        $this->assertSame([], $this->service()->lastActivityForMany([0, -5]));
    }

    public function test_every_ratting_ref_type_the_corp_journal_can_carry_is_read(): void
    {
        foreach (self::RAT_TYPES as $index => $refType) {
            $characterId = 4000 + $index;
            $this->seed([
                'character_affiliations'      => [['character_id' => $characterId, 'corporation_id' => 2000]],
                'corporation_wallet_journals' => [
                    ['corporation_id' => 2000, 'second_party_id' => $characterId, 'ref_type' => $refType, 'date' => '2026-11-04 10:00:00'],
                ],
            ]);

            $this->assertSame('2026-11-04 10:00:00', $this->activity($characterId), $refType);
        }
    }
}

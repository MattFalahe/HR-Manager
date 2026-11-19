<?php

namespace HrManager\Tests\Unit;

use HrManager\Services\CurrentMemberService;
use HrManager\Tests\Fake\Rows;
use HrManager\Tests\TestCase;

/**
 * Who counts as in the corp right now.
 *
 * Corp Health narrows every classification it counts to this list, and the
 * classifier picks its subjects by it, so it decides both who appears as dead
 * weight and who the nightly pass classifies at all. A departed member slipping
 * through is the bug a director reported: somebody purged weeks earlier still
 * listed as dead weight.
 */
class CurrentMemberTest extends TestCase
{
    private function members(int $corporationId): ?array
    {
        return (new CurrentMemberService())->userIds($corporationId);
    }

    public function test_each_person_counts_once_whatever_their_alts(): void
    {
        Rows::table('character_affiliations', [
            ['character_id' => 1001, 'corporation_id' => 2000],
            ['character_id' => 1002, 'corporation_id' => 2000],
            ['character_id' => 1003, 'corporation_id' => 2000],
        ]);
        Rows::table('refresh_tokens', [
            ['character_id' => 1001, 'user_id' => 500, 'deleted_at' => null],
            ['character_id' => 1002, 'user_id' => 500, 'deleted_at' => null],
            ['character_id' => 1003, 'user_id' => 501, 'deleted_at' => null],
        ]);

        $this->assertSame([500, 501], $this->members(2000));
    }

    /** The case that was reported: gone from the corp, row still on disk. */
    public function test_somebody_who_left_is_not_a_member(): void
    {
        Rows::table('character_affiliations', [
            ['character_id' => 1001, 'corporation_id' => 2000],
            ['character_id' => 1004, 'corporation_id' => 3000],
        ]);
        Rows::table('refresh_tokens', [
            ['character_id' => 1001, 'user_id' => 500, 'deleted_at' => null],
            ['character_id' => 1004, 'user_id' => 502, 'deleted_at' => null],
        ]);

        $this->assertSame([500], $this->members(2000));
    }

    /**
     * Kept in step with the classifier, which only classifies accounts with a
     * live token: a row shown here for a revoked-only account would be one the
     * nightly prune deletes.
     */
    public function test_a_character_whose_token_was_revoked_does_not_keep_its_account_in(): void
    {
        Rows::table('character_affiliations', [
            ['character_id' => 1001, 'corporation_id' => 2000],
            ['character_id' => 1002, 'corporation_id' => 2000],
        ]);
        Rows::table('refresh_tokens', [
            ['character_id' => 1001, 'user_id' => 500, 'deleted_at' => '2026-11-01 10:00:00'],
            ['character_id' => 1002, 'user_id' => 501, 'deleted_at' => null],
        ]);

        $this->assertSame([501], $this->members(2000));
    }

    public function test_an_account_with_one_alt_still_inside_stays_a_member(): void
    {
        Rows::table('character_affiliations', [
            ['character_id' => 1001, 'corporation_id' => 3000],
            ['character_id' => 1002, 'corporation_id' => 2000],
        ]);
        Rows::table('refresh_tokens', [
            ['character_id' => 1001, 'user_id' => 500, 'deleted_at' => null],
            ['character_id' => 1002, 'user_id' => 500, 'deleted_at' => null],
        ]);

        $this->assertSame([500], $this->members(2000));
    }

    /**
     * Nobody registered left in the corp is an answer, and it has to filter
     * every old row out. The nightly prune skips such a corp on purpose, so
     * this read is the only thing that stops its stale rows counting.
     */
    public function test_a_corp_with_nobody_registered_left_has_no_members(): void
    {
        Rows::table('character_affiliations', [['character_id' => 1001, 'corporation_id' => 2000]]);
        Rows::table('refresh_tokens', [['character_id' => 1001, 'user_id' => 500, 'deleted_at' => '2026-10-01 00:00:00']]);

        $this->assertSame([], $this->members(2000));
        $this->assertSame([], $this->members(4000));
    }

    /** Without SeAT's tables nothing is known, which is not the same as nobody. */
    public function test_missing_tables_mean_unknown_not_empty(): void
    {
        $this->assertNull($this->members(2000));

        Rows::table('character_affiliations', []);
        $this->assertNull($this->members(2000), 'refresh_tokens is still missing');
    }

    public function test_a_big_corp_is_read_in_chunks_and_once_per_request(): void
    {
        $affiliations = [];
        $tokens = [];
        for ($i = 0; $i < 2500; $i++) {
            $affiliations[] = ['character_id' => 10000 + $i, 'corporation_id' => 2000];
            $tokens[] = ['character_id' => 10000 + $i, 'user_id' => 500 + intdiv($i, 5), 'deleted_at' => null];
        }
        Rows::table('character_affiliations', $affiliations);
        Rows::table('refresh_tokens', $tokens);

        $service = new CurrentMemberService();
        $first = $service->userIds(2000);

        $this->assertCount(500, $first);
        $this->assertSame(1 + 3, Rows::countReads(), 'one roster read, then tokens in three chunks');

        Rows::forgetReads();
        $this->assertSame($first, $service->userIds(2000));
        $this->assertSame(0, Rows::countReads(), 'the page asks several times; it is read once');
    }
}

<?php

namespace HrManager\Tests\Unit;

use HrManager\Services\PlayerService;
use HrManager\Services\TierService;
use HrManager\Tests\Fake\Rows;
use HrManager\Tests\TestCase;

/**
 * The three signals the nightly classifier reads off a player.
 *
 * Two character sets are in play and they are not interchangeable. Activity
 * rolls up over every character the account has ever linked, revoked tokens
 * included, because a token lost last week does not undo the fact that the
 * human was playing. The wallet, blueprint and buyback passes score only live
 * tokens, since there is no fresh data behind a dead one.
 */
class ClassifierSignalsTest extends TestCase
{
    private function service(): PlayerService
    {
        return new PlayerService(new TierService());
    }

    /**
     * One account: a main logging in, a mining alt, a dormant alt, and an alt
     * whose token was revoked last month.
     */
    private function seedAccount(): void
    {
        Rows::table('users', [['id' => 500, 'main_character_id' => 1001]]);

        Rows::table('refresh_tokens', [
            ['character_id' => 1001, 'user_id' => 500, 'deleted_at' => null],
            ['character_id' => 1004, 'user_id' => 500, 'deleted_at' => null],
            ['character_id' => 1003, 'user_id' => 500, 'deleted_at' => null],
            ['character_id' => 1002, 'user_id' => 500, 'deleted_at' => '2026-10-01 00:00:00'],
        ]);

        Rows::table('character_infos', [
            ['character_id' => 1001, 'name' => 'Main', 'security_status' => 0, 'total_sp' => 0],
            ['character_id' => 1002, 'name' => 'Revoked Alt', 'security_status' => 0, 'total_sp' => 0],
            ['character_id' => 1003, 'name' => 'Dormant Alt', 'security_status' => 0, 'total_sp' => 0],
            ['character_id' => 1004, 'name' => 'Mining Alt', 'security_status' => 0, 'total_sp' => 0],
        ]);

        Rows::table('character_affiliations', [
            ['character_id' => 1001, 'corporation_id' => 2000],
            ['character_id' => 1002, 'corporation_id' => 2000],
            ['character_id' => 1003, 'corporation_id' => 2000],
            ['character_id' => 1004, 'corporation_id' => 2000],
        ]);

        Rows::table('corporation_member_trackings', [
            ['character_id' => 1001, 'logon_date' => '2026-11-07 18:00:00'],
        ]);

        Rows::table('mining_ledger', [
            ['character_id' => 1004, 'date' => '2026-10-30', 'deleted_at' => null],
        ]);

        // The revoked alt's only trace is the corp paying it a bounty.
        Rows::table('corporation_wallet_journals', [
            ['corporation_id' => 2000, 'second_party_id' => 1002, 'ref_type' => 'bounty_prizes', 'date' => '2026-11-08 02:00:00'],
        ]);

        Rows::table('character_wallet_journals', []);
    }

    public function test_only_live_tokens_are_scored_for_wallet_signals(): void
    {
        $this->seedAccount();

        $characters = $this->service()->getClassifierSignals(500)['characters'];

        $this->assertEqualsCanonicalizing(
            [1001, 1003, 1004],
            $characters->pluck('character_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_activity_counts_a_character_whose_token_was_revoked(): void
    {
        $this->seedAccount();

        $signals = $this->service()->getClassifierSignals(500);

        $this->assertArrayHasKey(1002, $signals['alt_activity']);
        $this->assertSame('2026-11-08 02:00:00', $signals['alt_activity'][1002]->toDateTimeString());
    }

    /**
     * Any active character makes the person active. The freshest signal across
     * the account wins, so a main flown daily is never dead weight because a
     * spare alt has sat unused.
     */
    public function test_last_activity_is_the_newest_across_the_whole_account(): void
    {
        $this->seedAccount();

        $signals = $this->service()->getClassifierSignals(500);

        $this->assertSame('2026-11-08 02:00:00', $signals['last_activity_at']->toDateTimeString());
    }

    public function test_a_character_with_no_activity_is_present_and_null(): void
    {
        $this->seedAccount();

        $activity = $this->service()->getClassifierSignals(500)['alt_activity'];

        $this->assertArrayHasKey(1003, $activity);
        $this->assertNull($activity[1003]);
    }

    /**
     * The dormant-alt badge counts characters idle past 90 days, or with no
     * signal at all, and needs one entry per character the account owns. It is
     * informational and never moves the category, so a wrong count is a wrong
     * badge rather than a wrong verdict, but it is still read by a director.
     */
    public function test_every_owned_character_is_present_for_the_dormant_count(): void
    {
        $this->seedAccount();

        $activity = $this->service()->getClassifierSignals(500)['alt_activity'];

        $this->assertEqualsCanonicalizing([1001, 1002, 1003, 1004], array_keys($activity));

        $dormant = 0;
        foreach ($activity as $stamp) {
            if ($stamp === null || $stamp->diffInDays(now()) > 90) {
                $dormant++;
            }
        }

        $this->assertSame(1, $dormant, 'only the alt with no signal at all is dormant here');
    }

    public function test_an_account_with_no_characters_yields_nothing_rather_than_failing(): void
    {
        Rows::table('users', [['id' => 999, 'main_character_id' => null]]);
        Rows::table('refresh_tokens', []);
        Rows::table('character_infos', []);
        Rows::table('character_affiliations', []);

        $signals = $this->service()->getClassifierSignals(999);

        $this->assertTrue($signals['characters']->isEmpty());
        $this->assertSame([], $signals['alt_activity']);
        $this->assertNull($signals['last_activity_at']);
    }

    /**
     * Nothing in the signals varies by corp, which is what lets one resolution
     * answer every corp in the pass. If a corp ever has to be taken into
     * account, this is the test that has to change first.
     */
    public function test_the_signals_are_the_same_whichever_corp_is_being_classified(): void
    {
        $this->seedAccount();
        $service = $this->service();

        $first = $service->getClassifierSignals(500);
        Rows::forgetReads();
        $second = $service->getClassifierSignals(500);

        $this->assertSame(
            $first['last_activity_at']->toDateTimeString(),
            $second['last_activity_at']->toDateTimeString()
        );
        $this->assertSame(
            array_map(fn ($s) => $s?->toDateTimeString(), $first['alt_activity']),
            array_map(fn ($s) => $s?->toDateTimeString(), $second['alt_activity'])
        );
    }
}

<?php

namespace HrManager\Tests\Unit;

use HrManager\Support\TierLevel;
use HrManager\Tests\TestCase;

/**
 * The thresholds the classifier measures everybody against. A default moving
 * silently re-buckets a whole roster overnight and sends the webhooks to match.
 */
class TierLevelTest extends TestCase
{
    public function test_the_thresholds_are_what_the_tiers_were_set_to(): void
    {
        $this->assertNull(TierLevel::defaultThresholdDays(TierLevel::APPLICANT), 'a recruit is not expected to be active yet');
        $this->assertSame(90, TierLevel::defaultThresholdDays(TierLevel::MEMBER));
        $this->assertSame(30, TierLevel::defaultThresholdDays(TierLevel::JUNIOR_OFFICER));
        $this->assertSame(14, TierLevel::defaultThresholdDays(TierLevel::SENIOR_OFFICER));
        $this->assertSame(14, TierLevel::defaultThresholdDays(TierLevel::DIRECTOR));
    }

    /** Responsibility tightens the window, never loosens it. */
    public function test_a_higher_tier_is_never_given_longer_than_a_lower_one(): void
    {
        $previous = null;
        foreach ([TierLevel::MEMBER, TierLevel::JUNIOR_OFFICER, TierLevel::SENIOR_OFFICER, TierLevel::DIRECTOR] as $level) {
            $days = TierLevel::defaultThresholdDays($level);
            $this->assertNotNull($days);
            if ($previous !== null) {
                $this->assertLessThanOrEqual($previous, $days);
            }
            $previous = $days;
        }
    }

    public function test_an_unknown_level_has_no_threshold_rather_than_a_guessed_one(): void
    {
        $this->assertNull(TierLevel::defaultThresholdDays(99));
        $this->assertFalse(TierLevel::isValid(99));
    }

    public function test_every_tier_is_valid_and_has_a_badge(): void
    {
        foreach ([TierLevel::APPLICANT, TierLevel::MEMBER, TierLevel::JUNIOR_OFFICER, TierLevel::SENIOR_OFFICER, TierLevel::DIRECTOR] as $level) {
            $this->assertTrue(TierLevel::isValid($level));
            $this->assertArrayHasKey($level, TierLevel::BADGE_CLASS);
        }
    }
}

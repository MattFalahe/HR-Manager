<?php

namespace HrManager\Console\Commands;

use HrManager\Services\OnboardingService;
use Illuminate\Console\Command;

/**
 * Posts queued new-member onboarding welcomes whose delay has elapsed. The delay
 * exists so SeAT Connector has assigned the member's Discord role before the
 * welcome pings them; this sweep runs often enough to fire close to that mark.
 * A welcome for a member who left during the wait is dropped.
 */
class SendOnboardingWelcomesCommand extends Command
{
    protected $signature = 'hr-manager:send-onboarding-welcomes';

    protected $description = 'Post queued new-member onboarding welcomes whose delay has elapsed';

    public function handle(OnboardingService $service): int
    {
        $result = $service->dispatchDue();

        $this->info(sprintf(
            'Onboarding welcomes — sent: %d, dropped (left during the wait): %d, still waiting: %d, given up on: %d',
            $result['sent'],
            $result['dropped'],
            $result['deferred'],
            $result['expired']
        ));

        // "Still waiting" is the normal state for a member who hasn't linked
        // Discord yet — but it's also what a missing webhook looks like, and
        // that one an operator needs pointing at.
        if ($result['deferred'] > 0) {
            $this->line('  Waiting welcomes are held until the member is confirmed in the corp, a webhook subscribes to the onboarding category, and the member has a linked Discord identity. Given up on after 7 days.');
        }

        if ($result['expired'] > 0) {
            $this->warn('  ' . $result['expired'] . ' welcome(s) waited 7 days without their conditions being met and were abandoned. Check that a webhook has the "New-member onboarding welcome" category enabled.');
        }

        return 0;
    }
}

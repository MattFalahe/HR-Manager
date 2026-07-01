<?php

namespace HrManager\Console\Commands;

use HrManager\Services\MembershipChangeService;
use Illuminate\Console\Command;

/**
 * Diff SeAT's live corp roster against HR's snapshot and notify on joins /
 * leaves. Forward-only: the first run for a corp seeds its roster silently, so
 * existing members are never announced — only later changes notify.
 *
 * Joins are classified (alt of a current member / valid application / no
 * application) and routed to the matching webhook category. See
 * MembershipChangeService.
 */
class DetectMembershipChangesCommand extends Command
{
    protected $signature = 'hr-manager:detect-membership-changes
                            {--dry-run : Report what would change without writing or notifying}';

    protected $description = 'Detect corp joins / leaves and notify (forward-only; first run seeds silently)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $summary = app(MembershipChangeService::class)->detect($dry);

        $prefix = $dry ? '[dry-run] ' : '';
        $this->info(sprintf(
            '%sScanned %d corp(s): seeded %d, joined %d (no-application %d, unregistered %d), left %d, registration cleared %d.',
            $prefix,
            $summary['corps'],
            $summary['seeded'],
            $summary['joined'],
            $summary['no_application'],
            $summary['unregistered'],
            $summary['left'],
            $summary['registered_cleared']
        ));

        return 0;
    }
}

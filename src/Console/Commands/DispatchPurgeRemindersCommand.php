<?php

namespace HrManager\Console\Commands;

use HrManager\Services\PurgeService;
use Illuminate\Console\Command;

/**
 * Daily cron: scan player_status rows flagged for purge + fire any due
 * milestone reminders (T-7d / T-3d / T-48h / T-0). Dedup via the
 * purge_reminders unique constraint — cron is safe to run multiple times.
 */
class DispatchPurgeRemindersCommand extends Command
{
    protected $signature = 'hr-manager:dispatch-purge-reminders';

    protected $description = 'Send due purge reminder notifications (T-7d / T-3d / T-48h / T-0)';

    public function handle(PurgeService $purge): int
    {
        $this->info('HR Manager: dispatching purge reminders...');

        $counts = $purge->dispatchDue();

        foreach ($counts as $milestone => $count) {
            if ($count > 0) {
                $this->line("  {$milestone}: {$count} dispatched");
            }
        }

        $total = array_sum($counts);
        $this->info("Done. {$total} reminder(s) dispatched.");

        // Opt-in safety auto squad cleanup: clear manual/hidden squads (minus
        // the operator's excluded list) for purges that have left the corp or
        // reached T-minus-X of the kick date. No-op when the toggle is off.
        $squadsProcessed = $purge->processAutoSquadRemovals();
        if ($squadsProcessed > 0) {
            $this->info("Auto squad cleanup: {$squadsProcessed} purge(s) processed.");
        }

        return 0;
    }
}

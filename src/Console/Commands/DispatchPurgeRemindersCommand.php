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

        return 0;
    }
}

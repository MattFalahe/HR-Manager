<?php

namespace HrManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use HrManager\Models\Application;

class CleanupExpiredApplicationsCommand extends Command
{
    protected $signature = 'hr-manager:cleanup
                            {--days=90 : Delete soft-deleted applications older than this many days}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Clean up old soft-deleted applications and expired data';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        // Its own step, ahead of the application cleanup, which returns early
        // when there is nothing to delete. --days does not apply here: the
        // window is the Activity Tracking retention setting.
        $this->pruneActivitySessions((bool) $dryRun);

        $this->info("HR Manager: Cleaning up applications soft-deleted more than {$days} days ago...");

        $ids = Application::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days))
            ->pluck('id')
            ->all();

        $count = count($ids);

        if ($count === 0) {
            $this->info('No applications to clean up.');
            return 0;
        }

        // Polymorphic notes don't have a foreign-key constraint to applications
        // (the noteable_type is a string discriminator), so the application's
        // FK cascade doesn't reach them. Without explicit deletion here the
        // notes orphan forever.
        $orphanedNotes = DB::table('hr_manager_notes')
            ->where('noteable_type', 'application')
            ->whereIn('noteable_id', $ids)
            ->count();

        if ($dryRun) {
            $this->info("[Dry run] Would permanently delete {$count} application(s) and {$orphanedNotes} associated note(s).");
            return 0;
        }

        DB::transaction(function () use ($ids) {
            DB::table('hr_manager_notes')
                ->where('noteable_type', 'application')
                ->whereIn('noteable_id', $ids)
                ->delete();

            Application::whereIn('id', $ids)->forceDelete();
        });

        $this->info("Permanently deleted {$count} application(s) and {$orphanedNotes} associated note(s).");

        return 0;
    }

    private function pruneActivitySessions(bool $dryRun): void
    {
        try {
            $tracking = app(\HrManager\Services\ActivityTrackingService::class);
            $removed = $tracking->prune($dryRun);
            $days = $tracking->retentionDays();

            $this->info(sprintf(
                '%sEVE and voice sessions older than %d days: %d EVE, %d voice %s.',
                $dryRun ? '[Dry run] ' : '',
                $days,
                $removed['eve'],
                $removed['voice'],
                $dryRun ? 'would be deleted' : 'deleted'
            ));
        } catch (\Throwable $e) {
            $this->warn('Activity session prune failed: ' . $e->getMessage());
        }
    }
}

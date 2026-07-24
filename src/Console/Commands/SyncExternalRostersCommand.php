<?php

namespace HrManager\Console\Commands;

use HrManager\Services\EveWhoRosterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily background refresh of the EveWho member rosters (Settings → Features,
 * off by default). Does the FULL multi-page pull that a page-load seed can't
 * afford — the interactive path uses a short budget to avoid holding the
 * Members page, which is why a big corp can show only the first 500 until this
 * cron completes it.
 *
 * Scope: only corps that already have a stored roster (i.e. a director opened
 * them at least once, seeding them). The cron never introduces new EveWho
 * traffic on its own — it just keeps the in-use rosters full + current.
 *
 * Force-refreshes each corp (ignoring the per-view freshness cache) with a
 * generous per-corp time budget so every page is fetched. Overlap-guarded with
 * a lock (belt-and-suspenders with the schedule's allow_overlap=false).
 */
class SyncExternalRostersCommand extends Command
{
    protected $signature = 'hr-manager:sync-external-rosters
                            {--force : Run even when the EveWho feature is off}';

    protected $description = 'Refresh the EveWho member rosters for corps that use the back-fill (full multi-page pull)';

    private const LOCK_KEY = 'hr-sync-external-rosters-lock';
    private const LOCK_TTL = 3600;

    public function handle(EveWhoRosterService $eveWho): int
    {
        if (!$this->option('force') && !$eveWho->isEnabled()) {
            $this->info('EveWho roster back-fill is disabled (Settings → Features). Nothing to do.');
            return 0;
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);
        if (!$lock->get()) {
            $this->warn('Another roster sync is already in progress — skipping this cycle.');
            return 0;
        }

        try {
            $corpIds = $eveWho->syncedCorporationIds();
            if (empty($corpIds)) {
                $this->info('No corps have an EveWho roster yet (they seed on first view). Nothing to refresh.');
                return 0;
            }

            $bar = null;
            if ($this->output->isDecorated()) {
                $bar = $this->output->createProgressBar(count($corpIds));
                $bar->setFormat(" %current%/%max% corps  [%bar%]  %percent:3s%%  %elapsed:6s%  %message%");
                $bar->setMessage('starting…');
                $bar->start();
            }

            $totalChars = 0;
            $failed = 0;
            foreach ($corpIds as $corpId) {
                try {
                    // Force + the full background budget = fetch every page.
                    $count = $eveWho->sync($corpId, true, EveWhoRosterService::WALL_BUDGET_FULL);
                    if ($count === null) {
                        $failed++;
                    } else {
                        $totalChars += $count;
                    }
                    if ($bar) {
                        $bar->setMessage(sprintf('corp %d · %s chars', $corpId, $count === null ? 'failed' : (string) $count));
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('[HR Manager] external roster sync failed for corp ' . $corpId . ': ' . $e->getMessage());
                }
                if ($bar) {
                    $bar->advance();
                }
            }

            if ($bar) {
                $bar->finish();
                $this->newLine(2);
            }

            $this->info(sprintf(
                'Refreshed %d corp roster(s) from EveWho: %d characters total%s.',
                count($corpIds),
                $totalChars,
                $failed > 0 ? ", {$failed} failed (kept previous copy)" : ''
            ));

            return 0;
        } finally {
            optional($lock)->release();
        }
    }
}

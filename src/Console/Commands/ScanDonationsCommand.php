<?php

namespace HrManager\Console\Commands;

use HrManager\Services\DonationScanService;
use Illuminate\Console\Command;

/**
 * Looks for direct ISK transfers between corp members and entities the corp
 * rates badly.
 *
 * Reads only what is new since the last pass, so the nightly run is cheap. The
 * exception is the first one after enabling: every character's journal is read
 * from the beginning once, which on a large corp is the only run that takes
 * real time. --limit exists so that can be spread over several passes.
 */
class ScanDonationsCommand extends Command
{
    protected $signature = 'hr-manager:scan-donations
                            {--limit= : stop after this many characters (spreads a first backfill over several runs)}';

    protected $description = 'Flag direct ISK transfers between members and entities your standings rate badly';

    public function handle(DonationScanService $service): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $r = $service->scan($limit);

        // A bare "0 flagged" is ambiguous. Each of these is a different reason
        // for finding nothing, and only one of them is a healthy quiet run.
        if (!$r['available']) {
            $this->warn('Donation flag tables are missing — migration pending. Restart SeAT to apply it, then re-run.');
            return 0;
        }

        if (!$r['enabled']) {
            $this->info('Donation flags are off. Turn them on in Settings → Standings.');
            return 0;
        }

        if (!$r['configured']) {
            $this->warn('No standings are configured, so there is nothing to compare transfers against. Set a source in Settings → Standings.');
            return 0;
        }

        $this->info(sprintf(
            'Scanned %d character(s), %d new donation(s): %d flagged (%d since the member joined), %d skipped.',
            $r['characters'],
            $r['rows'],
            $r['flagged'],
            $r['suspect'],
            $r['skipped']
        ));

        if ($r['suspect'] > 0) {
            $this->warn($r['suspect'] . ' transfer(s) were dated AFTER the member was already in the corp. Review them on the player profiles.');
        }

        return 0;
    }
}

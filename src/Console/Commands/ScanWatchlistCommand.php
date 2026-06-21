<?php

namespace HrManager\Console\Commands;

use HrManager\Services\WatchlistMonitorService;
use Illuminate\Console\Command;

/**
 * Runs the watchlist detection passes:
 *   - corp join     (blacklisted char in a managed corp)
 *   - alliance join (blacklisted char in a corp in a managed alliance)
 *   - external poll (public ESI shows their corp changed)
 *   - intel match   (intel-flagged char currently in a watched corp)
 *
 * Schedules every 15 minutes by default via ScheduleSeeder.
 */
class ScanWatchlistCommand extends Command
{
    protected $signature = 'hr-manager:scan-watchlist';

    protected $description = 'Scan for blacklist matches across corps, alliances, and external corp changes';

    public function handle(WatchlistMonitorService $service): int
    {
        $this->info('HR Manager: scanning watchlist for new matches...');

        $result = $service->scan();

        $this->info(sprintf(
            'Corp-join detections: %d · Alliance-join detections: %d · External-change detections: %d · Intel scope matches: %d',
            $result['corp'],
            $result['alliance'],
            $result['external'],
            $result['intel'] ?? 0
        ));

        return 0;
    }
}

<?php

namespace HrManager\Console\Commands;

use HrManager\Jobs\PollVoiceStates;
use HrManager\Services\ActivityTrackingService;
use Illuminate\Console\Command;

/**
 * Hands this minute's voice slice to the queue and returns. Scheduled every
 * minute; each linked member falls in one minute of the interval, so every
 * member is sampled once per interval.
 */
class PollVoiceCommand extends Command
{
    protected $signature = 'hr-manager:poll-voice
                            {--sync : Poll this minute\'s slice now, in this process, and print the result}';

    protected $description = 'Ask Discord which linked members are in voice (one slice of the interval per minute).';

    public function handle(ActivityTrackingService $tracking): int
    {
        if (!$tracking->voiceEnabled()) {
            if ($this->option('sync')) {
                $this->info('[HR Manager] Discord voice tracking is switched off.');
            }

            return self::SUCCESS;
        }

        if (!$tracking->connectorInstalled()) {
            $tracking->recordSkippedRun('connector_missing');
            if ($this->option('sync')) {
                $this->warn('[HR Manager] SeAT Connector is not installed; there are no linked Discord accounts.');
            }

            return self::SUCCESS;
        }

        $interval = $tracking->interval();
        $slot = ActivityTrackingService::slotFor(now(), $interval);

        if ($this->option('sync')) {
            PollVoiceStates::dispatchSync($slot, $interval);
            $run = $tracking->lastRun() ?? [];
            $this->info(sprintf(
                '[HR Manager] Slot %d of %d: %d requests, %d in voice, %d in hidden channels, %d not in voice, %d errors%s.',
                $slot,
                $interval,
                (int) ($run['requests'] ?? 0),
                (int) ($run['in_voice'] ?? 0),
                (int) ($run['hidden'] ?? 0),
                (int) ($run['not_in_voice'] ?? 0),
                (int) ($run['errors'] ?? 0),
                !empty($run['stopped']) ? ', stopped early: ' . $run['stopped'] : ''
            ));

            return self::SUCCESS;
        }

        PollVoiceStates::dispatch($slot, $interval);

        return self::SUCCESS;
    }
}

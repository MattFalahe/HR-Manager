<?php

namespace HrManager\Jobs;

use HrManager\Services\ActivityTrackingService;
use HrManager\Services\DiscordHttpVoiceTransport;
use HrManager\Services\VoicePoller;
use HrManager\Services\VoiceSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * One minute's slice of the voice poll.
 *
 * Runs on the queue because SeAT's scheduler runs commands one after another:
 * a poll inside it would push back every other plugin's minute. The poller
 * stops itself inside RUN_BUDGET_SECONDS, and a slice that finds the previous
 * one still running is dropped, not queued behind it, so a slow minute costs
 * one sample for that slice's members and never doubles the request rate.
 */
class PollVoiceStates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 120;

    // A failed slice is not retried: the next sample for these members comes
    // one interval later anyway, and a retry would land on another slice's minute.
    public $tries = 1;

    public $backoff = [60];

    public int $slot;
    public int $interval;

    public function __construct(int $slot, int $interval)
    {
        $this->slot = $slot;
        $this->interval = $interval;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('hr-manager:poll-voice'))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(ActivityTrackingService $tracking, VoiceSessionService $sessions): void
    {
        if (!$tracking->voiceEnabled() || !$sessions->isAvailable()) {
            return;
        }

        $config = $tracking->discordConfig();
        if ($config === null) {
            $tracking->recordSkippedRun('not_configured');

            return;
        }

        $members = $tracking->membersForSlot($this->slot, $this->interval);
        $now = now();

        $transport = new DiscordHttpVoiceTransport($config['bot_token']);
        $result = [
            'samples' => [],
            'stats'   => ['members' => 0, 'requests' => 0],
            'stopped' => null,
        ];

        if (!empty($members)) {
            $poller = new VoicePoller(
                $transport,
                null,
                null,
                fn (int $add): int => $tracking->countInvalid($add)
            );
            $result = $poller->poll($config['guild_id'], $members);

            $names = [];
            foreach ($result['samples'] as $sample) {
                if ($sample['state'] === 'in_voice') {
                    $names = $tracking->channelNames($transport, $config['guild_id']);
                    break;
                }
            }

            $sessions->applySamples($result['samples'], $now, $this->interval, $names);
            $tracking->rememberNotInGuild($result['samples']);
        }

        $sessions->closeStale($now, $this->interval);
        $tracking->recordRun($result, $this->slot, $this->interval);

        if ($result['stopped'] === 'unauthorized') {
            Log::warning('[HR Manager] Discord rejected the SeAT Connector bot token for voice tracking; polling stops until it is fixed');
        } elseif ($result['stopped'] === 'invalid_ceiling') {
            Log::warning('[HR Manager] voice polling paused: too many refused requests in the last ten minutes, close to Discord\'s IP ban threshold');
        }
    }
}

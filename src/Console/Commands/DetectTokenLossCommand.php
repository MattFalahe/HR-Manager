<?php

namespace HrManager\Console\Commands;

use HrManager\Services\TokenLossService;
use Illuminate\Console\Command;

/**
 * The Token-loss Watchdog pass. Scans SeAT's refresh_tokens for recently
 * revoked entries (deleted_at set since the last scan), records token_revoked
 * history events and fires the security alert. When security_token_loss_enabled
 * is true it also applies the configured auto-purge policy. Then, every run and
 * independent of that toggle, it cancels any of its own purges whose account has
 * recovered token coverage, and runs the opt-in squad-drop for purges whose
 * grace window has elapsed.
 *
 * Runs every 10 minutes by default via ScheduleSeeder. The detection scan is
 * watermarked via the security_token_loss_last_scan_at setting so repeated runs
 * only see new revocations; the cancel + squad-drop passes re-check current
 * state each run.
 */
class DetectTokenLossCommand extends Command
{
    protected $signature = 'hr-manager:detect-token-loss';

    protected $description = 'Detect SeAT refresh token revocations and apply the security policy when enabled';

    public function handle(TokenLossService $service): int
    {
        $this->info('HR Manager: scanning refresh_tokens for revocations...');

        $result = $service->detect();

        // Reconcile restored tokens FIRST: cancel any of the automation's own
        // purges whose account no longer trips the policy (member re-linked), so
        // a same-tick restore beats the squad drop below.
        $cancelled = $service->cancelResolvedPurges();

        // Opt-in access reduction: drop removable squads for due token-loss
        // purges (grace window elapsed, or director-immediate).
        $squadsDropped = $service->processSquadDrops();

        $this->info(sprintf(
            'Detected: %d, history rows inserted: %d, purges scheduled: %d, purges cancelled (token restored): %d, squad-drops processed: %d. Last scan watermark: %s',
            $result['detected'],
            $result['history_inserted'],
            $result['purges_scheduled'],
            $cancelled,
            $squadsDropped,
            $result['last_scan']->toIso8601String()
        ));

        return 0;
    }
}

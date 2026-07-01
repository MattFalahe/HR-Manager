<?php

namespace HrManager\Console\Commands;

use HrManager\Services\TokenLossService;
use Illuminate\Console\Command;

/**
 * Scans SeAT's refresh_tokens for recently revoked entries
 * (deleted_at set since the last scan) and records token_revoked
 * history events. When security_token_loss_enabled is true in the
 * settings, also schedules a T+N hour purge as a security guard.
 *
 * Runs every 15 minutes by default via ScheduleSeeder. Scan is
 * watermarked via the security_token_loss_last_scan_at setting so
 * repeated runs only see new revocations.
 */
class DetectTokenLossCommand extends Command
{
    protected $signature = 'hr-manager:detect-token-loss';

    protected $description = 'Detect SeAT refresh token revocations and apply the security policy when enabled';

    public function handle(TokenLossService $service): int
    {
        $this->info('HR Manager: scanning refresh_tokens for revocations...');

        $result = $service->detect();

        $this->info(sprintf(
            'Detected: %d, history rows inserted: %d, purges scheduled: %d. Last scan watermark: %s',
            $result['detected'],
            $result['history_inserted'],
            $result['purges_scheduled'],
            $result['last_scan']->toIso8601String()
        ));

        return 0;
    }
}

<?php

namespace HrManager\Console\Commands;

use HrManager\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Periodic token-coverage digest. For every corp HR can see (corps with at
 * least one registered character), sends the opt-in token + scope coverage
 * summary to any webhook with notify_token_coverage enabled. Weekly by default
 * via ScheduleSeeder. No-op for a corp with no subscribing webhook.
 */
class TokenCoverageDigestCommand extends Command
{
    protected $signature = 'hr-manager:token-coverage-digest
                            {--corporation_id= : Only digest this corporation}';

    protected $description = 'Send the opt-in periodic token + scope coverage digest per corporation';

    public function handle(NotificationService $notifications): int
    {
        $corpIds = $this->option('corporation_id')
            ? [(int) $this->option('corporation_id')]
            : DB::table('refresh_tokens')
                ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                ->whereNull('refresh_tokens.deleted_at')
                ->distinct()
                ->pluck('character_affiliations.corporation_id')
                ->filter()
                ->all();

        if (empty($corpIds)) {
            $this->warn('No corporations to digest.');

            return 0;
        }

        $this->info('HR Manager: dispatching token-coverage digest...');

        foreach ($corpIds as $corpId) {
            try {
                $notifications->notifyTokenCoverage((int) $corpId);
            } catch (\Throwable $e) {
                $this->error("corp {$corpId}: " . $e->getMessage());
            }
        }

        $this->info('Done. Token-coverage digest dispatched for ' . count($corpIds) . ' corporation(s).');

        return 0;
    }
}

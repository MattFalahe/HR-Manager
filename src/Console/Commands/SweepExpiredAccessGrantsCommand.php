<?php

namespace HrManager\Console\Commands;

use HrManager\Services\ApplicantAccessService;
use HrManager\Services\ApplicantConnectorAccessService;
use Illuminate\Console\Command;

/**
 * Daily backstop sweep for temporary access grants that have passed
 * their expires_at without being explicitly revoked by a lifecycle
 * hook. Covers BOTH grant families:
 *   - recruiter -> applicant-data grants (ApplicantAccessService)
 *   - applicant -> Connector-link grants (ApplicantConnectorAccessService)
 * Scheduled via the ScheduleSeeder. Idempotent — running twice
 * back-to-back the second pass finds nothing to do.
 */
class SweepExpiredAccessGrantsCommand extends Command
{
    protected $signature = 'hr-manager:sweep-access-grants';
    protected $description = 'Revoke any expired recruiter / applicant access grants that lifecycle hooks missed.';

    public function handle(ApplicantAccessService $recruiter, ApplicantConnectorAccessService $connector): int
    {
        $recruiterCount = $recruiter->sweepExpired();
        $connectorCount = $connector->sweepExpired();
        $total = $recruiterCount + $connectorCount;

        if ($total > 0) {
            $this->info("[HR Manager] Revoked {$recruiterCount} expired recruiter grant(s) and {$connectorCount} expired applicant Connector grant(s).");
        } else {
            $this->info('[HR Manager] No expired access grants to revoke.');
        }
        return self::SUCCESS;
    }
}

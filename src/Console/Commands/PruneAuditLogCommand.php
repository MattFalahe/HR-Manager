<?php

namespace HrManager\Console\Commands;

use HrManager\Services\AuditService;
use Illuminate\Console\Command;

/**
 * Daily retention prune for the activity/audit log. Deletes rows past the
 * retention window (AuditService::RETENTION_DAYS, 180 days). Runs regardless of
 * whether the feature is currently enabled, so a log left switched off still
 * drains rather than lingering forever. Cheap when there's nothing to remove.
 */
class PruneAuditLogCommand extends Command
{
    protected $signature = 'hr-manager:prune-audit-log {--days= : Override the retention window in days}';
    protected $description = 'Delete HR activity-log entries older than the retention window (default 180 days).';

    public function handle(AuditService $audit): int
    {
        $days    = $this->option('days') !== null ? (int) $this->option('days') : null;
        $removed = $audit->prune($days);

        $this->info($removed > 0
            ? "[HR Manager] Pruned {$removed} activity-log entr(y/ies) past retention."
            : '[HR Manager] No activity-log entries past retention.');

        return self::SUCCESS;
    }
}

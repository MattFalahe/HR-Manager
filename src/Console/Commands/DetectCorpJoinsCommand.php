<?php

namespace HrManager\Console\Commands;

use HrManager\Models\Application;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Detect when accepted applicants actually join the corporation.
 *
 * "Accepted" doesn't mean "joined". Plenty of applicants get told yes
 * and then ghost — never apply in-game, never click the Discord invite,
 * never become a member. This command closes the funnel by watching
 * SeAT's existing character_corporation_histories table for the join
 * event and flipping joined_corp_at + joined_corp_id on the matching
 * application row.
 *
 * Read-only consumer of SeAT's synced histories — no ESI calls.
 * Publishes hr.application.joined_corp to EventBus so downstream
 * subscribers (SeAT Broadcast, MC) can announce the milestone.
 *
 * Bounded to apps accepted in the last 90 days so we don't keep
 * scanning ancient rows forever.
 */
class DetectCorpJoinsCommand extends Command
{
    protected $signature = 'hr-manager:detect-corp-joins
                            {--days=90 : Only consider applications accepted within this window}
                            {--dry-run : Show what would be updated without writing}';

    protected $description = 'Detect when accepted applicants actually joined their target corporation';

    public function handle(): int
    {
        $days   = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        if (!Schema::hasTable('character_corporation_histories')) {
            $this->warn('character_corporation_histories table missing — nothing to do.');
            return 0;
        }

        // Don't even query when the new columns aren't on the
        // applications table yet (fresh-install race window before the
        // 2026_06_01_000005 migration runs).
        if (!Schema::hasColumn('hr_manager_applications', 'joined_corp_at')) {
            $this->warn('joined_corp_at column missing — migration pending. Skipping.');
            return 0;
        }

        $candidates = Application::where('status', 'accepted')
            ->whereNull('joined_corp_at')
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', now()->subDays($days))
            ->get(['id', 'character_id', 'corporation_id', 'decided_at']);

        if ($candidates->isEmpty()) {
            $this->info('No candidates within the window. Done.');
            return 0;
        }

        $this->info("Scanning {$candidates->count()} accepted application(s) for join events...");

        $updated = 0;
        foreach ($candidates as $app) {
            $row = DB::table('character_corporation_histories')
                ->where('character_id', $app->character_id)
                ->where('corporation_id', $app->corporation_id)
                ->where('start_date', '>=', $app->decided_at)
                ->orderBy('start_date')
                ->first(['start_date']);

            if (!$row) {
                continue;
            }

            $startDate = $row->start_date;

            if ($dryRun) {
                $this->line("  [dry] app #{$app->id} char #{$app->character_id} joined corp {$app->corporation_id} at {$startDate}");
                $updated++;
                continue;
            }

            $app->update([
                'joined_corp_at' => $startDate,
                'joined_corp_id' => $app->corporation_id,
            ]);

            $this->publishJoinedEvent($app, $startDate);
            $this->revokeConnectorAccess($app);

            $updated++;
        }

        $verb = $dryRun ? 'would update' : 'updated';
        $this->info("Done. {$verb} {$updated} application(s).");

        return 0;
    }

    /**
     * Publish hr.application.joined_corp to MC EventBus when available
     * so SeAT Broadcast etc. can announce the milestone. Silent no-op
     * when MC isn't installed.
     */
    private function publishJoinedEvent(Application $app, string $startDate): void
    {
        // Topics::publish is the canonical publish path; it resolves the
        // publisher (hr-manager) + idempotency key from the registry and
        // no-ops cleanly when MC is absent.
        if (!class_exists('\\ManagerCore\\Topics')) {
            return;
        }

        try {
            \ManagerCore\Topics::publish(
                'hr.application.joined_corp',
                [
                    'source_plugin'   => 'hr-manager',
                    'schema_version'  => 1,
                    'event_id'        => 'hr-evt-' . Str::uuid()->toString(),
                    'application_id'  => $app->id,
                    'character_id'    => $app->character_id,
                    'corporation_id'  => $app->corporation_id,
                    'decided_at'      => optional($app->decided_at)->toIso8601String(),
                    'joined_corp_at'  => $startDate,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] hr.application.joined_corp publish failed: ' . $e->getMessage());
        }
    }

    /**
     * Pull the applicant's temporary Connector-link grant now that they've
     * actually joined the corp — the "keep it until joining corporation"
     * end of the lifecycle. Best-effort; the daily sweep is the backstop.
     * No-ops when the feature is off or no grant exists.
     */
    private function revokeConnectorAccess(Application $app): void
    {
        try {
            app(\HrManager\Services\ApplicantConnectorAccessService::class)
                ->revokeForApplication((int) $app->id, 'joined_corp');
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] connector access revoke on corp-join failed: ' . $e->getMessage());
        }
    }
}

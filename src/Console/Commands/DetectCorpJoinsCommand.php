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
 * never become a member. This command closes the funnel by detecting the
 * join from SeAT's synced data and flipping joined_corp_at + joined_corp_id
 * on the matching application row.
 *
 * Membership is confirmed from the freshest source (character_affiliations,
 * falling back to the latest corp-history record). The join DATE is the most
 * recent corp-history record for the target corp — the real stint start, which
 * is often BEFORE the decision (applicants are frequently already in the corp);
 * detection time is used only until that history row syncs, and an approximate
 * date stamped that way is corrected to the real one on a later run.
 *
 * Read-only consumer of SeAT's synced data; no ESI calls.
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

        // Already-joined apps are included too: a date stamped via the
        // affiliation fallback (detection time, approximate) is corrected to the
        // real corp-history join date once that record is available.
        $candidates = Application::where('status', 'accepted')
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', now()->subDays($days))
            ->get(['id', 'character_id', 'corporation_id', 'decided_at', 'joined_corp_at']);

        if ($candidates->isEmpty()) {
            $this->info('No candidates within the window. Done.');
            return 0;
        }

        $this->info("Scanning {$candidates->count()} accepted application(s) for join events...");

        $updated = 0;
        foreach ($candidates as $app) {
            $targetCorp = (int) $app->corporation_id;
            $wasJoined  = $app->joined_corp_at !== null;

            // Are they in the TARGET corp right now? character_affiliations is the
            // freshest signal; fall back to the latest corp-history record when no
            // affiliation row exists.
            $affCorp = Schema::hasTable('character_affiliations')
                ? DB::table('character_affiliations')->where('character_id', $app->character_id)->value('corporation_id')
                : null;

            if ($affCorp !== null) {
                $inTargetNow = ((int) $affCorp === $targetCorp);
            } else {
                $latestCorp = DB::table('character_corporation_histories')
                    ->where('character_id', $app->character_id)
                    ->orderByDesc('start_date')
                    ->value('corporation_id');
                $inTargetNow = ($latestCorp !== null && (int) $latestCorp === $targetCorp);
            }

            if (!$inTargetNow) {
                continue; // not a member of the target corp — leave as NOT JOINED YET
            }

            // Real join date = the most recent corp-history record for the TARGET
            // corp. An applicant is frequently ALREADY in the corp by the time the
            // application is processed (joined before / during recruitment), so do
            // NOT require the join to be after the decision date — read the actual
            // stint start. Falls back to detection time only when the history row
            // has not synced yet.
            $joinDate = DB::table('character_corporation_histories')
                ->where('character_id', $app->character_id)
                ->where('corporation_id', $targetCorp)
                ->orderByDesc('start_date')
                ->value('start_date')
                ?? now()->toDateTimeString();

            // Nothing to do when an already-joined app already carries this date.
            if ($wasJoined && \Carbon\Carbon::parse($app->joined_corp_at)->equalTo(\Carbon\Carbon::parse($joinDate))) {
                continue;
            }

            if ($dryRun) {
                $label = $wasJoined ? 'correct date ->' : 'join ->';
                $this->line("  [dry] app #{$app->id} char #{$app->character_id} {$label} corp {$targetCorp} at {$joinDate}");
                $updated++;
                continue;
            }

            $app->update([
                'joined_corp_at' => $joinDate,
                'joined_corp_id' => $targetCorp,
            ]);

            // Announce + pull Connector access only on the FIRST transition to
            // joined, never on a date correction of an already-joined app.
            if (!$wasJoined) {
                $this->publishJoinedEvent($app, $joinDate);
                $this->revokeConnectorAccess($app);
            }

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

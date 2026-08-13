<?php

namespace HrManager\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    /**
     * Override parent run() so changed cron expressions get reapplied.
     * AbstractScheduleSeeder uses firstOrCreate which silently ignores
     * existing rows, so a changed schedule would never take effect; this
     * version uses updateOrInsert so the new expression is actually written.
     */
    public function run(): void
    {
        foreach ($this->getSchedules() as $job) {
            DB::table('schedules')->updateOrInsert(
                ['command' => $job['command']],
                $job
            );
        }

        $deprecated = $this->getDeprecatedSchedules();
        if (!empty($deprecated)) {
            DB::table('schedules')->whereIn('command', $deprecated)->delete();
        }
    }

    public function getSchedules(): array
    {
        return [
            // Cache member assessment data - every 2 hours
            [
                'command'           => 'hr-manager:cache-assessments',
                'expression'        => '45 */2 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Cleanup old soft-deleted applications - daily at 03:00
            [
                'command'           => 'hr-manager:cleanup',
                'expression'        => '0 3 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Classify players - nightly at 02:00. Cheap pass over tracked
            // users; detects transitions + publishes hr.player.flagged_* events
            [
                'command'           => 'hr-manager:classify-players',
                'expression'        => '0 2 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Dispatch purge reminders - twice daily (every 12h). Dedup via
            // unique (player_status_id, milestone) makes replays harmless
            [
                'command'           => 'hr-manager:dispatch-purge-reminders',
                'expression'        => '0 */12 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Detect corp joins - every 30 minutes. Cheap scan over
            // accepted applications without joined_corp_at, matches
            // against SeAT's existing character_corporation_histories.
            // 90-day window so we don't keep checking ancient rows.
            [
                'command'           => 'hr-manager:detect-corp-joins',
                'expression'        => '*/30 * * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Detect corp membership changes - every 30 minutes. Diffs SeAT's
            // live roster against HR's snapshot to notify on joins (classified:
            // alt of a current member / valid application / no application) and
            // leaves. Forward-only: a corp's first scan seeds silently, so
            // existing members are never announced.
            [
                'command'           => 'hr-manager:detect-membership-changes',
                'expression'        => '10,40 * * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Warm player-profile bundles - every 30 minutes. Opt-in (Settings →
            // Features); the command no-ops when disabled. Pre-computes the
            // heavy per-alt profile panels so the view loads instantly. Skips
            // unchanged accounts via a change-fingerprint and self-limits with a
            // lock + time budget + cursor, so it stays cheap even on a huge
            // roster (allow_overlap=false as a second guard).
            [
                'command'           => 'hr-manager:warm-player-profiles',
                'expression'        => '20,50 * * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Refresh EveWho member rosters - daily at 04:30. Opt-in (Settings →
            // Features); the command no-ops when disabled. Does the full
            // multi-page pull the page-load seed can't afford, and only for
            // corps already in use (seeded by a view). Fills the gap a first-
            // view seed left and keeps rosters current.
            [
                'command'           => 'hr-manager:sync-external-rosters',
                'expression'        => '30 4 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Detect token loss - every 10 minutes. Watermarked scan
            // for SeAT refresh_tokens deleted_at > last_scan. Higher
            // cadence than other crons because token revocation is a
            // security-grade signal — the sooner we catch it, the
            // sooner the security policy fires (T+72h purge). Cron is
            // cheap when the watermark is current:
            // empty resultset returns in ms.
            [
                'command'           => 'hr-manager:detect-token-loss',
                'expression'        => '*/10 * * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Post queued onboarding welcomes whose delay has elapsed. Every 5
            // minutes so a welcome fires close to its send_after mark (the
            // Connector-role grace window). No-op when nothing is due.
            [
                'command'           => 'hr-manager:send-onboarding-welcomes',
                'expression'        => '*/5 * * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Settle suspected alt links - daily at 05:00. Claims are about
            // characters HR usually can't see yet, so most passes decide
            // nothing; the one that matters runs after a suspect finally
            // registers and either confirms the link or refutes it. Cheap:
            // two indexed lookups per open claim, and nothing at all when
            // there are none.
            [
                'command'           => 'hr-manager:reconcile-suspected-alts',
                'expression'        => '0 5 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Flag member ISK transfers to badly-rated entities - daily at
            // 05:40. Incremental: reads only journal rows newer than the last
            // pass, so a nightly run is a handful of indexed queries per
            // character with a wallet token. The exception is the first run
            // after enabling, which reads each journal from the beginning once
            // (--limit can spread that over several nights). Does nothing at
            // all while the feature is off or no standings are configured.
            [
                'command'           => 'hr-manager:scan-donations',
                'expression'        => '40 5 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Scan watchlist for new matches - every 15 minutes. Three
            // detection passes: corp-join (blacklist char in a managed
            // corp), alliance-join (blacklist char in a corp in our
            // alliance), and external-corp-change (ESI poll for opt-in
            // entries). Dedup via the hr_manager_watchlist_detections
            // unique constraint so cron replays don't spam.
            [
                'command'           => 'hr-manager:scan-watchlist',
                'expression'        => '*/15 * * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Backstop sweep for recruiter access grants whose expires_at
            // has passed without a lifecycle hook revoking them. Daily
            // at 04:00 is plenty — primary revoke fires from the
            // application-status / handler-leave hooks; this is the
            // "did anything slip through" safety net. Cheap when there's
            // nothing to do.
            [
                'command'           => 'hr-manager:sweep-access-grants',
                'expression'        => '0 4 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Token-coverage digest - weekly (Monday 09:00). Opt-in summary of
            // each corp's token + scope health to any webhook with
            // notify_token_coverage on. No-op when no webhook subscribes, so
            // the weekly tick is cheap on installs that never enabled it.
            [
                'command'           => 'hr-manager:token-coverage-digest',
                'expression'        => '0 9 * * 1',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],

            // Prune the activity/audit log past its 180-day retention - daily
            // at 04:30. Runs whether or not the feature is enabled so a log
            // left off still ages out. Cheap when there's nothing to remove.
            [
                'command'           => 'hr-manager:prune-audit-log',
                'expression'        => '45 4 * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
                'ping_before'       => null,
                'ping_after'        => null,
            ],
        ];
    }

    public function getDeprecatedSchedules(): array
    {
        return [];
    }
}

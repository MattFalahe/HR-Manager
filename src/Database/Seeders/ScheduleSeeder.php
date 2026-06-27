<?php

namespace HrManager\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    /**
     * Override parent run() so changed cron expressions get reapplied.
     * AbstractScheduleSeeder uses firstOrCreate which silently ignores
     * existing rows; this version uses updateOrInsert per the SeAT
     * pitfall workaround in feedback_seat_schedule_corrections.
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
                'expression'        => '0 */2 * * *',
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
                'expression'        => '*/30 * * * *',
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
        ];
    }

    public function getDeprecatedSchedules(): array
    {
        return [];
    }
}

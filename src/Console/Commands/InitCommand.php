<?php

namespace HrManager\Console\Commands;

use HrManager\Models\Application;
use HrManager\Models\MemberAssessment;
use HrManager\Models\WebhookConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guided first-time setup for a fresh HR Manager install.
 *
 * Two phases:
 *   1. Readiness check — verifies the prerequisites and points the operator at
 *      the setup steps (required vs optional), so they know what to configure
 *      before assessing.
 *   2. Sequenced load — runs the data-populating commands in dependency order
 *      so Corp Health, the member / player profiles and the buyback panels are
 *      populated immediately instead of waiting for the nightly crons.
 *
 * Deliberately runs ONLY the notification-free / dashboard-populating commands
 * (backfill-buyback, cache-assessments, classify-players, detect-corp-joins).
 * The notification-heavy monitoring passes (detect-token-loss, scan-watchlist)
 * are left to the schedule so a first run never floods webhooks with the
 * current backlog of lapsed tokens / watchlist hits.
 *
 * Safe + idempotent: every sub-command it calls is itself idempotent, so this
 * can be re-run at any time.
 */
class InitCommand extends Command
{
    protected $signature = 'hr-manager:init
                            {--check : Only run the readiness check; load nothing}
                            {--force : Skip the confirmation prompt and load straight away}';

    protected $description = 'Guided first-time setup: check prerequisites, then load + assess all HR data in sequence';

    public function handle(): int
    {
        $this->info('=== HR Manager — Initialize ===');
        $this->newLine();
        $this->line('Step 1 of 2 — readiness check');
        $this->newLine();

        $state = $this->readiness();

        if (!$state['hard_ok']) {
            $this->newLine();
            $this->error('Required prerequisites are missing (see ✗ above). Fix those, then re-run hr-manager:init.');
            return 1;
        }

        if ($this->option('check')) {
            $this->newLine();
            $this->info('Readiness check only (--check). Re-run without --check to load data.');
            return 0;
        }

        $this->newLine();
        $this->line('Step 2 of 2 — load + assess');
        if ($state['webhooks'] > 0) {
            $this->newLine();
            $this->warn("Heads up: {$state['webhooks']} webhook(s) are already configured. Classifying the roster can notify for CURRENT inactive directors. On a brand-new install, configure webhooks AFTER this load to avoid first-run noise.");
        }
        $this->newLine();

        if (!$this->option('force') && !$this->confirm('Run the initial data load + assessment now?', true)) {
            $this->line('Aborted — nothing was loaded. (Run with --force to skip this prompt.)');
            return 0;
        }

        // Dependency order: seed raw data, build the assessment cache, classify
        // off that cache, then resolve application outcomes.
        $steps = [];
        if (Schema::hasTable('buyback_contracts')) {
            $steps[] = ['hr-manager:backfill-buyback', 'Seed historical buyback activity'];
        }
        $steps[] = ['hr-manager:cache-assessments', 'Build the cross-plugin assessment cache'];
        $steps[] = ['hr-manager:classify-players', 'Classify Corp Health across the roster'];
        $steps[] = ['hr-manager:detect-corp-joins', 'Mark accepted applicants who have joined'];

        $failed = [];
        foreach ($steps as [$cmd, $label]) {
            $this->newLine();
            $this->line("→ {$label}  (<fg=cyan>{$cmd}</>)");
            try {
                $this->call($cmd);
            } catch (\Throwable $e) {
                $this->error("  {$cmd} failed: " . $e->getMessage());
                $failed[] = $cmd;
            }
        }

        $this->newLine();
        if (!empty($failed)) {
            $this->warn('Initialization finished with errors in: ' . implode(', ', $failed) . '. Re-run those individually once resolved.');
        } else {
            $this->info('=== Initialization complete ===');
        }

        $bbNote = Schema::hasTable('buyback_contracts') ? ', buyback panels' : '';
        $this->line("Dashboards (Corp Health, member / player profiles{$bbNote}) are now populated.");
        $this->newLine();
        $this->line('From here the schedule keeps everything fresh automatically:');
        $this->line('  • token loss every 10 min · watchlist every 15 min · corp-joins every 30 min');
        $this->line('  • assessment cache every 2h · classification nightly · cleanup nightly');
        $this->newLine();
        $bbStep = Schema::hasTable('buyback_contracts') ? ', Buyback Contribution' : '';
        $this->line("Recommended setup pass: Settings → Features, SSO & Scopes, Recruiter Access{$bbStep}, then configure Webhooks.");

        return 0;
    }

    /**
     * Print the readiness checklist and return the flags the load phase needs.
     *
     * @return array{hard_ok:bool,webhooks:int}
     */
    private function readiness(): array
    {
        $hardOk = true;

        // 1. Migrations applied (REQUIRED).
        $appTable = (new Application)->getTable();
        if (Schema::hasTable($appTable) && Schema::hasTable((new MemberAssessment)->getTable())) {
            $this->line('  <fg=green>✓</> HR tables present (migrations applied)');
        } else {
            $this->line('  <fg=red>✗</> HR tables missing — migrations have not run');
            $this->line('      → Restart your SeAT stack so HR migrations apply, then re-run this command.');
            $hardOk = false;
        }

        // 2. SeAT has synced corp/character data to assess (REQUIRED for the
        //    assessment side; a recruitment-only install can still proceed).
        if ($this->hasSyncedSeatData()) {
            $this->line('  <fg=green>✓</> SeAT has synced corporation / character data to assess');
        } else {
            $this->line('  <fg=yellow>!</> No synced corp / character data found yet (assessment will be empty)');
            $this->line('      → HR reads SeAT\'s synced data. Add a corp director token in SeAT and let the ESI sync run first.');
        }

        // 3. Manager Core + suite plugins (OPTIONAL — enables cross-plugin data).
        if (class_exists('ManagerCore\\Services\\PluginBridge')) {
            $detected = $this->detectedSuitePlugins();
            $this->line('  <fg=green>✓</> Manager Core present' . (empty($detected)
                ? ' (no suite plugins detected yet)'
                : ' — ' . implode(', ', $detected)));
        } else {
            $this->line('  <fg=yellow>!</> Manager Core not installed — standalone mode (wallet / mining / blueprint / structure / buyback panels stay hidden)');
        }

        // 4. Recruitment SSO scope profile (OPTIONAL — recruitment funnel).
        try {
            $profile = app(\HrManager\Services\RecruitmentSsoService::class)->selectedProfileName();
            if (trim((string) $profile) !== '') {
                $this->line('  <fg=green>✓</> Recruitment SSO profile selected: ' . $profile);
            } else {
                $this->line('  <fg=yellow>!</> No recruitment SSO profile chosen (Settings → SSO & Scopes) — recruitment uses SeAT default scopes');
            }
        } catch (\Throwable $e) {
            // service unavailable — skip silently
        }

        // 5. Buyback Manager (OPTIONAL).
        if (Schema::hasTable('buyback_contracts')) {
            $this->line('  <fg=green>✓</> Buyback Manager detected — review Settings → Buyback Contribution after the load');
        }

        // 6. Webhooks (INFO + first-run-noise note carried into the load phase).
        $webhooks = 0;
        try {
            if (Schema::hasTable((new WebhookConfiguration)->getTable())) {
                $webhooks = WebhookConfiguration::count();
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $this->line('  <fg=cyan>i</> Webhooks configured: ' . $webhooks
            . ($webhooks === 0 ? ' (safe to load without notifications)' : ' (see the note before the load)'));

        return ['hard_ok' => $hardOk, 'webhooks' => $webhooks];
    }

    private function hasSyncedSeatData(): bool
    {
        foreach (['corporation_member_trackings', 'character_infos'] as $table) {
            try {
                if (Schema::hasTable($table) && DB::table($table)->limit(1)->exists()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // ignore and try the next
            }
        }
        return false;
    }

    /** @return array<string> display names of detected suite plugins */
    private function detectedSuitePlugins(): array
    {
        $map = [
            'corp-wallet-manager' => 'Corp Wallet Manager',
            'mining-manager'      => 'Mining Manager',
            'blueprint-manager'   => 'Blueprint Manager',
            'structure-manager'   => 'Structure Manager',
            'buyback-manager'     => 'Buyback Manager',
            'seat-discord-pings'  => 'SeAT Broadcast',
        ];

        $detected = [];
        try {
            $bridge = app('ManagerCore\\Services\\PluginBridge');
            foreach ($map as $slug => $label) {
                if (method_exists($bridge, 'hasPlugin') && $bridge->hasPlugin($slug)) {
                    $detected[] = $label;
                }
            }
        } catch (\Throwable $e) {
            // bridge not resolvable — none detected
        }

        return $detected;
    }
}

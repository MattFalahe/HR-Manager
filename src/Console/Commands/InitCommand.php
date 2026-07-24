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
                            {--force : Skip the confirmation prompt and load straight away}
                            {--corporation= : Re-init just one corporation (name, ticker, or numeric ID) — re-applies tier mappings + assessment for that corp only}';

    protected $description = 'Guided first-time setup: check prerequisites, then load + assess all HR data in sequence';

    public function handle(): int
    {
        $this->info('=== HR Manager — Initialize ===');

        // Optional single-corp target. The common reason to re-init is "I added
        // tier mappings after the first run — apply them to this corp now"; this
        // scopes the per-corp steps to it instead of reprocessing the install.
        $corp = null;
        if ($this->option('corporation') !== null) {
            $corp = $this->resolveCorporation((string) $this->option('corporation'));
            if (!$corp) {
                $this->error('Could not resolve --corporation="' . $this->option('corporation') . '" to a single corporation. Try the exact name, ticker, or numeric ID.');
                return 1;
            }
            $label = ($corp->name ?? ('#' . $corp->corporation_id)) . ($corp->ticker ? " [{$corp->ticker}]" : '');
            $this->line('Targeting a single corporation: <fg=cyan>' . $label . '</> (' . $corp->corporation_id . ')');
        }

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

        $prompt = $corp
            ? 'Re-run the assessment + classification for this corporation now?'
            : 'Run the initial data load + assessment now?';
        if (!$this->option('force') && !$this->confirm($prompt, true)) {
            $this->line('Aborted — nothing was loaded. (Run with --force to skip this prompt.)');
            return 0;
        }

        // Dependency order: seed raw data, build the assessment cache, classify
        // off that cache, then resolve application outcomes. The optional third
        // element is command options.
        //
        // Single-corp mode (--corporation) runs ONLY the steps that meaningfully
        // scope to one corp — cache the assessment, classify (re-applying tier
        // mappings), and pre-warm — passing the corp filter through. The
        // install-wide baseline steps (buyback backfill, corp-join + membership
        // seeding) are skipped: they're one-time / global and re-running them
        // per-corp doesn't apply.
        $corpId  = $corp?->corporation_id;
        $cidOpt  = $corpId ? ['--corporation_id' => (string) $corpId] : [];

        $steps = [];
        if (!$corp && $this->buybackInstalled()) {
            $steps[] = ['hr-manager:backfill-buyback', 'Seed historical buyback activity'];
        }
        $steps[] = ['hr-manager:cache-assessments', 'Build the cross-plugin assessment cache', $cidOpt];
        // Baseline the classifier quietly: the first pass over a whole roster
        // would otherwise fire a transition/flag notification for every
        // newly-inactive member. Nightly runs notify normally thereafter.
        $steps[] = ['hr-manager:classify-players', 'Classify Corp Health' . ($corp ? ' for this corp' : ' across the roster'), array_merge(['--quiet-notify' => true], $cidOpt)];
        if (!$corp) {
            $steps[] = ['hr-manager:detect-corp-joins', 'Mark accepted applicants who have joined'];
            $steps[] = ['hr-manager:detect-membership-changes', 'Seed the corp roster baseline (silent first run)'];
        }
        // Pre-warm player profiles for instant loads — only when the operator
        // has turned it on (Settings → Features). Non-interactive + budgeted (no
        // --all), so it warms a chunk now without prompting or running
        // unbounded, and the 30-min scheduler completes the rest. Self-skips
        // dormant / off-corp accounts, so a first run stays reasonable.
        if ($this->prewarmEnabled()) {
            $warmOpt = $corp ? ['--corporation' => (string) $corpId, '--no-interaction' => true] : ['--no-interaction' => true];
            $steps[] = ['hr-manager:warm-player-profiles', 'Pre-warm player profiles for instant loads', $warmOpt];
        }

        $failed = [];
        $total  = count($steps);
        $loadStart = microtime(true);
        foreach ($steps as $i => $step) {
            [$cmd, $label] = $step;
            $opts = $step[2] ?? [];
            $n = $i + 1;
            $this->newLine();
            $this->line("→ [{$n}/{$total}] {$label}  (<fg=cyan>{$cmd}</>)");
            $stepStart = microtime(true);
            try {
                $this->call($cmd, $opts);
                $this->line('  <fg=green>✓ done in ' . $this->fmtSeconds(microtime(true) - $stepStart) . '</>');
            } catch (\Throwable $e) {
                $this->error("  {$cmd} failed: " . $e->getMessage());
                $failed[] = $cmd;
            }
        }
        $elapsed = $this->fmtSeconds(microtime(true) - $loadStart);

        $this->newLine();
        if (!empty($failed)) {
            $this->warn('Initialization finished in ' . $elapsed . ' with errors in: ' . implode(', ', $failed) . '. Re-run those individually once resolved.');
        } else {
            $this->info('=== Initialization complete in ' . $elapsed . ' ===');
        }

        $bbNote = $this->buybackInstalled() ? ', buyback panels' : '';
        $this->line("Dashboards (Corp Health, member / player profiles{$bbNote}) are now populated.");
        $this->newLine();
        $this->line('From here the schedule keeps everything fresh automatically:');
        $this->line('  • token loss every 10 min · watchlist every 15 min · corp-joins every 30 min');
        $this->line('  • assessment cache every 2h · classification nightly · cleanup nightly');
        if ($this->prewarmEnabled()) {
            $this->line('  • profile pre-warm every 30 min (profiles were warmed above; they load instantly)');
        }
        $this->newLine();
        if (!$this->prewarmEnabled()) {
            $this->line('Tip: turn on "Pre-warm player profiles" (Settings → Features) for instant profile loads, then re-run this command (or let the scheduler warm them).');
            $this->newLine();
        }
        $bbStep = $this->buybackInstalled() ? ', Buyback Contribution' : '';
        $this->line("Recommended setup pass: Settings → Features, SSO & Scopes, Recruiter Access{$bbStep}, then configure Webhooks.");

        return 0;
    }

    /**
     * Is Buyback Manager actually installed? Detect by composer package, NOT by
     * the buyback_contracts table — that table lingers after BB is uninstalled
     * (Laravel never drops it), which made init falsely report "detected" and
     * try to backfill a stale, orphaned table.
     */
    private function buybackInstalled(): bool
    {
        return class_exists('\\Composer\\InstalledVersions')
            && \Composer\InstalledVersions::isInstalled('mattfalahe/buyback-manager');
    }

    /** Has the operator opted into background profile pre-warm (Settings → Features)? */
    private function prewarmEnabled(): bool
    {
        try {
            return app(\HrManager\Services\PlayerProfileWarmer::class)->isPrewarmEnabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve a --corporation value (numeric ID, exact name/ticker, else a
     * fuzzy LIKE) to a single corporation row, or null when it's ambiguous /
     * unknown. Mirrors WarmPlayerProfilesCommand so the two behave identically.
     */
    private function resolveCorporation(string $input): ?object
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (ctype_digit($input)) {
            $id = (int) $input;
            $row = Schema::hasTable('corporation_infos')
                ? DB::table('corporation_infos')->where('corporation_id', $id)->first(['corporation_id', 'name', 'ticker'])
                : null;
            return $row ?: (object) ['corporation_id' => $id, 'name' => null, 'ticker' => null];
        }

        if (!Schema::hasTable('corporation_infos')) {
            return null;
        }

        $lower = mb_strtolower($input);
        $exact = DB::table('corporation_infos')
            ->whereRaw('LOWER(name) = ?', [$lower])
            ->orWhereRaw('LOWER(ticker) = ?', [$lower])
            ->get(['corporation_id', 'name', 'ticker']);
        if ($exact->count() === 1) {
            return $exact->first();
        }
        if ($exact->count() > 1) {
            $this->listAmbiguous($exact);
            return null;
        }

        $like = DB::table('corporation_infos')
            ->where('name', 'like', "%{$input}%")
            ->orWhere('ticker', 'like', "%{$input}%")
            ->limit(11)
            ->get(['corporation_id', 'name', 'ticker']);
        if ($like->count() === 1) {
            return $like->first();
        }
        if ($like->count() > 1) {
            $this->listAmbiguous($like);
        }
        return null;
    }

    private function listAmbiguous($rows): void
    {
        $this->warn('That matched more than one corporation — narrow it down (use the numeric ID to be exact):');
        foreach ($rows->take(10) as $r) {
            $this->line('  ' . $r->corporation_id . '  ' . ($r->name ?? '?') . ($r->ticker ? " [{$r->ticker}]" : ''));
        }
    }

    /** Compact elapsed time: "3.4s" under a minute, "2m 05s" past it. */
    private function fmtSeconds(float $seconds): string
    {
        if ($seconds < 60) {
            return number_format($seconds, 1) . 's';
        }
        $m = (int) floor($seconds / 60);
        $s = $seconds - ($m * 60);
        return $m . 'm ' . str_pad((string) (int) round($s), 2, '0', STR_PAD_LEFT) . 's';
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
        if ($this->buybackInstalled()) {
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

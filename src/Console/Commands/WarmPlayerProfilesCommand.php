<?php

namespace HrManager\Console\Commands;

use HrManager\Services\PlayerProfileWarmer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-warm player profile bundles so the profile view loads instantly
 * (Settings → Features, off by default).
 *
 * Two faces:
 *   - SCHEDULED (non-interactive, no flags): the every-30-min background warm —
 *     an incremental pass over all managed corps, bounded by a time budget +
 *     cursor, rebuilding only accounts whose change-fingerprint moved.
 *   - MANUAL (interactive): target one corp with --corporation=<name|ticker|id>
 *     (a preview + confirmation + large-roster heads-up), or --all to warm every
 *     managed corp in one pass (with a heads-up). No target + interactive prints
 *     usage — the scheduler already keeps things warm.
 *
 * Safety: a cache lock stops two runs stacking (belt-and-suspenders with the
 * schedule's allow_overlap=false); the scheduled pass self-limits with a wall
 * budget + user-id cursor so a 40k-character roster warms across cycles.
 *
 * Plugin-absence: every cross-plugin panel (blueprint / buyback / ratting /
 * mining) is read through Manager Core's class_exists-guarded bridge, which
 * returns "unavailable" when the source plugin (or MC) is absent — so the build
 * just produces a lighter bundle (the view self-hides those panels) and, with
 * no cross-plugin fan-out to do, actually runs FASTER. Nothing throws.
 */
class WarmPlayerProfilesCommand extends Command
{
    protected $signature = 'hr-manager:warm-player-profiles
                            {--corporation= : Warm only this corporation (name, ticker, or numeric ID)}
                            {--all : Warm every managed corporation in one pass}
                            {--force : Run even when the pre-warm setting is off}
                            {--include-dormant : Warm accounts with no recent login too (default skips long-dormant ones)}';

    protected $description = 'Pre-compute + cache player profile bundles so the profile view loads instantly';

    private const LOCK_KEY    = 'hr-warm-profiles-lock';
    private const LOCK_TTL    = 7200;  // 2h safety auto-release if a run dies
    private const CURSOR_KEY  = 'hr-warm-profiles-cursor';
    private const WALL_BUDGET = 1200;  // 20 min per scheduled run
    private const USER_BATCH  = 250;   // users pulled per DB page
    private const LARGE_ROSTER = 500;  // characters above which we warn about the time

    /**
     * Skip accounts with no login anywhere in this many days. A dead profile is
     * rarely opened and lazy-builds on the odd view, so warming it every cycle
     * is wasted work on a large / old roster. Override with --include-dormant.
     */
    private const MAX_INACTIVE_DAYS = 180;

    public function handle(PlayerProfileWarmer $warmer): int
    {
        if (!$this->option('force') && !$warmer->isPrewarmEnabled()) {
            $this->info('Profile pre-warm is disabled (Settings → Features). Nothing to do.');
            return 0;
        }

        // Targeted: a single corporation by name / ticker / ID.
        $corpInput = trim((string) ($this->option('corporation') ?? ''));
        if ($corpInput !== '') {
            $corp = $this->resolveCorporation($corpInput);
            if ($corp === null) {
                $this->error("No single corporation matches \"{$corpInput}\". Use an exact name, ticker, or the numeric corporation ID.");
                return 1;
            }
            return $this->warmCorporation($warmer, $corp);
        }

        // Everything, in one pass.
        if ((bool) $this->option('all')) {
            return $this->warmAll($warmer, true);
        }

        // No target. A human gets pointed at the two modes; the scheduler (which
        // runs non-interactively with no flags) does the incremental warm.
        if ($this->interactive()) {
            $this->line('Choose what to warm:');
            $this->line('  <fg=cyan>--corporation=</><name|ticker|id>   one corporation (preview + confirm)');
            $this->line('  <fg=cyan>--all</>                            every managed corporation');
            $this->newLine();
            $this->line('The scheduler already runs the incremental background warm every 30 min — you usually don\'t need to run this by hand.');
            return 0;
        }

        return $this->warmAll($warmer, false);
    }

    // -----------------------------------------------------------------
    // Mode: one corporation
    // -----------------------------------------------------------------

    private function warmCorporation(PlayerProfileWarmer $warmer, object $corp): int
    {
        $corpId = (int) $corp->corporation_id;
        $label  = $this->corpLabel($corp);

        $userIds   = $this->corpAccountIds($corpId);
        $charCount = $this->corpCharacterCount($corpId);
        $acctCount = count($userIds);

        $this->newLine();
        $this->info("Corporation: {$label}  (ID {$corpId})");
        $this->line("  Characters in corp: {$charCount}");
        $this->line("  Registered accounts to warm: {$acctCount}");
        if ($charCount > self::LARGE_ROSTER) {
            $this->warn("  Heads up: {$charCount} characters is a large roster — this can take a while.");
        }
        if ($acctCount === 0) {
            $this->line('  Nothing to warm — no registered accounts in this corp yet.');
            return 0;
        }

        if ($this->interactive() && !$this->confirm("Warm {$acctCount} account(s) for {$label} now?", true)) {
            $this->line('Aborted.');
            return 0;
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);
        if (!$lock->get()) {
            $this->warn('Another warm run is already in progress — try again shortly.');
            return 0;
        }

        try {
            $includeDormant = (bool) $this->option('include-dormant');
            $cutoff = now()->subDays($this->inactiveDays())->toDateTimeString();

            $bar = $this->makeBar($acctCount);
            $c = ['rebuilt' => 0, 'unchanged' => 0, 'empty' => 0, 'dormant' => 0];

            foreach ($userIds as $uid) {
                if (!$includeDormant && $this->isDormant($uid, $cutoff)) {
                    $c['dormant']++;
                } else {
                    $this->warmPair($warmer, $uid, $corpId, $c);
                }
                $this->tickBar($bar, $c);
            }

            $this->finishBar($bar);
            $this->info(sprintf(
                'Warmed %s: %d rebuilt, %d unchanged, %d empty, %d dormant skipped.',
                $label, $c['rebuilt'], $c['unchanged'], $c['empty'], $c['dormant']
            ));
            return 0;
        } finally {
            optional($lock)->release();
        }
    }

    // -----------------------------------------------------------------
    // Mode: every managed corporation (scheduled incremental, or --all full)
    // -----------------------------------------------------------------

    private function warmAll(PlayerProfileWarmer $warmer, bool $unlimited): int
    {
        // An interactive full pass gets a heads-up + confirm; the scheduler runs
        // straight through.
        if ($unlimited && $this->interactive()) {
            $acctCount = (int) DB::table('refresh_tokens')
                ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                ->whereNull('refresh_tokens.deleted_at')
                ->distinct()
                ->count('refresh_tokens.user_id');
            $this->warn("This warms EVERY managed corporation — roughly {$acctCount} account(s). It can take a long time.");
            $this->line('Tip: warm just one corp with --corporation=<name|ticker|id>.');
            if (!$this->confirm('Continue with a full warm of all corporations?', false)) {
                $this->line('Aborted.');
                return 0;
            }
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);
        if (!$lock->get()) {
            $this->warn('Another warm run is already in progress — skipping this cycle.');
            return 0;
        }

        try {
            $deadline     = microtime(true) + $this->runBudget();
            $includeDormant = (bool) $this->option('include-dormant');
            $cutoff       = now()->subDays($this->inactiveDays())->toDateTimeString();
            $cursor       = $unlimited ? 0 : (int) Cache::get(self::CURSOR_KEY, 0);

            // Only warm corps HR manages — a director token populates
            // corporation_member_trackings / corporation_members. A user's alt
            // parked in some OTHER corp makes a (user, corp) profile nobody
            // opens; warming it is waste that multiplies the run. Fallback: if no
            // corp has a director token at all, don't filter.
            $managedCorps = [];
            foreach (['corporation_member_trackings', 'corporation_members'] as $mt) {
                if (Schema::hasTable($mt)) {
                    foreach (DB::table($mt)->distinct()->pluck('corporation_id') as $mc) {
                        $managedCorps[(int) $mc] = true;
                    }
                }
            }
            $filterManaged = !empty($managedCorps);

            $c = ['rebuilt' => 0, 'unchanged' => 0, 'empty' => 0, 'dormant' => 0, 'offcorp' => 0];
            $lastUser = $cursor;
            $wrapped  = false;

            $bar = null;
            if ($this->output->isDecorated()) {
                $total = (int) DB::table('refresh_tokens')
                    ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                    ->whereNull('refresh_tokens.deleted_at')
                    ->where('refresh_tokens.user_id', '>', $cursor)
                    ->distinct()
                    ->count('refresh_tokens.user_id');
                $bar = $this->makeBar($total);
            }

            while (true) {
                $users = DB::table('refresh_tokens')
                    ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                    ->whereNull('refresh_tokens.deleted_at')
                    ->where('refresh_tokens.user_id', '>', $lastUser)
                    ->distinct()
                    ->orderBy('refresh_tokens.user_id')
                    ->limit(self::USER_BATCH)
                    ->pluck('refresh_tokens.user_id')
                    ->map(fn ($i) => (int) $i)
                    ->all();

                if (empty($users)) {
                    $lastUser = 0;    // reset — next run starts a fresh pass
                    $wrapped  = true;
                    break;
                }

                foreach ($users as $uid) {
                    $corps = DB::table('refresh_tokens')
                        ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                        ->whereNull('refresh_tokens.deleted_at')
                        ->where('refresh_tokens.user_id', $uid)
                        ->distinct()
                        ->pluck('character_affiliations.corporation_id')
                        ->filter()
                        ->map(fn ($cc) => (int) $cc)
                        ->unique();
                    if ($filterManaged) {
                        $corps = $corps->filter(fn ($cc) => isset($managedCorps[$cc]));
                    }
                    $corps = $corps->values()->all();

                    if (empty($corps)) {
                        $c['offcorp']++;
                    } elseif (!$includeDormant && $this->isDormant($uid, $cutoff)) {
                        $c['dormant']++;
                    } else {
                        foreach ($corps as $corpId) {
                            $this->warmPair($warmer, $uid, $corpId, $c);
                        }
                    }

                    $lastUser = $uid;
                    $this->tickBar($bar, $c);

                    if (!$unlimited && microtime(true) > $deadline) {
                        break 2; // budget spent — keep $lastUser as the cursor
                    }
                }
            }

            $this->finishBar($bar, $wrapped);
            Cache::put(self::CURSOR_KEY, $lastUser, 86400);

            $this->info(sprintf(
                'Warmed %d rebuilt, %d unchanged, %d empty. Skipped %d dormant + %d off-corp accounts.%s Next cursor: user_id > %d.',
                $c['rebuilt'], $c['unchanged'], $c['empty'], $c['dormant'], $c['offcorp'],
                $wrapped ? ' Full pass complete —' : '',
                $lastUser
            ));
            return 0;
        } finally {
            optional($lock)->release();
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function warmPair(PlayerProfileWarmer $warmer, int $uid, int $corpId, array &$c): void
    {
        try {
            $r = $warmer->warm($uid, $corpId);
            $r === 'rebuilt' ? $c['rebuilt']++ : ($r === 'skipped' ? $c['unchanged']++ : $c['empty']++);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] profile warm failed for user ' . $uid . ' corp ' . $corpId . ': ' . $e->getMessage());
        }
    }

    /** Latest login across the account's characters is older than the cutoff. A null (no tracking data) is NOT dormant. */
    private function isDormant(int $uid, string $cutoff): bool
    {
        if (!Schema::hasTable('corporation_member_trackings')) {
            return false;
        }
        $last = DB::table('corporation_member_trackings')
            ->join('refresh_tokens as rt', 'rt.character_id', '=', 'corporation_member_trackings.character_id')
            ->where('rt.user_id', $uid)
            ->whereNull('rt.deleted_at')
            ->max('corporation_member_trackings.logon_date');
        return $last !== null && (string) $last < $cutoff;
    }

    /** Resolve a corp by numeric ID, exact name/ticker, then fuzzy. Null when nothing (or too many) match. */
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

    private function listAmbiguous($matches): void
    {
        $this->warn('Multiple corporations match — be more specific, or pass the numeric ID:');
        foreach ($matches->take(10) as $m) {
            $this->line(sprintf('  %d  %s [%s]', (int) $m->corporation_id, $m->name ?? '?', $m->ticker ?? '?'));
        }
    }

    private function corpLabel(object $corp): string
    {
        if (!empty($corp->name)) {
            return $corp->name . (!empty($corp->ticker) ? " [{$corp->ticker}]" : '');
        }
        return 'Corporation #' . (int) $corp->corporation_id;
    }

    /** Registered SeAT accounts (user_ids) with a character currently in the corp. */
    private function corpAccountIds(int $corpId): array
    {
        return DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->whereNull('refresh_tokens.deleted_at')
            ->where('character_affiliations.corporation_id', $corpId)
            ->distinct()
            ->pluck('refresh_tokens.user_id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    /** Total characters in the corp — authoritative roster if present, else affiliations. */
    private function corpCharacterCount(int $corpId): int
    {
        foreach (['corporation_members', 'corporation_member_trackings'] as $t) {
            if (Schema::hasTable($t)) {
                $n = (int) DB::table($t)->where('corporation_id', $corpId)->count();
                if ($n > 0) {
                    return $n;
                }
            }
        }
        return Schema::hasTable('character_affiliations')
            ? (int) DB::table('character_affiliations')->where('corporation_id', $corpId)->count()
            : 0;
    }

    private function interactive(): bool
    {
        return $this->input->isInteractive() && $this->output->isDecorated();
    }

    private function runBudget(): int
    {
        return (int) config('hr-manager.performance.warm_run_budget_seconds', self::WALL_BUDGET);
    }

    private function inactiveDays(): int
    {
        return (int) config('hr-manager.performance.warm_max_inactive_days', self::MAX_INACTIVE_DAYS);
    }

    private function makeBar(int $max)
    {
        if (!$this->output->isDecorated()) {
            return null;
        }
        $bar = $this->output->createProgressBar(max(1, $max));
        $bar->setFormat(" %current%/%max% accounts  [%bar%]  %percent:3s%%  %elapsed:6s%/%estimated:-6s%  %message%");
        $bar->setMessage('starting…');
        $bar->start();
        return $bar;
    }

    private function tickBar($bar, array $c): void
    {
        if (!$bar) {
            return;
        }
        $bar->setMessage(sprintf(
            'rebuilt %d · unchanged %d · dormant %d',
            $c['rebuilt'] ?? 0, $c['unchanged'] ?? 0, $c['dormant'] ?? 0
        ));
        $bar->advance();
    }

    private function finishBar($bar, bool $complete = true): void
    {
        if (!$bar) {
            return;
        }
        if ($complete) {
            $bar->finish();
        }
        $this->newLine(2);
    }
}

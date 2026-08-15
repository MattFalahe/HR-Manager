<?php

namespace HrManager\Console\Commands;

use HrManager\Services\EveWhoRosterService;
use HrManager\Services\NameResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh the EveWho member rosters (Settings → Features, off by default). Does
 * the FULL multi-page pull that a page-load seed can't afford — the interactive
 * path uses a short budget to avoid holding the Members page, which is why a big
 * corp can show only the first 500 until this runs.
 *
 * With no target it refreshes exactly the corps that already have a stored
 * roster, which is what the nightly schedule wants: it keeps the in-use rosters
 * full and current without ever introducing new EveWho traffic on its own. Those
 * corps got there by a director opening their Members page once.
 *
 * That default is also its limitation, and the reason for the selectors below: a
 * corp nobody has opened yet is invisible to it. Each selector is a different
 * answer to "which corps do we care about", they can be combined, and the run
 * previews the resolved list before spending a single request.
 *
 * Corps SeAT already has a roster for are skipped by default. The Members page
 * only falls through to EveWho when neither roster table holds the corp, so
 * pulling one of those fetches data the page will never display.
 */
class SyncExternalRostersCommand extends Command
{
    protected $signature = 'hr-manager:sync-external-rosters
                            {--corporation=* : Name, ticker or ID. Repeatable, and accepts a comma-separated list}
                            {--registered : Every corp a currently-registered character belongs to}
                            {--landings : Every corp with an HR recruitment landing}
                            {--alliance= : Every corp in this alliance (ID or exact name)}
                            {--all : Everything the three selectors above would find, plus corps already seeded}
                            {--include-tracked : Also pull corps SeAT already has a roster for (the Members page will not display these)}
                            {--yes : Skip the confirmation prompt}
                            {--force : Run even when the EveWho feature is off}';

    protected $description = 'Refresh the EveWho member rosters (full multi-page pull), for seeded corps or a set you choose';

    private const LOCK_KEY = 'hr-sync-external-rosters-lock';
    private const LOCK_TTL = 3600;

    /** Above this many corps, say how long it will take before starting. */
    private const LARGE_RUN = 25;

    public function handle(EveWhoRosterService $eveWho, NameResolutionService $names): int
    {
        if (!$this->option('force') && !$eveWho->isEnabled()) {
            $this->info('EveWho roster back-fill is disabled (Settings → Features). Nothing to do.');
            $this->line('  Pass <fg=cyan>--force</> to run it anyway.');
            return 0;
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);
        if (!$lock->get()) {
            $this->warn('Another roster sync is already in progress, skipping this cycle.');
            return 0;
        }

        try {
            $targeted = $this->hasSelector();

            $corpIds = $this->resolveTargets($eveWho, $names);
            if ($corpIds === null) {
                return 1; // a selector failed and has already explained itself
            }

            if (empty($corpIds)) {
                $this->info('No corps matched. Corps seed on first view of their Members page; the selectors '
                    . '(--registered / --landings / --alliance / --all) reach ones nobody has opened yet.');
                return 0;
            }

            if ($targeted && !$this->confirmRun($corpIds)) {
                $this->line('Nothing done.');
                return 0;
            }

            return $this->pullRosters($eveWho, $corpIds);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Build the corp list from whichever selectors were passed.
     *
     * @return array<int>|null null when a selector could not be resolved
     */
    private function resolveTargets(EveWhoRosterService $eveWho, NameResolutionService $names): ?array
    {
        $all      = (bool) $this->option('all');
        $explicit = $this->corporationOptionValues();

        // No selector: the scheduled behaviour, unchanged.
        if (!$this->hasSelector()) {
            return $eveWho->syncedCorporationIds();
        }

        $ids = [];
        $add = function (array $found, string $label) use (&$ids) {
            foreach ($found as $id) {
                $ids[(int) $id] = (int) $id;
            }
            $this->line(sprintf('  %-28s %d corp(s)', $label, count($found)));
        };

        $this->line('Resolving targets:');

        foreach ($explicit as $input) {
            $corp = $this->resolveCorporation($input);
            if ($corp === null) {
                $this->error('Could not resolve corporation "' . $input . '".');
                return null;
            }
            $ids[(int) $corp->corporation_id] = (int) $corp->corporation_id;
            $this->line(sprintf('  %-28s %s', '--corporation', $this->corpLabel($corp)));
        }

        if ($all || $this->option('registered')) {
            $add($eveWho->corporationIdsFromRegisteredCharacters(), '--registered');
        }

        if ($all || $this->option('landings')) {
            $add($eveWho->corporationIdsWithLandings(), '--landings');
        }

        if ($allianceInput = (string) $this->option('alliance')) {
            $allianceId = $this->resolveAlliance($allianceInput, $names);
            if ($allianceId === null) {
                $this->error('Could not resolve alliance "' . $allianceInput . '".');
                return null;
            }
            $found = $names->allianceCorporationIds($allianceId);
            if (empty($found)) {
                $this->warn('  Alliance ' . $allianceId . ' returned no corporations (ESI may be unreachable).');
            }
            $add($found, '--alliance');
        }

        // --all also keeps whatever is already seeded, so it is a superset of
        // the default rather than a different list.
        if ($all) {
            $add($eveWho->syncedCorporationIds(), 'already seeded');
        }

        $ids = array_values($ids);

        if (!$this->option('include-tracked')) {
            $tracked = $eveWho->corporationIdsWithSeatRoster();
            $before  = count($ids);
            $ids     = array_values(array_diff($ids, $tracked));
            $skipped = $before - count($ids);

            if ($skipped > 0) {
                $this->line(sprintf(
                    '  %-28s %d corp(s) skipped: SeAT already has their roster, so the Members page would never show an EveWho one. Pass --include-tracked to pull them anyway.',
                    'already tracked by SeAT',
                    $skipped
                ));
            }
        }

        return $ids;
    }

    /** Did the operator name a set, rather than taking the scheduled default? */
    private function hasSelector(): bool
    {
        return (bool) $this->option('all')
            || !empty($this->corporationOptionValues())
            || (bool) $this->option('registered')
            || (bool) $this->option('landings')
            || (bool) $this->option('alliance');
    }

    /**
     * --corporation is repeatable AND accepts a comma-separated list, because
     * both are things people reach for without checking.
     *
     * @return array<string>
     */
    private function corporationOptionValues(): array
    {
        $out = [];
        foreach ((array) $this->option('corporation') as $raw) {
            foreach (explode(',', (string) $raw) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    private function confirmRun(array $corpIds): bool
    {
        $count = count($corpIds);
        $this->newLine();
        $this->info($count . ' corp(s) to pull from EveWho.');

        // Never prompt without a real terminal. isDecorated is the same tty
        // test the progress bar uses, and a scheduled run has neither.
        if ($this->option('yes') || !$this->input->isInteractive() || !$this->output->isDecorated()) {
            return true;
        }

        if ($count >= self::LARGE_RUN) {
            // Roughly a second or two each, plus EveWho's own pacing.
            $this->warn('That is a large run: expect a few minutes, and it makes ' . $count . ' requests to a third-party service.');
        }

        return $this->confirm('Continue?', $count < self::LARGE_RUN);
    }

    /**
     * Pull each corp in turn and summarise what came back.
     *
     * NOT named run(): Illuminate\Console\Command inherits a public run() from
     * Symfony, and redeclaring it private is a fatal at class-load time, which
     * takes down every artisan invocation including the ones composer fires
     * during install.
     */
    private function pullRosters(EveWhoRosterService $eveWho, array $corpIds): int
    {
        $bar = null;
        if ($this->output->isDecorated()) {
            $bar = $this->output->createProgressBar(count($corpIds));
            $bar->setFormat(" %current%/%max% corps  [%bar%]  %percent:3s%%  %elapsed:6s%  %message%");
            $bar->setMessage('starting…');
            $bar->start();
        }

        $totalChars = 0;
        $failed     = 0;
        $empty      = 0;
        $seatAdded  = 0;
        $short      = [];

        foreach ($corpIds as $corpId) {
            try {
                // Force + the full background budget = fetch every page.
                $count = $eveWho->sync($corpId, true, EveWhoRosterService::WALL_BUDGET_FULL);
                if ($count === null) {
                    $failed++;
                } else {
                    $totalChars += $count;
                    if ($count === 0) {
                        $empty++;
                    }
                    if ($eveWho->lastRunWasShort()) {
                        $short[$corpId] = $eveWho->lastSyncStats();
                    }
                    $st = $eveWho->lastSyncStats();
                    $seatAdded += $st['seat_added'] ?? 0;
                }
                if ($bar) {
                    $bar->setMessage(sprintf('corp %d · %s chars', $corpId, $count === null ? 'failed' : (string) $count));
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[HR Manager] external roster sync failed for corp ' . $corpId . ': ' . $e->getMessage());
            }
            if ($bar) {
                $bar->advance();
            }
        }

        if ($bar) {
            $bar->finish();
            $this->newLine(2);
        }

        $this->info(sprintf(
            'Refreshed %d corp roster(s) from EveWho: %d characters total%s.',
            count($corpIds),
            $totalChars,
            $failed > 0 ? ", {$failed} failed (kept previous copy)" : ''
        ));

        // Worth calling out separately: these are members EveWho did not know
        // about, and they are disproportionately the registered ones.
        if ($seatAdded > 0) {
            $this->line('  ' . $seatAdded . ' of those came from SeAT\'s own affiliation data rather than EveWho.');
        }

        // A corp EveWho knows nothing about looks identical to a successful
        // pull of nothing, so name it rather than let it read as a silent win.
        if ($empty > 0) {
            $this->warn($empty . ' corp(s) returned no members. EveWho only knows corps it has seen public activity for.');
        }

        // A truncated roster looks complete from the inside. Say which corps
        // came back short and what EveWho itself claims they hold, so nobody
        // reads a 500-member ceiling as the corp's real size.
        if (!empty($short)) {
            $this->newLine();
            $this->warn(count($short) . ' corp(s) came back short of what EveWho reports they hold:');
            foreach ($short as $corpId => $s) {
                $this->line(sprintf(
                    '    corp %d: stored %d of %d (%s)  [%d EveWho + %d SeAT]%s',
                    $corpId,
                    $s['stored'],
                    $s['expected'],
                    $s['expected_from'] === 'esi' ? 'ESI member_count' : 'EveWho total',
                    $s['evewho'],
                    $s['seat_added'],
                    $s['stalled'] ? '  (EveWho served the same page again, so the rest is unreachable)' : ''
                ));
            }
            $this->line('  A public source can only infer membership from public activity, so it is always a little short.');
            $this->line('  A director token with read_corporation_membership remains the only way to see a full roster.');
        }

        return 0;
    }

    /** Accepts an alliance ID or an exact alliance name. */
    private function resolveAlliance(string $input, NameResolutionService $names): ?int
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (ctype_digit($input)) {
            return (int) $input;
        }

        $hit = $names->resolveNamesToEntities([$input])[mb_strtolower($input)] ?? null;

        return ($hit && $hit['category'] === 'alliance') ? (int) $hit['id'] : null;
    }

    /**
     * Name, ticker or numeric ID, matching how --corporation behaves on
     * hr-manager:warm-player-profiles.
     *
     * An ID that SeAT has never resolved is still accepted: EveWho is asked
     * about corps SeAT does not know, which is the whole point of the feature.
     */
    private function resolveCorporation(string $input): ?object
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (ctype_digit($input)) {
            $id  = (int) $input;
            $row = Schema::hasTable('corporation_infos')
                ? DB::table('corporation_infos')->where('corporation_id', $id)->first(['corporation_id', 'name', 'ticker'])
                : null;
            return $row ?: (object) ['corporation_id' => $id, 'name' => null, 'ticker' => null];
        }

        if (!Schema::hasTable('corporation_infos')) {
            $this->error('  A name or ticker needs corporation_infos, which this install has no table for. Pass a numeric ID.');
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
            ->whereRaw('LOWER(name) LIKE ?', ['%' . $lower . '%'])
            ->limit(10)
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
        $this->warn('  That matches more than one corporation:');
        foreach ($rows as $r) {
            $this->line('    ' . $this->corpLabel($r));
        }
        $this->line('  Pass the numeric ID instead.');
    }

    private function corpLabel(object $corp): string
    {
        $name = $corp->name ?? ('Corp #' . $corp->corporation_id);
        return $corp->ticker
            ? sprintf('%s [%s] (%d)', $name, $corp->ticker, $corp->corporation_id)
            : sprintf('%s (%d)', $name, $corp->corporation_id);
    }
}

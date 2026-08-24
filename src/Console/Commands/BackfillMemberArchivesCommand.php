<?php

namespace HrManager\Console\Commands;

use Carbon\Carbon;
use HrManager\Models\MemberArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild records for people who left BEFORE the archive existed.
 *
 * From here on, departures are snapshotted as they happen. That does nothing
 * for anyone already gone, which is most of the people you would actually want
 * to look up on day one, so this walks SeAT's corporation histories and
 * reconstructs the stints it can see.
 *
 * Everything it writes is marked `reconstructed`, never `recorded`, and the UI
 * shows the difference. That distinction is the whole point: a reconstruction
 * is built from whatever survived, and what survived is exactly what a
 * departing member did not take with them when they revoked their key. Showing
 * it as though it were a contemporaneous snapshot would invite decisions based
 * on totals that quietly omit whatever was already pruned.
 *
 * Never overwrites a recorded row, and re-running is safe.
 */
class BackfillMemberArchivesCommand extends Command
{
    protected $signature = 'hr-manager:backfill-member-archives
                            {--corporation= : Only this corporation (numeric ID)}
                            {--since= : Only stints that ended on or after this date (YYYY-MM-DD)}
                            {--dry-run : Report what would be written without writing it}
                            {--yes : Skip the confirmation prompt}';

    protected $description = 'Reconstruct Former Member records for departures that predate the archive';

    public function handle(): int
    {
        if (!Schema::hasTable('hr_manager_member_archives')) {
            $this->warn('Member archive table is missing: migration pending. Restart SeAT to apply it, then re-run.');
            return 0;
        }
        if (!Schema::hasTable('character_corporation_histories')) {
            $this->error('SeAT has no character_corporation_histories table, so there is nothing to reconstruct from.');
            return 1;
        }

        $corps = $this->targetCorporations();
        if (empty($corps)) {
            $this->warn('No corporations to scan. HR only reconstructs for corps it has a roster for.');
            return 0;
        }

        $since = null;
        if ($this->option('since')) {
            try {
                $since = Carbon::parse((string) $this->option('since'));
            } catch (\Throwable $e) {
                $this->error('Could not read --since as a date. Use YYYY-MM-DD.');
                return 1;
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line('Scanning ' . count($corps) . ' corporation(s)' . ($since ? ' for stints ending on or after ' . $since->toDateString() : '') . '.');

        $found = [];
        foreach ($corps as $corpId) {
            foreach ($this->closedStintsFor($corpId, $since) as $stint) {
                $found[] = $stint;
            }
        }

        if (empty($found)) {
            $this->info('No closed stints found that are not already archived.');
            return 0;
        }

        $this->info(count($found) . ' stint(s) could be reconstructed.');

        if ($dryRun) {
            $this->table(
                ['Character', 'Corp', 'Joined', 'Left', 'Days'],
                array_map(fn ($s) => [
                    $s['character_name'] ?: ('#' . $s['character_id']),
                    $s['corporation_id'],
                    $s['joined_at'] ? $s['joined_at']->toDateString() : '?',
                    $s['left_at']->toDateString(),
                    $s['days_in_corp'] ?? '?',
                ], array_slice($found, 0, 25))
            );
            if (count($found) > 25) {
                $this->line('  ... and ' . (count($found) - 25) . ' more.');
            }
            $this->comment('Dry run: nothing was written.');
            return 0;
        }

        if (!$this->option('yes') && $this->input->isInteractive() && $this->output->isDecorated()) {
            $this->warn('These will be written as RECONSTRUCTED, meaning partial by definition: only what survived in SeAT can be recovered.');
            if (!$this->confirm('Write ' . count($found) . ' reconstructed record(s)?', true)) {
                $this->line('Nothing written.');
                return 0;
            }
        }

        $written = 0;
        $skipped = 0;
        foreach ($found as $stint) {
            try {
                // A recorded row always wins: it was taken while the data was
                // still whole, and a reconstruction must never overwrite it.
                $exists = MemberArchive::where('character_id', $stint['character_id'])
                    ->where('corporation_id', $stint['corporation_id'])
                    ->whereBetween('left_at', [
                        $stint['left_at']->copy()->subDays(2),
                        $stint['left_at']->copy()->addDays(2),
                    ])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                MemberArchive::create($stint + ['source' => MemberArchive::SOURCE_RECONSTRUCTED]);
                $written++;
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] archive backfill row failed: ' . $e->getMessage());
                $skipped++;
            }
        }

        $this->info(sprintf('Wrote %d reconstructed record(s), skipped %d already covered.', $written, $skipped));
        $this->line('They appear on Former Members badged as reconstructed, so nobody mistakes them for a contemporaneous snapshot.');

        return 0;
    }

    /**
     * Closed stints in this corp: a history row for the corp with a later row
     * after it, meaning they joined and then went somewhere else.
     *
     * @return array<int, array<string, mixed>>
     */
    private function closedStintsFor(int $corporationId, ?Carbon $since): array
    {
        // Only characters SeAT has ever resolved can be reconstructed at all.
        try {
            $charIds = DB::table('character_corporation_histories')
                ->where('corporation_id', $corporationId)
                ->distinct()
                ->pluck('character_id')
                ->map(fn ($c) => (int) $c)
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] archive backfill history read failed: ' . $e->getMessage());
            return [];
        }

        if (empty($charIds)) {
            return [];
        }

        $out = [];
        foreach (array_chunk($charIds, 500) as $chunk) {
            $rows = DB::table('character_corporation_histories')
                ->whereIn('character_id', $chunk)
                ->orderBy('character_id')
                ->orderBy('start_date')
                ->get(['character_id', 'corporation_id', 'start_date']);

            $byChar = [];
            foreach ($rows as $r) {
                $byChar[(int) $r->character_id][] = $r;
            }

            foreach ($byChar as $charId => $history) {
                foreach ($history as $i => $row) {
                    if ((int) $row->corporation_id !== $corporationId) {
                        continue;
                    }

                    // The NEXT row's start date is when this stint ended. No
                    // next row means they are still there, so it is not a
                    // closed stint and has no place in an archive of departures.
                    $next = $history[$i + 1] ?? null;
                    if ($next === null || !$next->start_date) {
                        continue;
                    }

                    $leftAt = Carbon::parse($next->start_date);
                    if ($since && $leftAt->lessThan($since)) {
                        continue;
                    }

                    $joinedAt = $row->start_date ? Carbon::parse($row->start_date) : null;

                    $out[] = [
                        'character_id'   => (int) $charId,
                        'corporation_id' => $corporationId,
                        'character_name' => $this->characterName((int) $charId),
                        'user_id'        => $this->userIdFor((int) $charId),
                        'joined_at'      => $joinedAt,
                        'left_at'        => $leftAt,
                        'days_in_corp'   => $joinedAt ? max(0, $joinedAt->diffInDays($leftAt)) : null,

                        'destination_corporation_id'   => (int) $next->corporation_id,
                        'destination_corporation_name' => $this->corporationName((int) $next->corporation_id),

                        // Unknowable in hindsight. Left honest rather than
                        // guessed: whether somebody was purged is a fact about
                        // a decision, and inventing one would be worse than
                        // admitting we cannot tell.
                        'departure_type'           => MemberArchive::DEPARTURE_UNKNOWN,
                        'token_valid_at_departure' => false,
                    ];
                }
            }
        }

        return $out;
    }

    /** @return array<int> */
    private function targetCorporations(): array
    {
        if ($this->option('corporation')) {
            $id = (int) $this->option('corporation');
            return $id > 0 ? [$id] : [];
        }

        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            try {
                $ids = DB::table($table)->distinct()->pluck('corporation_id')
                    ->map(fn ($c) => (int) $c)->filter()->values()->all();
                if (!empty($ids)) {
                    return $ids;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return [];
    }

    private function characterName(int $characterId): ?string
    {
        try {
            $n = DB::table('character_infos')->where('character_id', $characterId)->value('name');
            return $n ? (string) $n : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function corporationName(int $corporationId): ?string
    {
        if (!Schema::hasTable('corporation_infos')) {
            return null;
        }
        try {
            $n = DB::table('corporation_infos')->where('corporation_id', $corporationId)->value('name');
            return $n ? (string) $n : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function userIdFor(int $characterId): ?int
    {
        try {
            $id = DB::table('refresh_tokens')->where('character_id', $characterId)->value('user_id');
            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

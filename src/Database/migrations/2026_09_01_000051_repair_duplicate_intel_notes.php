<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Clear out intel notes that were filed twice against the same character.
 *
 * Adding a note wrote from two independent loops: the possible-alts list the
 * director typed by hand, and the include-alts expansion of the SeAT account.
 * A character in BOTH — which is the normal case, not an edge case, since a
 * hand-listed alt is usually registered too — got the identical note twice.
 * The main was written once, so a person with five alts produced eleven rows
 * where six were meant.
 *
 * Only provably redundant rows go: same character, same author, same scope,
 * same body, and written in the same minute, which is the signature of one
 * submission rather than a director deliberately re-filing the same text later.
 * The earliest row is kept, so created_at and anything referencing the note by
 * id survive.
 *
 * Deliberately narrow. Two notes with the same body on the same character from
 * different authors, different scopes, or a different day are a real editorial
 * decision somebody made, and a migration has no business guessing at those.
 */
class RepairDuplicateIntelNotes extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_intel_notes')) {
            return;
        }

        try {
            // Grouping on a minute-truncated timestamp: rows from one request
            // share a created_at to the second in practice, but a slow bulk add
            // can straddle a second boundary, and a minute is still far tighter
            // than any deliberate re-filing.
            $groups = DB::table('hr_manager_intel_notes')
                ->select(
                    'character_id',
                    'author_id',
                    'scope_corporation_id',
                    DB::raw('MD5(body) as body_hash'),
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as minute"),
                    DB::raw('MIN(id) as keep_id'),
                    DB::raw('COUNT(*) as n')
                )
                ->groupBy('character_id', 'author_id', 'scope_corporation_id', 'body_hash', 'minute')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            $removed = 0;

            foreach ($groups as $g) {
                $ids = DB::table('hr_manager_intel_notes')
                    ->where('character_id', $g->character_id)
                    ->where('author_id', $g->author_id)
                    ->where('id', '>', $g->keep_id)
                    ->whereRaw('MD5(body) = ?', [$g->body_hash])
                    ->whereRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') = ?", [$g->minute]);

                // scope_corporation_id is nullable, and NULL = NULL is never
                // true in SQL, so the two cases need asking differently.
                if ($g->scope_corporation_id === null) {
                    $ids->whereNull('scope_corporation_id');
                } else {
                    $ids->where('scope_corporation_id', $g->scope_corporation_id);
                }

                $doomed = $ids->pluck('id')->all();
                if (empty($doomed)) {
                    continue;
                }

                // Any suspected-alt claim pointing at a row about to go is
                // re-pointed at the survivor rather than left dangling.
                if (Schema::hasTable('hr_manager_suspected_alt_links')) {
                    DB::table('hr_manager_suspected_alt_links')
                        ->whereIn('source_id', $doomed)
                        ->where('source', 'intel')
                        ->update(['source_id' => $g->keep_id]);
                }

                $removed += DB::table('hr_manager_intel_notes')->whereIn('id', $doomed)->delete();
            }

            if ($removed > 0) {
                Log::info('[HR Manager] removed ' . $removed . ' duplicate intel note(s).');
            }
        } catch (\Throwable $e) {
            // A failed cleanup must never block the migration run. The
            // duplicates are cosmetic, and the fix that stops new ones is in
            // the controller regardless.
            Log::warning('[HR Manager] duplicate intel note cleanup failed: ' . $e->getMessage());
        }
    }

    /** Forward-only: the removed rows were redundant copies. */
    public function down(): void
    {
        // no-op
    }
}

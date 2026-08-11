<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Clear out duplicate CURRENT identity mappings.
 *
 * resolveOrCreate() called forSeatUser() — which already maps every unmapped
 * character on the account — and then created a mapping for the same character
 * again, so every auto-linked character ended up with two identical open
 * mappings. Harmless to read past, but it showed each character twice in the
 * identity audit trail and double-counted them on the identity.
 *
 * Removes only provably redundant rows: same character, same identity, both
 * open, keeping the earliest. Rows that differ in identity are NOT touched —
 * two open mappings pointing at different identities would be a real conflict
 * a director needs to resolve by hand, not something a migration should guess
 * at.
 */
class RepairDuplicateIdentityMappings extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_character_identity_mappings')) {
            return;
        }

        try {
            // Groups of open mappings that are identical in the pair that
            // matters. Keeping MIN(id) preserves the original row and its
            // effective_from, so the audit trail reads exactly as it would
            // have without the bug.
            $dupes = DB::table('hr_manager_character_identity_mappings')
                ->select('character_id', 'player_identity_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as n'))
                ->whereNull('effective_to')
                ->groupBy('character_id', 'player_identity_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            $removed = 0;
            foreach ($dupes as $d) {
                $removed += DB::table('hr_manager_character_identity_mappings')
                    ->whereNull('effective_to')
                    ->where('character_id', $d->character_id)
                    ->where('player_identity_id', $d->player_identity_id)
                    ->where('id', '>', $d->keep_id)
                    ->delete();
            }

            if ($removed > 0) {
                Log::info('[HR Manager] removed ' . $removed . ' duplicate current identity mapping(s).');
            }
        } catch (\Throwable $e) {
            // Never block the migration run over a cleanup; the guard in
            // createMapping() stops any new ones regardless.
            Log::warning('[HR Manager] duplicate identity mapping repair failed: ' . $e->getMessage());
        }
    }

    /** Forward-only: the removed rows were redundant, nothing to restore. */
    public function down(): void
    {
        // no-op
    }
}

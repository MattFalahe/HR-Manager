<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record where a merged identity went, instead of deleting it.
 *
 * Merging soft-deleted the losing identity. But that identity is still the one
 * keyed to its SeAT account, so the next time anything resolved that account
 * HR found nothing (soft-deletes are excluded) and minted a fresh empty
 * identity — one per merge, forever, each showing "no characters currently
 * mapped" with no hint of why.
 *
 * Keeping the row and pointing it at the winner fixes that at the source and
 * gives the profile something to explain itself with.
 */
class AddMergeLineageToIdentities extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_player_identities')) {
            return;
        }
        if (Schema::hasColumn('hr_manager_player_identities', 'merged_into_id')) {
            return;
        }

        Schema::table('hr_manager_player_identities', function (Blueprint $table) {
            $table->unsignedBigInteger('merged_into_id')->nullable()->after('seat_user_id');
            $table->unsignedBigInteger('merged_by')->nullable()->after('merged_into_id');
            $table->timestamp('merged_at')->nullable()->after('merged_by');
            $table->text('merge_notes')->nullable()->after('merged_at');

            $table->index('merged_into_id', 'hr_ident_merged_into_idx');
        });
    }

    /** Forward-only: the released schema never drops columns. */
    public function down(): void
    {
        // no-op
    }
}

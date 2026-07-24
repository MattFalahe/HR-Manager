<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a purge-scheduled player's SeAT squad memberships were cleared
 * (manually via the purge board / player profile, or by the opt-in auto
 * cleanup). One nullable timestamp on hr_manager_player_status so the auto
 * cleanup fires exactly once per purge and the board can show it as done.
 *
 * Additive + nullable; existing rows untouched.
 */
class AddPurgeSquadsRemovedToPlayerStatus extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_player_status')) {
            return;
        }

        Schema::table('hr_manager_player_status', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_player_status', 'purge_squads_removed_at')) {
                $table->timestamp('purge_squads_removed_at')->nullable()->after('purge_notes');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('hr_manager_player_status')) {
            return;
        }

        Schema::table('hr_manager_player_status', function (Blueprint $table) {
            if (Schema::hasColumn('hr_manager_player_status', 'purge_squads_removed_at')) {
                $table->dropColumn('purge_squads_removed_at');
            }
        });
    }
}

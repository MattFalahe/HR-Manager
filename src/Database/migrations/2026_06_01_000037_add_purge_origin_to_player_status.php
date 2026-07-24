<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds purge_origin to hr_manager_player_status so the Token-loss Watchdog can
 * tell its OWN auto-scheduled purges apart from a director's manual purge.
 * Only 'token_loss'-origin purges are eligible for auto-cancel on token
 * restore; a manual purge is never touched by the automation.
 *
 * Existing rows default to 'manual' — the conservative choice, so any purge
 * that predates this feature is treated as a human decision and left alone.
 */
class AddPurgeOriginToPlayerStatus extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_player_status')) {
            return;
        }

        Schema::table('hr_manager_player_status', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_player_status', 'purge_origin')) {
                $table->string('purge_origin', 20)->default('manual')->after('reason');
            }
        });
    }

    public function down(): void
    {
        // Leave the column in place on rollback (additive, harmless).
    }
}

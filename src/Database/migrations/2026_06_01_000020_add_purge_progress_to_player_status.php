<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purge-progress tracking on hr_manager_player_status. Powers the Corp Health
 * Purge board: a per-step completion timestamp (in-game roles removed)
 * plus auto-detected corp-removal (purge_left_corp_at + the corp they went to),
 * and a free-text progress note distinct from the original purge reason.
 *
 * All additive + nullable, so existing active/LOA/marked rows are untouched.
 */
class AddPurgeProgressToPlayerStatus extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_player_status')) {
            return;
        }

        Schema::table('hr_manager_player_status', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_player_status', 'purge_roles_removed_at')) {
                $table->timestamp('purge_roles_removed_at')->nullable()->after('purge_scheduled_for');
            }
            if (!Schema::hasColumn('hr_manager_player_status', 'purge_left_corp_at')) {
                $table->timestamp('purge_left_corp_at')->nullable()->after('purge_roles_removed_at');
            }
            if (!Schema::hasColumn('hr_manager_player_status', 'purge_left_corp_to')) {
                $table->unsignedBigInteger('purge_left_corp_to')->nullable()->after('purge_left_corp_at');
            }
            if (!Schema::hasColumn('hr_manager_player_status', 'purge_notes')) {
                $table->text('purge_notes')->nullable()->after('purge_left_corp_to');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('hr_manager_player_status')) {
            return;
        }

        Schema::table('hr_manager_player_status', function (Blueprint $table) {
            foreach ([
                'purge_roles_removed_at',
                'purge_left_corp_at',
                'purge_left_corp_to',
                'purge_notes',
            ] as $col) {
                if (Schema::hasColumn('hr_manager_player_status', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}

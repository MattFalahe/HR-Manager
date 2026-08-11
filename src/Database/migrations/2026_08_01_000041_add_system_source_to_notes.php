<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Label automated notes by which subsystem wrote them.
 *
 * System notes are authored as user 0, which the player view rendered as
 * "HR Watchdog" — correct while token-loss was the only thing writing them,
 * wrong now that the purge workflow narrates itself the same way. author_id is
 * unsigned, so a second sentinel id isn't available; a source tag is both
 * cleaner and reusable by anything else that wants to leave an automated note.
 *
 * Nullable, so existing system notes (all of them Watchdog's) keep rendering
 * under the Watchdog label via the view's fallback.
 */
class AddSystemSourceToNotes extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_notes')) {
            return;
        }
        if (Schema::hasColumn('hr_manager_notes', 'system_source')) {
            return;
        }

        Schema::table('hr_manager_notes', function (Blueprint $table) {
            $table->string('system_source', 32)->nullable()->after('author_id');
        });
    }

    /** Forward-only: the released schema never drops columns. */
    public function down(): void
    {
        // no-op
    }
}

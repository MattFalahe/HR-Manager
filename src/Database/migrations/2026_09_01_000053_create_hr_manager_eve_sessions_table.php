<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per EVE play session of one character, copied out of SeAT's
 * character_onlines.
 *
 * SeAT keeps only the latest login and logout per character and overwrites
 * them on every refresh, so without a copy there is no "how much did this
 * person play last month", only "when did they last log in". HR reads the
 * snapshot every few minutes and keeps each session it sees.
 *
 * SeAT refreshes a character at most once an hour. Somebody who logs in and
 * out more than once inside that hour leaves only their last session visible;
 * the logins counter still moves, so the session we do see records how many
 * were folded into it (merged_logins) and spans from the first login we saw to
 * the last logout, which is what a relog after a disconnect looks like anyway.
 */
class CreateHrManagerEveSessionsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_eve_sessions')) {
            return;
        }

        Schema::create('hr_manager_eve_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('character_id');

            $table->timestamp('started_at')->nullable();

            // Null while the session is open. An open session only counts up to
            // last_seen_at, the last time SeAT confirmed the character online,
            // so a token that dies mid-session cannot run up hours forever.
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // ESI's lifetime logins counter when the session was first seen.
            $table->unsignedInteger('logins_at_start')->nullable();
            $table->unsignedInteger('merged_logins')->default(0);

            // ESI gave no logout at or after the login, so the end is the last
            // time SeAT saw the character offline rather than a real logout.
            $table->boolean('end_estimated')->default(false);

            $table->timestamps();

            $table->unique(['character_id', 'started_at'], 'hr_eve_sess_char_start_uniq');
            $table->index(['character_id', 'ended_at'], 'hr_eve_sess_char_open_idx');
            $table->index('started_at', 'hr_eve_sess_start_idx');
        });
    }

    /** Forward-only: the released schema never drops tables. */
    public function down(): void
    {
        // no-op
    }
}

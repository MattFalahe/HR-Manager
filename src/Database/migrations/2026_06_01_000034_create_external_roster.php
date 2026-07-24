<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in secondary roster source (Settings → Features, off by default).
 *
 * When SeAT has no authoritative corp roster (no Director token with the
 * read_corporation_membership scope), the Members page can back-fill the
 * character list from EveWho's public corp API so it isn't near-empty.
 * EveWhoRosterService upserts one row per character here (name-only), and the
 * page reads it as just another roster source — clearly badged "EveWho" so it
 * is never mistaken for SeAT-verified membership. Aggregator data, so it can
 * lag real membership; the authoritative fix is still a Director token.
 */
class CreateExternalRoster extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_external_roster')) {
            return;
        }

        Schema::create('hr_manager_external_roster', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('character_id');
            $table->string('name')->nullable();          // EveWho-reported name (fallback for display)
            $table->string('source', 32)->default('evewho');
            $table->timestamp('fetched_at')->nullable(); // when this corp was last pulled
            $table->timestamps();

            // One row per (corp, character). Explicit names — the long table
            // prefix would otherwise overflow MySQL's 64-char index-name limit.
            $table->unique(['corporation_id', 'character_id'], 'hr_ext_roster_corp_char_uq');
            $table->index('corporation_id', 'hr_ext_roster_corp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_external_roster');
    }
}

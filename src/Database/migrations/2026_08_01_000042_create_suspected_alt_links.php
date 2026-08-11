<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This character is probably an alt of that one" — recorded as a CLAIM, not
 * as fact.
 *
 * For someone who has never been in SeAT there is no account to read and no
 * way to prove an alt link: EVE's API exposes no account concept at all. A
 * director listing a spy's alts is working from their own intel, so HR records
 * the assertion, who made it, and then lets reality settle it — a shared SeAT
 * account later confirms the link, two different accounts refute it.
 *
 * Deliberately NOT stored in hr_manager_character_identity_mappings. Those are
 * authoritative and drive real access decisions; writing unverified suspicion
 * into them would leak "maybe" into every surface that assumes "yes".
 */
class CreateSuspectedAltLinks extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_suspected_alt_links')) {
            return;
        }

        Schema::create('hr_manager_suspected_alt_links', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('suspected_character_id');
            $table->string('suspected_character_name', 64)->nullable();
            $table->unsignedBigInteger('main_character_id');
            $table->string('main_character_name', 64)->nullable();

            // Which surface the claim was made from, and the row it backs, so a
            // resolved link can point back at the entry it annotates.
            $table->string('source', 16)->default('watchlist');
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('state', 16)->default('suspected');

            $table->unsignedBigInteger('asserted_by')->nullable();
            $table->timestamp('asserted_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            // One claim per (alt, main) pair — re-asserting updates rather than
            // stacking duplicates. Named explicitly: the auto-generated name
            // would run well past MySQL's 64-character identifier limit.
            $table->unique(['suspected_character_id', 'main_character_id'], 'hr_alt_link_pair_uniq');
            $table->index('state', 'hr_alt_link_state_idx');
            $table->index('main_character_id', 'hr_alt_link_main_idx');
        });
    }

    /** Forward-only: the released schema never drops tables. */
    public function down(): void
    {
        // no-op
    }
}

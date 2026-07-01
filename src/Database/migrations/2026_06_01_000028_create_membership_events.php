<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent log of corp membership changes, written by MembershipChangeService
 * alongside each notification. Drives the Corp Health → Membership tab: a
 * recent joins / leaves history, plus a review queue of "joined without a valid
 * application" flags a director can acknowledge (reviewed_at / reviewed_by).
 *
 * change_type     'joined' | 'left'
 * classification  joins only: 'known_alt' | 'applied' | 'no_application'
 *                 (leaves are null)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_membership_events')) {
            return;
        }

        Schema::create('hr_manager_membership_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('main_character_id')->nullable();
            $table->string('change_type', 16);              // joined | left
            $table->string('classification', 24)->nullable(); // known_alt | applied | no_application
            $table->boolean('player_still_present')->default(false); // leaves: account keeps a character
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['corporation_id', 'occurred_at']);
            // Fast lookup of the unreviewed no-application review queue.
            $table->index(['corporation_id', 'classification', 'reviewed_at'], 'hr_mem_evt_corp_class_rev_idx');
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_membership_events');
    }
};

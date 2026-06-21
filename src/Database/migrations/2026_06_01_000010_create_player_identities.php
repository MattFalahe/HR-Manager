<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Player Identity system.
 *
 * A "player identity" is a persistent record of a human, independent
 * of the SeAT user account, the in-game character, or corp membership.
 * It survives:
 *   - Characters being renamed in game
 *   - SeAT accounts being deleted and re-created
 *   - Characters being passed from one account to another
 *     ("account takeover" — a real thing in EVE long-tail communities)
 *   - The original human leaving the corp / coalition / game
 *
 * The character_identity_mappings table records WHICH identity owns
 * a given character_id AT A POINT IN TIME, with effective_from /
 * effective_to date pairs. The active mapping per character is the
 * one with effective_to IS NULL. Historical mappings are kept so the
 * audit trail of "who owned this character when" survives.
 *
 * Lifecycle:
 *   - Identities auto-materialize the first time a character is
 *     viewed via PlayerIdentityResolver::forCharacter (lazy backfill;
 *     migration creates the tables, doesn't bulk-create rows).
 *   - When a SeAT user exists, one identity is created per user.id
 *     (seat_user_id on the identity).
 *   - When no SeAT user exists (unregistered alt), a "ghost" identity
 *     is created with seat_user_id=NULL.
 *   - Directors can MERGE two identities (same person, two SeAT
 *     accounts) and REASSIGN a character (account takeover).
 */
class CreatePlayerIdentities extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_player_identities')) {
            Schema::create('hr_manager_player_identities', function (Blueprint $table) {
                $table->bigIncrements('id');
                // Display name; updates as the player's main char
                // renames or as a director edits it.
                $table->string('primary_name', 96)->nullable();
                // Optional link to a SeAT user account. Multiple
                // identities can share the same seat_user_id only
                // briefly during a merge operation; otherwise
                // 1-to-1 + nullable for ghost identities.
                $table->unsignedBigInteger('seat_user_id')->nullable();
                // Free-form director-authored "who is this player"
                // summary. Independent of intel notes (which are
                // character-keyed).
                $table->text('notes_summary')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('seat_user_id');
                $table->index('primary_name');
            });
        }

        if (!Schema::hasTable('hr_manager_character_identity_mappings')) {
            Schema::create('hr_manager_character_identity_mappings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('character_id');
                $table->unsignedBigInteger('player_identity_id');
                // Date-bounded ownership window. NULL effective_to
                // means "current owner".
                $table->timestamp('effective_from')->useCurrent();
                $table->timestamp('effective_to')->nullable();
                // Audit fields
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->enum('reason', [
                    'auto_seat',          // auto-created from a SeAT refresh_token
                    'auto_member_track',  // auto-created from corp_member_tracking
                    'ghost_unregistered', // unregistered alt
                    'manual',             // director added by hand
                    'account_takeover',   // ownership changed (character sold / handed over)
                    'merge',              // identity merge moved this mapping
                ])->default('manual');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['character_id', 'effective_to']);
                $table->index('player_identity_id');
                $table->foreign('player_identity_id')
                    ->references('id')
                    ->on('hr_manager_player_identities')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_character_identity_mappings');
        Schema::dropIfExists('hr_manager_player_identities');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct ISK transfers between corp members and entities the corp rates badly.
 *
 * One row per matching wallet journal entry. Deliberately NOT computed on the
 * fly: the journal is one of the largest tables SeAT holds, and a page that had
 * to scan it would be unusable on a real corp. A scheduled pass writes here and
 * every surface reads these rows.
 *
 * The counterparty's standing is LOCKED onto the row at scan time. That is the
 * important decision in this table. EVE's API exposes who someone is affiliated
 * with NOW, never who they were affiliated with on the day of a transfer, so a
 * standing resolved today cannot honestly be re-applied to a two-year-old
 * donation. Freezing the verdict with the date it was reached means a row always
 * says what HR actually knew when it looked, instead of silently rewriting its
 * own history every time an alliance changes hands or the standings list is
 * edited.
 */
class CreateDonationFlagsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_donation_flags')) {
            return;
        }

        Schema::create('hr_manager_donation_flags', function (Blueprint $table) {
            $table->bigIncrements('id');

            // The corp member's character on this side of the transfer.
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('corporation_id');

            // The journal row this came from. Paired with character_id it is
            // unique, matching the journal's own composite key, so a re-scan
            // updates rather than duplicating.
            $table->unsignedBigInteger('journal_id');

            $table->unsignedBigInteger('counterparty_id');
            $table->string('counterparty_type', 16)->nullable();  // character | corporation | alliance
            $table->string('counterparty_name', 128)->nullable();

            // Always positive; which way it went lives in direction, so
            // "how much moved" never depends on reading a sign correctly.
            $table->decimal('amount', 22, 2)->default(0);
            $table->string('direction', 8);   // in | out (from the member's side)

            $table->timestamp('occurred_at')->nullable();

            // --- The locked verdict -------------------------------------
            // standing is the resolved value at scan time; via_type / via_id
            // record WHICH rated entity supplied it, since a character is
            // usually rated through their corp or alliance rather than by name.
            $table->smallInteger('standing')->default(0);
            $table->string('standing_from', 16)->nullable();   // seat | own
            $table->string('via_type', 16)->nullable();        // character | corporation | alliance
            $table->unsignedBigInteger('via_id')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // neutral = the whole of history, a fact on the record.
            // suspect = dated after this human was already in the corp, which
            //           is the one that actually means something.
            $table->string('tier', 16)->default('neutral');

            $table->string('reason', 255)->nullable(); // journal reason / description

            $table->timestamps();

            $table->unique(['character_id', 'journal_id'], 'hr_donflag_journal_uniq');
            $table->index(['corporation_id', 'tier'], 'hr_donflag_corp_tier_idx');
            $table->index(['character_id', 'occurred_at'], 'hr_donflag_char_date_idx');
            $table->index('counterparty_id', 'hr_donflag_counterparty_idx');
        });
    }

    /** Forward-only: the released schema never drops tables. */
    public function down(): void
    {
        // no-op
    }
}

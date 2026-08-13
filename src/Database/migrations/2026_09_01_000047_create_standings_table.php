<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR's own standings, as NUMERIC values rather than two flat lists.
 *
 * The previous model stored four settings — hostile alliances, hostile corps,
 * friendly alliances, friendly corps — which could only ever answer "is this
 * entity bad, yes or no". SeAT's Standings Builder has carried a real value
 * per entity all along (-10 / -5 / 0 / +5 / +10) and HR was reading those rows
 * and throwing the number away.
 *
 * Keeping the value means the applicant assessment can say HOW hostile rather
 * than just "hostile", and lets HR act as a thin override layer over SeAT's
 * standings instead of a competing second list.
 */
class CreateStandingsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_standings')) {
            return;
        }

        Schema::create('hr_manager_standings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('entity_id');
            $table->string('entity_type', 16); // alliance | corporation | character

            // -10 terrible, -5 bad, 0 neutral, 5 good, 10 excellent. Signed:
            // MySQL would otherwise silently clamp negatives to zero, which is
            // the entire hostile half of the scale.
            $table->smallInteger('standing')->default(0);

            // Snapshot so the list still reads sensibly when a name can't be
            // resolved later (entity closed, ESI down).
            $table->string('entity_name', 128)->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('set_by')->nullable();

            $table->timestamps();

            // One standing per entity. Named explicitly: the auto-generated
            // name would be long, and the house rule is to never leave that
            // to chance on a prefixed table.
            $table->unique(['entity_type', 'entity_id'], 'hr_standings_entity_uniq');
            $table->index('standing', 'hr_standings_value_idx');
        });
    }

    /** Forward-only: the released schema never drops tables. */
    public function down(): void
    {
        // no-op
    }
}

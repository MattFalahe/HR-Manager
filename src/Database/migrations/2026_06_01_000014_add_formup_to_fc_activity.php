<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only: extend hr_manager_fc_activity to ALSO accumulate SeAT
 * Broadcast's `pings.formup.scheduled` events (an FC scheduling a fleet for
 * a tactical event) alongside `pings.broadcast.sent`. A `kind` discriminator
 * ('broadcast' | 'formup') separates the two; the tactical-context columns
 * are only populated for formup rows.
 *
 * Existing rows backfill to kind='broadcast' via the column default, so the
 * broadcast aggregates are unaffected. Same forward-only + idempotent
 * (event_id unique) contract as the original table.
 */
class AddFormupToFcActivity extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_fc_activity')) {
            return;
        }

        Schema::table('hr_manager_fc_activity', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_fc_activity', 'kind')) {
                $table->string('kind')->default('broadcast');
                $table->index(['user_id', 'kind']);
            }
            if (!Schema::hasColumn('hr_manager_fc_activity', 'category_group')) {
                $table->string('category_group')->nullable();
            }
            if (!Schema::hasColumn('hr_manager_fc_activity', 'severity')) {
                $table->string('severity')->nullable();
            }
            if (!Schema::hasColumn('hr_manager_fc_activity', 'structure_name')) {
                $table->string('structure_name')->nullable();
            }
            if (!Schema::hasColumn('hr_manager_fc_activity', 'system_name')) {
                $table->string('system_name')->nullable();
            }
            if (!Schema::hasColumn('hr_manager_fc_activity', 'scheduled_for')) {
                $table->timestamp('scheduled_for')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Forward-only; columns are additive + nullable. No-op.
    }
}

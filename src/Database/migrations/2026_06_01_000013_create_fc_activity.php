<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FC activity accumulation table. HR subscribes to SeAT Broadcast's
 * `pings.broadcast.sent` EventBus topic and records one row per
 * broadcast. All FC-activity aggregates (fleets led, coverage window,
 * cadence) are computed from THIS table — HR never reads Broadcast's
 * own tables at render time.
 *
 * Forward-only by design: rows accumulate from subscribe-time onward.
 * There is no backfill of historical pings (operator-chosen — purest
 * EventBus). A corp's FC profile therefore ramps up over the first
 * weeks after install.
 *
 * Idempotency: event_id (the publisher's per-publish UUID) is unique,
 * so an EventBus redelivery can't double-count a broadcast.
 */
class CreateFcActivity extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_fc_activity')) {
            return;
        }

        Schema::create('hr_manager_fc_activity', function (Blueprint $table) {
            $table->bigIncrements('id');
            // The FC = the SeAT user who sent the broadcast. character_id
            // is their resolved main, for portrait + member-profile
            // attribution (FC activity is human-level).
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('character_id')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->string('broadcast_type')->nullable();   // embed_type
            $table->string('mention_type')->nullable();
            // Automated structure-defense pings aren't FC fleet activity.
            // Recorded but flagged so aggregates can exclude them.
            $table->boolean('is_structure_alert')->default(false);
            $table->boolean('is_scheduled')->default(false);
            $table->string('event_id')->unique();           // dedup
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('user_id');
            $table->index('corporation_id');
            $table->index('occurred_at');
            $table->index(['user_id', 'is_structure_alert']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_fc_activity');
    }
}

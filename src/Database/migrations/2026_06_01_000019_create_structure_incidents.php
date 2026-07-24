<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure incident accumulation table. HR subscribes to Structure
 * Manager's `structure.alert.*` EventBus topics (shield/armor reinforced,
 * destroyed, fuel critical, etc.) and records one row per alert. The Corp
 * Health "Structure Health" card computes incident tallies (how many times
 * structures went into reinforcement / ran critically low on fuel / were
 * lost over a window) from THIS table; HR never reads Structure Manager's
 * own tables.
 *
 * Forward-only by design: rows accumulate from subscribe-time onward, no
 * backfill of historical alerts. A corp's incident history ramps up from
 * install.
 *
 * Needs Manager Core (the events ride its EventBus). Without MC the table
 * simply stays empty and the snapshot half of the card still works; nothing
 * here is a hard requirement for HR running standalone.
 *
 * Idempotency: event_id (the publisher's per-publish id via SM's
 * AlertEventEnvelope) is unique, so an EventBus redelivery or SM
 * re-detection can't double-count an incident.
 */
class CreateStructureIncidents extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_structure_incidents')) {
            return;
        }

        Schema::create('hr_manager_structure_incidents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id');
            // Nullable: system-wide alerts (e.g. AllAnchoringMsg) legitimately
            // lack a per-structure id.
            $table->unsignedBigInteger('structure_id')->nullable();
            $table->string('structure_name')->nullable();
            // The event suffix: shield_reinforced / armor_reinforced /
            // destroyed / fuel_critical / fuel_recovered / anchoring_started / etc.
            $table->string('incident_type', 40);
            $table->string('severity', 20)->nullable();   // critical / warning
            $table->string('event_id')->unique();          // dedup
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('corporation_id');
            $table->index('structure_id');
            $table->index('occurred_at');
            $table->index(['corporation_id', 'incident_type'], 'hr_struct_inc_corp_type_idx');
            $table->index(['corporation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_structure_incidents');
    }
}

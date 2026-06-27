<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyback contribution integration tables.
 *
 *   hr_manager_buyback_activity
 *     One row per Buyback Manager event HR consumes off the EventBus:
 *       - stage 'offer'     (buyback.offer.published)  — a member requested a
 *                            quote / published an offer. Engagement signal.
 *       - stage 'completed' (buyback.contract.completed) — the contract
 *                            finished; realized ISK contribution.
 *     character_id is the issuer (the contributing member). corporation_id is
 *     the corp running the buyback (the event's corporation_id). The offer
 *     stage also carries BB's richer target model (my_corp / corp / player +
 *     target_corporation_id) which informs the per-corp policy.
 *
 *   hr_manager_buyback_policies
 *     One row per buyback-running corp. The operator declares how that
 *     programme's contributions are valued: counted at all, tier (direct /
 *     community / personal), a scoring weight, and — for an alt/holding corp
 *     whose buyback supports the main corp — which corporation the contribution
 *     is credited to (attributed_corporation_id; null = self).
 *
 * Both tables are HR-owned. The integration is EventBus-first and self-hides
 * when Manager Core / Buyback Manager are absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_buyback_activity')) {
            Schema::create('hr_manager_buyback_activity', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('stage', 16);                          // offer | completed
                $table->unsignedBigInteger('character_id');           // issuer = contributor
                $table->unsignedBigInteger('corporation_id');         // corp running the buyback
                $table->string('target_type', 16)->nullable();        // my_corp | corp | player
                $table->unsignedBigInteger('target_corporation_id')->nullable();
                $table->unsignedBigInteger('target_character_id')->nullable();
                $table->string('mode', 16)->nullable();               // public | private (BB legacy/derived)
                $table->string('offer_public_id', 64)->nullable();    // links an offer to its completion
                $table->unsignedBigInteger('contract_id')->nullable();
                $table->decimal('total_value', 20, 2)->default(0);    // buyback ISK value
                $table->unsignedInteger('items_count')->default(0);
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();

                // Completions dedup on the EVE contract id (nullable unique:
                // MySQL allows many NULLs, so offer rows never collide here).
                $table->unique('contract_id');
                $table->index(['stage', 'offer_public_id']);          // offer dedup lookup
                $table->index(['character_id', 'stage']);
                $table->index(['corporation_id', 'stage']);
                $table->index('target_corporation_id');
                $table->index('occurred_at');
            });
        }

        if (!Schema::hasTable('hr_manager_buyback_policies')) {
            Schema::create('hr_manager_buyback_policies', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('corporation_id')->unique(); // buyback-running corp
                $table->boolean('counted')->default(true);
                $table->string('tier', 16)->default('community');       // direct | community | personal
                $table->decimal('weight', 5, 2)->default(1.00);
                $table->unsignedBigInteger('attributed_corporation_id')->nullable(); // null = self
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_buyback_activity');
        Schema::dropIfExists('hr_manager_buyback_policies');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-character alert-throttle state for the recurring CWM wallet signals
 * (tax compliance dropped / contributions stalled). CWM re-publishes the same
 * standing "dropped" level on every wallet-sync cycle; this table lets HR alert
 * once per episode — or on the operator's chosen repeat cadence — instead of on
 * every cycle.
 *
 *   last_seen_at     refreshed on EVERY event, so a long quiet gap reads as the
 *                    condition having recovered (a later drop is a new episode).
 *   last_notified_at stamped only when a Discord alert actually fires, so the
 *                    "repeat every N hours" cadence can be measured.
 *
 * One row per (corporation, character, event_type).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_wallet_alert_state')) {
            return;
        }

        Schema::create('hr_manager_wallet_alert_state', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('character_id');
            $table->string('event_type', 48);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            // Explicit short name — the auto name would overflow MySQL's 64-char
            // identifier limit on the hr_manager_ prefix.
            $table->unique(['corporation_id', 'character_id', 'event_type'], 'hr_wallet_alert_state_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_wallet_alert_state');
    }
};

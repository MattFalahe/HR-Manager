<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Player-status webhook category: fire a notification the moment a
 * director marks a player on LOA, marks them for purge, or clears their
 * status (LOA ended / purge cancelled). Distinct from notify_purge_reminder
 * (the T-7..T-0 countdown the cron fires as the scheduled purge nears) and
 * from notify_status_change (which is APPLICATION status, not player status).
 *
 * Defaults ON so the director audience that already receives purge reminders
 * also hears about the kick-off action without re-configuring every webhook.
 * Additive + guarded.
 */
class AddPlayerStatusWebhookCategory extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_webhook_configurations')) {
            return;
        }

        Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_player_status')) {
                $table->boolean('notify_player_status')->default(true)->after('notify_purge_reminder');
            }
        });
    }

    public function down(): void
    {
        // Leave the column in place on rollback (additive, harmless).
    }
}

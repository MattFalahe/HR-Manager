<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Flagged applicant" notification category.
 *
 * Fires when a new application registers a character that is on the blacklist or
 * flagged in the intel database (matched across the whole applying account, not
 * just the applied main). A security-grade alert so operators can route it to a
 * leadership channel, distinct from the routine application-submitted category.
 */
class AddFlaggedApplicantWebhookCategory extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_webhook_configurations')) {
            return;
        }

        if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_flagged_applicant')) {
            Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
                $table->boolean('notify_flagged_applicant')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_manager_webhook_configurations')
            && Schema::hasColumn('hr_manager_webhook_configurations', 'notify_flagged_applicant')) {
            Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
                $table->dropColumn('notify_flagged_applicant');
            });
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token-coverage digest webhook category: an opt-in PERIODIC summary of the
 * corp's token + scope health (valid / missing-scopes / lost / never-linked),
 * sent by hr-manager:token-coverage-digest.
 *
 * Distinct from notify_token_revoked (the per-event security alert): this is a
 * recurring report, so it defaults OFF — an operator opts in per webhook.
 * Additive + guarded.
 */
class AddTokenCoverageWebhookCategory extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_webhook_configurations')) {
            return;
        }

        Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_token_coverage')) {
                $table->boolean('notify_token_coverage')->default(false)->after('notify_token_revoked');
            }
        });
    }

    public function down(): void
    {
        // Leave the column in place on rollback (additive, harmless).
    }
}

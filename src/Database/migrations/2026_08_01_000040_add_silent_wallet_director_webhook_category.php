<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give "silent wallet director" its own webhook category.
 *
 * It used to ride notify_inactive_director and reuse that alert's message,
 * which claimed the director was "inactive for 0 days" — nonsense, since this
 * check is specifically for a director who IS still logging in but has no corp
 * wallet activity attributed to them. Splitting the category lets the two be
 * routed (and silenced) separately.
 *
 * Defaults to FALSE so an existing install doesn't start receiving a category
 * it never opted into; operators turn it on per webhook.
 */
class AddSilentWalletDirectorWebhookCategory extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_webhook_configurations')) {
            return;
        }
        if (Schema::hasColumn('hr_manager_webhook_configurations', 'notify_silent_wallet_director')) {
            return;
        }

        Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
            $table->boolean('notify_silent_wallet_director')
                ->default(false)
                ->after('notify_inactive_director');
        });
    }

    /**
     * Forward-only: dropping the column would lose an operator's routing choice
     * and the released schema never removes columns.
     */
    public function down(): void
    {
        // no-op
    }
}

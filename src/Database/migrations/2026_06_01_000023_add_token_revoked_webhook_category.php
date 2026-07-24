<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token-revoked webhook category: fire a security notification the moment HR
 * detects a tracked member's SeAT refresh token has gone (the member delinked
 * it, OR CCP permanently rejected it so SeAT soft-deleted it). Driven by
 * hr-manager:detect-token-loss + TokenLossService.
 *
 * Previously this alert had NO column of its own: it piggybacked on
 * notify_inactive_director / notify_wallet_compliance_dropped, so it was
 * undiscoverable in the webhook UI and silently off unless one of those was
 * enabled. Its own column makes it a first-class, independently-controllable
 * category.
 *
 * Defaults ON so the director / security audience hears about token loss
 * without re-touching every webhook. Additive + guarded.
 */
class AddTokenRevokedWebhookCategory extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_webhook_configurations')) {
            return;
        }

        Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_token_revoked')) {
                $table->boolean('notify_token_revoked')->default(true)->after('notify_player_status');
            }
        });
    }

    public function down(): void
    {
        // Leave the column in place on rollback (additive, harmless).
    }
}

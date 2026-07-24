<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Director-facing audit log — one append-only stream of who did (and viewed)
 * what in the plugin, and when. Populated by:
 *   - the AuditViewLogger middleware (every internal GET = a 'view' row), and
 *   - explicit AuditService::action() calls from the grant / revoke / decision /
 *     override / notification flows.
 *
 * Opt-in (Settings → Features, off by default); a daily prune keeps ~180 days.
 * All columns are denormalised snapshots so the audit page needs no joins and a
 * later name/role change never rewrites history.
 */
class CreateHrAuditLog extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_audit_log')) {
            return;
        }

        Schema::create('hr_manager_audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('actor_user_id')->nullable();       // SeAT user (null = system/cron)
            $table->unsignedBigInteger('actor_character_id')->nullable();  // actor's main, for portrait/name
            $table->string('actor_name')->nullable();                      // snapshot
            $table->string('action', 48);                                  // view / grant / revoke / status_change / ...
            $table->string('category', 24);                                // view / privilege / decision / security / notification / system
            $table->string('target_type', 48)->nullable();                 // application / player / member / dossier / intel / ...
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label')->nullable();                    // snapshot (character/app label)
            $table->string('summary', 512)->nullable();                    // human-readable one-liner
            $table->json('context')->nullable();                           // route, from/to, reason, permission set, …
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['actor_user_id', 'occurred_at'], 'hr_audit_actor_at_idx');
            $table->index(['target_type', 'target_id'], 'hr_audit_target_idx');
            $table->index('action', 'hr_audit_action_idx');
            $table->index('category', 'hr_audit_category_idx');
            $table->index('occurred_at', 'hr_audit_at_idx');
            $table->index('corporation_id', 'hr_audit_corp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_audit_log');
    }
}

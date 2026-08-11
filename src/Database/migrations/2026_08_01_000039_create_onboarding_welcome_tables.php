<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member onboarding welcome (opt-in). When a genuinely new person joins the
 * corp, HR queues a member-facing welcome message that fires after a delay (so
 * SeAT Connector has time to assign the Discord role that lets them see the
 * channel), @-mentioning the new member with a per-corp Markdown template.
 *
 * Additive only (HR is released): two new tables + one new webhook category
 * column, all guarded.
 *
 *   hr_manager_onboarding_welcomes  — the pending-send queue (one row per new
 *                                     person, fired once)
 *   hr_manager_onboarding_templates — per-corp custom welcome body + care-team
 *                                     @-mention roles (a hardcoded default is
 *                                     used when a corp has no row)
 *   notify_onboarding_welcome       — the webhook category the welcome routes to
 */
class CreateOnboardingWelcomeTables extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_onboarding_welcomes')) {
            Schema::create('hr_manager_onboarding_welcomes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('corporation_id');
                $table->unsignedBigInteger('user_id');       // the SeAT account
                $table->unsignedBigInteger('character_id');   // the joining character (portrait / name)
                $table->timestamp('send_after');
                $table->timestamp('sent_at')->nullable();     // fired-once marker
                $table->timestamps();

                $table->index(['sent_at', 'send_after'], 'hr_onboard_due_idx');
                $table->index(['corporation_id', 'user_id'], 'hr_onboard_acct_idx');
            });
        }

        if (!Schema::hasTable('hr_manager_onboarding_templates')) {
            Schema::create('hr_manager_onboarding_templates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('corporation_id')->unique();
                $table->text('body')->nullable();             // Markdown; {member} / {corp} vars
                $table->text('mention_role_ids')->nullable();  // JSON array of Discord role snowflakes (care team)
                $table->timestamps();
            });
        }

        if (Schema::hasTable('hr_manager_webhook_configurations')
            && !Schema::hasColumn('hr_manager_webhook_configurations', 'notify_onboarding_welcome')) {
            Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
                $table->boolean('notify_onboarding_welcome')->default(false)->after('notify_member_unregistered');
            });
        }
    }

    public function down(): void
    {
        // Additive, forward-only: leave the tables + column in place on rollback.
    }
}

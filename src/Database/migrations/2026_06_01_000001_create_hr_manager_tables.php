<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR Manager v1.0.0 — full schema in a single migration.
 *
 * Tables are created in FK-safe order so the foreign-key constraints below
 * can be declared inline. This is the only migration for v1.0.0; subsequent
 * releases append forward-only migrations per the project convention.
 *
 * Sixteen tables, organized:
 *   Configuration:        settings, webhook_configurations
 *   Form definition:      form_templates, form_template_questions
 *   Application pipeline: applications, application_answers,
 *                         application_status_history
 *   Notes:                notes (polymorphic application/member/player)
 *   Member assessment:    member_assessments
 *   Activity tiers:       role_tier_mappings
 *   Player state:         player_status, member_history_events,
 *                         player_classifications
 *   Purge workflow:       purge_reminders
 *   Recruitment site:     recruitment_landings, recruitment_views
 */
class CreateHrManagerTables extends Migration
{
    public function up(): void
    {
        // =================================================================
        // CONFIGURATION
        // =================================================================

        // Settings — global key-value store. Per-corp scoping was specced
        // but never used; dropped from v1.0.0 to simplify.
        Schema::create('hr_manager_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Webhook configurations — Discord + Slack only. The 'custom'
        // type was removed in v1.0.0 cleanup (it added SSRF complexity
        // without a real use case in the suite).
        Schema::create('hr_manager_webhook_configurations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->enum('type', ['discord', 'slack'])->default('discord');
            $table->text('webhook_url');
            $table->boolean('is_enabled')->default(true);

            // HR notification toggles
            $table->boolean('notify_application_submitted')->default(true);
            $table->boolean('notify_application_accepted')->default(true);
            $table->boolean('notify_application_rejected')->default(false);
            $table->boolean('notify_status_change')->default(true);
            $table->boolean('notify_inactive_director')->default(true);
            $table->boolean('notify_dead_weight')->default(false);
            $table->boolean('notify_purge_reminder')->default(true);

            // CWM wallet-signal notifications (Round-2 CWM integration)
            $table->boolean('notify_wallet_stalled')->default(false);
            $table->boolean('notify_wallet_compliance_dropped')->default(false);
            $table->boolean('notify_wallet_milestone')->default(false);

            // Platform-specific
            $table->string('discord_role_id')->nullable();
            $table->string('discord_username')->nullable();
            $table->string('slack_channel')->nullable();
            $table->string('slack_username')->nullable();

            // Delivery stats
            $table->integer('success_count')->default(0);
            $table->integer('failure_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();

            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->timestamps();

            $table->index('is_enabled');
            $table->index('type');
            $table->index('corporation_id');
        });

        // =================================================================
        // FORM DEFINITION
        // =================================================================

        // Form templates — corporation_id is REQUIRED in v1.0.0. Every
        // template belongs to a specific recruiting corporation. Drops the
        // global-template fallback that caused F3 (applications invisible
        // to recruiters) in the audit pass.
        Schema::create('hr_manager_form_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('corporation_id'); // NOT NULL
            $table->unsignedBigInteger('created_by')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['slug', 'corporation_id'], 'hr_templates_slug_corp_unique');
            $table->index('is_default');
            $table->index('is_active');
            $table->index('corporation_id');
        });

        Schema::create('hr_manager_form_template_questions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('template_id');
            $table->text('question_text');
            $table->enum('question_type', ['text', 'textarea', 'select', 'checkbox', 'radio', 'number', 'url'])->default('text');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('help_text')->nullable();
            $table->string('placeholder')->nullable();
            $table->string('validation_rules')->nullable();
            $table->timestamps();

            $table->index('template_id');
            $table->foreign('template_id')
                ->references('id')
                ->on('hr_manager_form_templates')
                ->onDelete('cascade');
        });

        // =================================================================
        // APPLICATION PIPELINE
        // =================================================================

        // Applications — `assigned_recruiter` column was specced but had
        // no UI surface; removed from v1.0.0 cleanup. Recruiters use notes
        // to communicate review ownership.
        Schema::create('hr_manager_applications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedBigInteger('corporation_id'); // NOT NULL per F3 fix
            $table->unsignedBigInteger('landing_id')->nullable(); // recruitment source attribution
            $table->enum('status', ['applied', 'under_review', 'interview', 'accepted', 'rejected', 'withdrawn'])->default('applied');
            $table->boolean('eligibility_passed')->default(true);
            $table->json('eligibility_failures')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('joined_corp_at')->nullable();    // set by hr-manager:detect-corp-joins
            $table->unsignedBigInteger('joined_corp_id')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->text('decision_notes')->nullable();
            // Public unguessable slug for the /recruit/track/{token}
            // applicant-facing progress page. No auth required.
            $table->string('tracking_token', 64)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['character_id', 'status']);
            $table->index('corporation_id');
            $table->index('landing_id');
            $table->index('template_id');
            $table->index('submitted_at');
            $table->foreign('template_id')
                ->references('id')
                ->on('hr_manager_form_templates')
                ->onDelete('set null');
        });

        Schema::create('hr_manager_application_answers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('question_id')->nullable();
            $table->text('question_text');
            $table->text('answer_text')->nullable();
            $table->timestamps();

            $table->index('application_id');
            $table->foreign('application_id')
                ->references('id')
                ->on('hr_manager_applications')
                ->onDelete('cascade');
            $table->foreign('question_id')
                ->references('id')
                ->on('hr_manager_form_template_questions')
                ->onDelete('set null');
        });

        Schema::create('hr_manager_application_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('application_id');
            $table->string('old_status', 50)->nullable();
            $table->string('new_status', 50);
            $table->unsignedBigInteger('changed_by');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('application_id');
            $table->index('changed_by');
            $table->foreign('application_id')
                ->references('id')
                ->on('hr_manager_applications')
                ->onDelete('cascade');
        });

        // =================================================================
        // NOTES (polymorphic across application/member/player)
        // =================================================================

        Schema::create('hr_manager_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('noteable_type', 50);
            $table->unsignedBigInteger('noteable_id');
            $table->unsignedBigInteger('author_id');
            $table->text('content');
            $table->boolean('is_private')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['noteable_type', 'noteable_id']);
            $table->index('author_id');
            $table->index('is_private');
        });

        // =================================================================
        // MEMBER ASSESSMENT
        // =================================================================

        Schema::create('hr_manager_member_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->decimal('total_mining_value', 20, 2)->default(0);
            $table->decimal('total_mining_tax', 20, 2)->default(0);
            $table->decimal('tax_compliance_pct', 5, 2)->default(0);
            $table->decimal('total_ratting_income', 20, 2)->default(0);
            $table->json('ore_preferences')->nullable();
            $table->integer('active_months')->default(0);
            $table->date('last_mining_date')->nullable();
            $table->date('last_ratting_date')->nullable();
            $table->decimal('security_status', 5, 2)->nullable();
            $table->unsignedBigInteger('total_sp')->nullable();
            $table->integer('employment_count')->default(0);
            $table->timestamp('member_since')->nullable();
            // CWM wallet aggregates folded into assessment cache (Round-2 CWM
            // integration). All nullable — populated only when MC + CWM are
            // installed and the bridge calls succeeded at build time.
            $table->decimal('lifetime_contribution', 20, 2)->nullable();
            $table->decimal('net_position_6mo', 20, 2)->nullable();
            $table->decimal('wallet_compliance_pct_6mo', 5, 2)->nullable();
            $table->timestamp('last_contribution_at')->nullable();
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'corporation_id'], 'hr_assessments_char_corp_unique');
            $table->index('corporation_id');
            $table->index('cached_at');
        });

        // =================================================================
        // ACTIVITY TIERS (Phase 1)
        // =================================================================

        Schema::create('hr_manager_role_tier_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id')->nullable(); // null = global mapping
            $table->string('discord_role_id', 32);
            $table->smallInteger('tier_level');
            $table->smallInteger('threshold_days')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['corporation_id', 'discord_role_id'], 'hr_tier_corp_role_unique');
            $table->index('tier_level');
            $table->index('corporation_id');
        });

        // =================================================================
        // PLAYER STATE (Phase 2 + 3 + 4)
        // =================================================================

        // Per-(user, corp) LOA / mark-for-purge flag
        Schema::create('hr_manager_player_status', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('corporation_id');
            $table->enum('status', ['active', 'loa', 'marked_for_purge'])->default('active');
            $table->date('loa_until')->nullable();
            $table->date('purge_scheduled_for')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('status_set_by')->nullable();
            $table->timestamp('status_set_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'corporation_id'], 'hr_player_status_user_corp_unique');
            $table->index('status');
            $table->index('loa_until');
            $table->index('purge_scheduled_for');
        });

        // Persisted history events (Phase 5). Powers timeline view + tax-history
        // subscriber (PINNED 2026-05-27 design). Idempotency key prevents double
        // insertion if MC's EventBus replays an event.
        Schema::create('hr_manager_member_history_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('character_id')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->string('event_type', 64);
            $table->string('source_plugin', 32)->default('hr-manager');
            $table->json('payload')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->unique('idempotency_key', 'hr_history_idempotency_unique');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['character_id', 'occurred_at']);
            $table->index('event_type');
            $table->index('corporation_id');
        });

        // Cached classifier output (Phase 3). Nightly cron writes; Corp Health
        // page reads. Transitions are published as `hr.player.flagged_*` events.
        Schema::create('hr_manager_player_classifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('corporation_id');
            $table->smallInteger('tier_level')->nullable();
            $table->enum('category', ['active', 'at_risk', 'inactive', 'dead_weight'])->default('active');
            $table->boolean('is_inactive_director')->default(false);
            $table->integer('days_inactive')->default(0);
            $table->smallInteger('threshold_days')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            // wallet_flags: JSON array of CWM wallet-signal flags that
            // contributed to the category decision (e.g. ["stalled",
            // "negative_contribution"]). Lets Corp Health render per-row
            // "why" badges without re-running the classifier.
            $table->json('wallet_flags')->nullable();
            $table->timestamp('classified_at');
            $table->timestamps();

            $table->unique(['user_id', 'corporation_id'], 'hr_classifications_user_corp_unique');
            $table->index('category');
            $table->index('is_inactive_director');
            $table->index('classified_at');
            $table->index('corporation_id');
        });

        // Dedup table for purge reminder dispatches (Phase 4). Cron-safe replays
        // skip already-sent milestones via the unique constraint.
        Schema::create('hr_manager_purge_reminders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('player_status_id');
            $table->string('milestone', 16); // t7 | t3 | t48 | t0 | executed
            $table->timestamp('dispatched_at');
            $table->timestamps();

            $table->unique(['player_status_id', 'milestone'], 'hr_purge_reminder_unique');
            $table->index('milestone');
            $table->foreign('player_status_id')
                ->references('id')
                ->on('hr_manager_player_status')
                ->onDelete('cascade');
        });

        // =================================================================
        // RECRUITMENT SITE
        // =================================================================

        // Per-corp public landing pages. URL: /recruit/{corp_ticker}/{slug}
        // unique by (corporation_id, slug) — corp ticker is derived from
        // SeAT's corporation_infos.ticker at render time so the URL is
        // human-friendly without operators having to manage tickers manually.
        Schema::create('hr_manager_recruitment_landings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('corporation_id');
            $table->string('slug', 96);
            $table->string('title');
            $table->string('headline')->nullable();
            $table->text('body_markdown')->nullable();
            $table->string('template_key', 32)->default('classic'); // classic | showcase | minimal | industrial
            $table->json('theme_json')->nullable();
            $table->string('hero_image_path')->nullable(); // relative to storage/app/public
            $table->unsignedBigInteger('default_template_id')->nullable();
            $table->enum('post_submission_mode', ['discord_invite', 'seat_connector', 'custom'])->default('seat_connector');
            $table->string('discord_invite_url', 2048)->nullable();
            $table->text('custom_confirmation_markdown')->nullable();
            // Always-visible "Next steps" notes, independent of
            // post_submission_mode. Lets directors add a free-form
            // Markdown message (status link, timeline, recruiter
            // contact) that renders alongside the mode-specific CTA.
            $table->text('next_steps_markdown')->nullable();
            $table->json('eligibility_rules_json')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('application_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['corporation_id', 'slug'], 'hr_landings_corp_slug_unique');
            $table->index('is_published');
            $table->index('corporation_id');
            $table->foreign('default_template_id')
                ->references('id')
                ->on('hr_manager_form_templates')
                ->onDelete('set null');
        });

        // Page-view analytics (best-effort, IP-hashed for privacy). Powers
        // the director-facing analytics dashboard. ip_hash is SHA-256 of
        // ip + secret so the same visitor counts as one unique without
        // storing the raw IP.
        Schema::create('hr_manager_recruitment_views', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('landing_id');
            $table->char('ip_hash', 64)->nullable();
            $table->string('referrer_domain', 191)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->boolean('clicked_apply')->default(false);
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->index(['landing_id', 'viewed_at']);
            $table->index('clicked_apply');
            $table->foreign('landing_id')
                ->references('id')
                ->on('hr_manager_recruitment_landings')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_recruitment_views');
        Schema::dropIfExists('hr_manager_recruitment_landings');
        Schema::dropIfExists('hr_manager_purge_reminders');
        Schema::dropIfExists('hr_manager_player_classifications');
        Schema::dropIfExists('hr_manager_member_history_events');
        Schema::dropIfExists('hr_manager_player_status');
        Schema::dropIfExists('hr_manager_role_tier_mappings');
        Schema::dropIfExists('hr_manager_member_assessments');
        Schema::dropIfExists('hr_manager_notes');
        Schema::dropIfExists('hr_manager_application_status_history');
        Schema::dropIfExists('hr_manager_application_answers');
        Schema::dropIfExists('hr_manager_applications');
        Schema::dropIfExists('hr_manager_form_template_questions');
        Schema::dropIfExists('hr_manager_form_templates');
        Schema::dropIfExists('hr_manager_webhook_configurations');
        Schema::dropIfExists('hr_manager_settings');
    }
}

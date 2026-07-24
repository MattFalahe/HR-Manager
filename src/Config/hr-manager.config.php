<?php

/*
|--------------------------------------------------------------------------
| HR Manager — Default Configuration
|--------------------------------------------------------------------------
|
| Defaults overridable via Settings UI (Setting model rows take precedence
| over these values at runtime).
|
*/

return [

    'general' => [
        'version'        => '1.0.0',
        'author'         => 'Matt Falahe',
        'corporation_id' => null,
    ],

    'applications' => [
        'auto_assign'               => false,
        'allow_withdrawal'          => true,
        // Ask the applicant for a reason when they self-withdraw, and
        // optionally make it mandatory. Both off by default so existing
        // installs keep the frictionless one-click withdrawal.
        'withdrawal_reason_enabled'  => false,
        'withdrawal_reason_required' => false,
        // Block moving an applicant to "accepted" while a HIGH-severity blacklist
        // entry is active on their account, unless a director overrides with a
        // written justification (logged + notified). On by default.
        'blacklist_accept_guard_enabled' => true,
        'stale_days'                => 7,
        'max_pending_per_character' => 1,
    ],

    'assessment' => [
        'cache_duration' => 60,
        'mining_months'  => 6,
        'ratting_months' => 6,
    ],

    'features' => [
        'enable_mining_data'         => true,
        'enable_ratting_data'        => true,
        'enable_employment_history'  => true,
        'enable_security_status'     => true,
        'enable_skill_points'        => true,
        'enable_webhooks'            => true,
        'enable_discord_notifications' => true,
        'enable_private_notes'       => true,
        // Optional in-game title/role alignment check across a player's alts.
        'enable_role_alignment'      => false,
        // Opt-in director activity/audit log — records who viewed and did what
        // in the plugin. Off by default; 180-day retention (a daily prune).
        'enable_audit_log'           => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance / background pre-warm
    |--------------------------------------------------------------------------
    |
    | Tuning knobs for the opt-in profile pre-warm (hr-manager:warm-player-profiles)
    | and the window the role classifier looks back over. All env-overridable so a
    | large / slow install can tune them without editing code.
    |
    | warm_max_inactive_days: the pre-warm skips accounts with no login in this
    |   many days (their profiles lazy-build on the rare view). Lower = faster on
    |   a big / old roster; the command's --include-dormant ignores it entirely.
    | warm_run_budget_seconds: how long ONE scheduled warm run works before it
    |   stops and saves its cursor, so a huge roster is warmed across cycles.
    | activity_window_months: how far back the role classifier looks for what a
    |   character is "used for". Shorter = cheaper cross-plugin queries, at some
    |   loss of fidelity for occasional ratters / miners.
    */
    'performance' => [
        'warm_max_inactive_days'  => (int) env('HR_WARM_MAX_INACTIVE_DAYS', 180),
        'warm_run_budget_seconds' => (int) env('HR_WARM_RUN_BUDGET_SECONDS', 1200),
        'activity_window_months'  => (int) env('HR_ACTIVITY_WINDOW_MONTHS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recruitment Site
    |--------------------------------------------------------------------------
    |
    | upload_disk: Laravel filesystem disk for hero image uploads. 'public'
    |   (default) serves via /storage/* — operators must run
    |   `php artisan storage:link` once. Switch to a private disk if you
    |   want auth-gated images.
    |
    | seat_connector_base_url: Optional. The base URL of the operator's SeAT
    |   install. Used to deeplink the post-submission "connect your Discord"
    |   CTA to {base_url}/seat-connector/identities. Leave empty to disable
    |   the deeplink. Can also be overridden per-install via the Settings UI.
    */
    'recruitment' => [
        'upload_disk'             => env('HR_RECRUIT_UPLOAD_DISK', 'public'),
        'seat_connector_base_url' => env('HR_RECRUIT_SEAT_CONNECTOR_URL', ''),
        // Set HR_RECRUIT_APPLY_SSO_DEBUG=true in your env to log every
        // RedirectAfterApplySso middleware invocation + every clickApply
        // session stash. Use ONLY while diagnosing the
        // /queue/short-status overshoot — leaves a log line per
        // request when on. Off by default.
        'apply_sso_debug'         => env('HR_RECRUIT_APPLY_SSO_DEBUG', false),
    ],

];

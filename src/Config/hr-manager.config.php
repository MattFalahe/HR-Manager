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

<?php

namespace HrManager\Services;

use HrManager\Models\Setting;

/**
 * Single source of truth for every notification TYPE HR can emit, grouped for
 * the consolidated Notifications console (Settings → Notifications).
 *
 * This is deliberately separate from the per-webhook category toggles: those
 * decide *which webhook* fires a category; the master switch here decides
 * whether the type fires AT ALL, on any channel. Off here = never sent,
 * regardless of webhook config. Default is on for every type, so an install
 * that never visits this tab behaves exactly as before.
 *
 * The `key` doubles as the webhook category suffix (notify_<key>) where one
 * exists, and always as the master-enable setting suffix (notif_enabled_<key>).
 */
class NotificationCatalog
{
    /** Setting-key prefix for the per-type master switch. */
    public const ENABLED_PREFIX = 'notif_enabled_';

    /**
     * Ordered groups → the type keys they contain. The console renders in this
     * order; every key must appear in types().
     */
    public static function groups(): array
    {
        return [
            'applications' => ['application_submitted', 'status_change', 'flagged_applicant', 'handler_note'],
            'membership'   => ['member_joined', 'member_left', 'join_no_application', 'member_unregistered'],
            'security'     => ['watchlist_detection', 'intel_match', 'token_revoked'],
            'health'       => ['inactive_director', 'silent_wallet_director', 'dead_weight', 'loa_marked', 'marked_for_purge', 'status_cleared'],
            'wallet'       => ['wallet_stalled', 'wallet_compliance_dropped', 'wallet_milestone'],
            'purge_tokens' => ['purge_reminder', 'token_coverage'],
        ];
    }

    /**
     * Per-type metadata:
     *   label / desc — lang keys (hr-manager::settings.*)
     *   cadence      — true when the type can re-fire and honours the recurring
     *                  wallet-style cadence control (the "how often" knob)
     *   default      — master-switch default (all true; a couple of noisy/opt-in
     *                  ones still default on but are the obvious first toggles)
     */
    public static function types(): array
    {
        return [
            // Applications
            'application_submitted'     => ['label' => 'notify_application_submitted',     'desc' => 'notif_desc_application_submitted',     'cadence' => false, 'fast_poll' => false],
            'status_change'             => ['label' => 'notify_status_change',             'desc' => 'notif_desc_status_change',             'cadence' => false, 'fast_poll' => false],
            'flagged_applicant'         => ['label' => 'notify_flagged_applicant',         'desc' => 'notif_desc_flagged_applicant',         'cadence' => false, 'fast_poll' => false],
            'handler_note'              => ['label' => 'notify_handler_note',              'desc' => 'notif_desc_handler_note',              'cadence' => false, 'fast_poll' => false],

            // Membership
            'member_joined'             => ['label' => 'notify_member_joined',             'desc' => 'notif_desc_member_joined',             'cadence' => false, 'fast_poll' => true],
            'member_left'               => ['label' => 'notify_member_left',               'desc' => 'notif_desc_member_left',               'cadence' => false, 'fast_poll' => true],
            'join_no_application'       => ['label' => 'notify_join_no_application',       'desc' => 'notif_desc_join_no_application',       'cadence' => false, 'fast_poll' => true],
            'member_unregistered'       => ['label' => 'notify_member_unregistered',       'desc' => 'notif_desc_member_unregistered',       'cadence' => false, 'fast_poll' => true],

            // Security
            'watchlist_detection'       => ['label' => 'notif_watchlist_detection',        'desc' => 'notif_desc_watchlist_detection',       'cadence' => false, 'fast_poll' => false],
            'intel_match'               => ['label' => 'notif_intel_match',                'desc' => 'notif_desc_intel_match',               'cadence' => false, 'fast_poll' => false],
            'token_revoked'             => ['label' => 'notify_token_revoked',             'desc' => 'notif_desc_token_revoked',             'cadence' => false, 'fast_poll' => false],

            // Corp health
            'inactive_director'         => ['label' => 'notify_inactive_director',         'desc' => 'notif_desc_inactive_director',         'cadence' => false, 'fast_poll' => false],
            'silent_wallet_director'    => ['label' => 'notify_silent_wallet_director',    'desc' => 'notif_desc_silent_wallet_director',    'cadence' => false, 'fast_poll' => false],
            'dead_weight'               => ['label' => 'notify_dead_weight',               'desc' => 'notif_desc_dead_weight',               'cadence' => false, 'fast_poll' => false],
            'loa_marked'                => ['label' => 'notify_loa_marked',                'desc' => 'notif_desc_loa_marked',                'cadence' => false, 'fast_poll' => false],
            'marked_for_purge'          => ['label' => 'notify_marked_for_purge',          'desc' => 'notif_desc_marked_for_purge',          'cadence' => false, 'fast_poll' => false],
            'status_cleared'            => ['label' => 'notify_status_cleared',            'desc' => 'notif_desc_status_cleared',            'cadence' => false, 'fast_poll' => false],

            // Wallet (these re-fire each sync cycle → cadence-controlled)
            'wallet_stalled'            => ['label' => 'notify_wallet_stalled',            'desc' => 'notif_desc_wallet_stalled',            'cadence' => true,  'fast_poll' => false],
            'wallet_compliance_dropped' => ['label' => 'notify_wallet_compliance_dropped', 'desc' => 'notif_desc_wallet_compliance_dropped', 'cadence' => true,  'fast_poll' => false],
            'wallet_milestone'          => ['label' => 'notify_wallet_milestone',          'desc' => 'notif_desc_wallet_milestone',          'cadence' => false, 'fast_poll' => false],

            // Purge & tokens
            'purge_reminder'            => ['label' => 'notify_purge_reminder',            'desc' => 'notif_desc_purge_reminder',            'cadence' => false, 'fast_poll' => false],
            'token_coverage'            => ['label' => 'notify_token_coverage',            'desc' => 'notif_desc_token_coverage',            'cadence' => false, 'fast_poll' => false],
        ];
    }

    /** All type keys, flat. */
    public static function keys(): array
    {
        return array_keys(self::types());
    }

    /** Master switch for a type. Unknown keys default to enabled (fail-open). */
    public static function isEnabled(string $key): bool
    {
        return (bool) Setting::getValue(self::ENABLED_PREFIX . $key, true);
    }
}

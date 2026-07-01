<?php

return [
    'title'             => 'Watchlist',
    'blacklist'         => 'Blacklist',
    'whitelist'         => 'Whitelist',

    // Page intro banner
    'intro_what_label'  => 'What this page does',
    'intro_what_body'   => 'A two-sided watchlist that surfaces during application review. <strong>Blacklist</strong> entries flag known troublemakers, spies, or bad-faith applicants — applications from them get a critical-tier banner. <strong>Whitelist</strong> entries flag former members who left on good terms — applications from them get a welcoming "previous member" banner so recruiters give them priority. Add characters by <strong>name OR character ID</strong>; the plugin resolves the other half via SeAT\'s cache and CCP ESI even when the character has never authed in SeAT.',
    'intro_visibility'  => 'Visible to recruiter+',
    'intro_when_label'  => 'When to use',
    'intro_when_1'      => 'Blacklist a spy / scammer / serial drama-starter so they get auto-flagged on future applications across the install',
    'intro_when_2'      => 'Whitelist a 5-year veteran who left amicably so any future application from them is highlighted as "welcome back" priority',
    'intro_when_3'      => 'Add entries by name when you only know the name — the plugin resolves to character_id via SeAT\'s cache or CCP ESI, then snapshots both',

    // Add form
    'add_to_blacklist'  => 'Add to Blacklist',
    'add_to_whitelist'  => 'Add to Whitelist',
    'add_entry'         => 'Add Entry',
    'input_label'       => 'Character name or ID',
    'input_placeholder' => 'e.g. "John Smith" or 90456792',
    'input_help'        => 'Type either a character name OR a numeric character ID. Both are accepted; the plugin resolves the other half.',
    'scope_label'       => 'Scope',
    'scope_global'      => 'Global (every corp you manage)',
    'scope_help'        => 'Choose a corp to scope the entry to that corp only. Pick global for shared cross-corp lists.',
    'severity_label'    => 'Severity',
    'severity_low'      => 'LOW — info only',
    'severity_medium'   => 'MEDIUM — warning',
    'severity_high'     => 'HIGH — critical, reject by default',
    'reason_label'      => 'Reason',
    'reason_placeholder' => 'Why is this character on the list? e.g. "Spy in Capital Conflict 2024", "5 year veteran, AFK for IRL"',
    'expires_label'     => 'Expires',
    'expires_help'      => 'Optional auto-removal date. Leave blank for permanent.',
    'never'             => 'Never',

    // Table columns
    'character_col'     => 'Character',
    'scope_col'         => 'Scope',
    'severity_col'      => 'Severity',
    'reason_col'        => 'Reason',
    'added_col'         => 'Added',
    'expires_col'       => 'Expires',
    'search_placeholder' => 'Search by name or ID...',
    'empty_blacklist'   => 'No blacklist entries. Add the first one above.',
    'empty_whitelist'   => 'No whitelist entries. Add the first one above.',

    // Flash messages
    'entry_added'       => 'Watchlist entry added.',
    'entry_added_with_hit' => 'Watchlist entry added. Heads up: this character is already inside a corp or alliance you watch. A detection alert was sent.',
    'entry_removed'     => 'Watchlist entry removed.',
    'entry_cleared'     => 'Watchlist entry cleared. Audit trail retained.',
    'confirm_remove'    => 'Clear this entry from the watchlist? The audit record stays.',
    'cleared_reason_label'   => 'Reason for clearing (required)',
    'cleared_reason_placeholder' => 'e.g. "Resolved misunderstanding 2026", "Was wrong person", "Rehabilitated"',

    // Alliance scope + policy flags
    'alliance_scope_label'   => 'Alliance scope (optional)',
    'alliance_scope_help'    => 'When set, the entry applies across every corp in this alliance. Every corp in the alliance sees the warning during application review.',
    'alliance_id_placeholder' => 'Alliance ID (numeric)',
    'alliance_scope'         => 'Alliance',
    'policy_heading'         => 'Notification policy',
    'notify_on_corp_match'   => 'Notify when char appears in a managed corp',
    'notify_on_alliance_match' => 'Notify when char appears in a managed alliance',
    'notify_on_external_change' => 'Poll public ESI for corp changes (warn even outside our reach)',

    // Cleared / history
    'cleared_history_heading' => '{1} :n cleared watchlist entry for this character|[2,*] :n cleared watchlist entries for this character',
    'was_listed'              => 'was listed',
    'cleared_by'              => 'Cleared by',
    'original_reason'         => 'Original reason',
    'alliance_scope'          => 'Alliance scope',
    'tab_cleared'             => 'Cleared (audit)',

    // Add failures
    'add_failed_empty_input'              => 'Please enter a character name or ID.',
    'add_failed_invalid_list_type'        => 'Invalid list type.',
    'add_failed_invalid_character_id_range' => 'That character ID is outside the valid EVE range.',
    'add_failed_name_length_out_of_range' => 'EVE character names are 3 to 37 characters long.',
    'add_failed_esi_unknown_name'         => 'Could not find a character with that name on the public ESI. Check spelling or use the character ID directly.',
    'add_failed_esi_failed'               => 'Could not reach the ESI to resolve the name. Try again, or paste the character ID directly.',
    'add_failed_resolution_failed'        => 'Could not resolve that input to a character.',
    'add_failed_unknown'                  => 'Could not add that entry. Check your input and try again.',

    // Application detail banner (used by ApplicationController integration)
    'app_match_blacklist_heading' => 'BLACKLIST MATCH — this applicant is on your blacklist',
    'app_match_whitelist_heading' => 'WHITELIST MATCH — welcome back, previous member',
    'app_match_added_by'          => 'Added by',
    'app_match_added_at'          => 'on',
    'app_match_applying_char'     => 'Applying character',
    'app_match_alt'               => 'Linked alt',
    'app_match_also_flagged'      => 'Also flagged on this account',
];

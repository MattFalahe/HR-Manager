<?php

return [
    'title' => 'Activity Log',

    // Disabled / discovery state
    'disabled_title'      => 'Activity log is off',
    'disabled_body'       => 'When enabled, HR Manager records who viewed and did what in the plugin — applications opened, privileges granted or revoked, decisions, blacklist overrides and system actions — so directors have an accountable trail. Nothing is recorded until you turn it on.',
    'disabled_cta'        => 'Enable in Settings',
    'disabled_admin_note' => 'Ask an administrator to enable the activity log in Settings → Features.',

    // Intro banner
    'intro_what_label'    => 'What this is',
    'intro_what_body'     => 'A director-only, append-only trail of activity inside HR Manager: navigation, privilege changes, application decisions, overrides and notifications — each with who, what and when.',
    'intro_visibility'    => 'Directors only',
    'intro_retention_label' => 'Retention',
    'intro_retention_1'   => 'Entries are kept for :days days, then pruned automatically.',
    'intro_retention_2'   => 'The log is append-only — entries are never edited, only aged out.',

    // Filters
    'filter_category' => 'Category',
    'filter_action'   => 'Action',
    'filter_actor'    => 'Actor',
    'filter_from'     => 'From',
    'filter_to'       => 'To',
    'filter_all'      => 'All',
    'filter_search'   => 'Search summary, target or actor…',
    'filter_reset'    => 'Reset',
    'export_csv'      => 'Export CSV',

    // Table
    'log_title'     => 'Activity',
    'total_count'   => ':count entries',
    'empty'         => 'No activity recorded for these filters.',
    'col_when'      => 'When',
    'col_actor'     => 'Actor',
    'col_category'  => 'Category',
    'col_activity'  => 'Activity',

    // Category labels
    'cat_view'         => 'View',
    'cat_privilege'    => 'Privilege',
    'cat_decision'     => 'Decision',
    'cat_security'     => 'Security',
    'cat_notification' => 'Notification',
    'cat_management'   => 'Management',
    'cat_system'       => 'System',
];

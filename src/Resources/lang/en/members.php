<?php

return [
    'members'            => 'Members',
    'member_profile'     => 'Member Profile',
    'character'          => 'Character',
    'corporation'        => 'Corporation',
    'alliance'           => 'Alliance',
    'security_status'    => 'Security Status',
    'skill_points'       => 'Skill Points',
    'member_since'       => 'Member Since',
    'no_members'         => 'No members found.',

    // Assessment
    'mining_stats'       => 'Mining Statistics',
    'ratting_stats'      => 'Ratting Statistics',
    'tax_compliance'     => 'Tax Compliance',
    'total_mining_value' => 'Total Mining Value',
    'total_mining_tax'   => 'Total Mining Tax',
    'total_ratting_income' => 'Total Ratting Income',
    'compliance_rate'    => 'Compliance Rate',
    'ore_preferences'    => 'Ore Preferences',
    'active_months'      => 'Active Months',
    'last_mining'        => 'Last Mining Activity',
    'last_ratting'       => 'Last Ratting Activity',
    'employment_history' => 'Employment History',
    'employment_count'   => 'Previous Corporations',
    'refresh_data'       => 'Refresh Data',
    'data_cached_at'     => 'Data cached at',
    'data_unavailable'   => 'Data unavailable (plugin not installed)',
    'no_data'            => 'No data available for this period.',

    // Members index — corp picker, search, headline counts, registration column
    'corporation_context' => 'Corporation',
    'search_placeholder'  => 'Search character name...',
    'search'              => 'Search',
    'total_chars'         => 'total characters',
    'registered_chars'    => 'registered in SeAT',
    'unregistered_chars'  => 'unregistered',
    'registration'        => 'Registered',
    'registered'          => 'Registered',
    'registered_help'     => 'Character has a SeAT refresh token (registered with this install).',
    'unregistered'        => 'Unregistered',
    'unregistered_help'   => 'Character visible to ESI corp-member tracking but has no SeAT refresh token.',
    'roster_partial_heading' => 'Showing partial roster',
    'roster_partial_body'    => 'SeAT has not synced the full corporation member list for this corp yet. Add an ESI refresh token from a Director character with the read_corporation_membership scope under Seat → Settings → API → Refresh tokens to see every member (registered or not).',

    // Page intro banner ("What this page does / When to use")
    'intro_what_label' => 'What this page does',
    'intro_what_body'  => 'Lists every <strong>character</strong> in the corporation — one row per character, including unregistered alts. Names fall back through character_infos &rarr; universe_names cache &rarr; character ID so even pre-registration alts show real names when SeAT has resolved them. Click a row to see the character\'s assessment data, wallet activity, in-game titles + roles, recent PvP, and notes. <strong>See also</strong> the Players page for the human-level rollup.',
    'intro_visibility' => 'Visible to recruiter+',
    'intro_when_label' => 'When to use',
    'intro_when_1'     => 'Looking up a specific character\'s standing, in-game titles, or roles for a corp management task',
    'intro_when_2'     => 'Auditing the corp roster against in-game tools or chasing unregistered alts to register them',
    'intro_when_3'     => 'Refreshing a single character\'s cross-plugin assessment data (mining / ratting / wallet) on demand',

    // Member profile — Quick Info additions
    'seat_user'           => 'SeAT user',
    'discord_user'        => 'Discord',
    'discord_id'          => 'Discord ID',
    'discord_roles_heading' => 'Discord roles',

    // Member profile — Recent PvP card (zKillboard)
    'pvp_heading'         => 'Recent PvP',
    'pvp_source'          => 'via zKillboard',
    'pvp_unavailable'     => 'zKillboard unreachable (network) or character has no public record.',
    'pvp_no_history'      => 'No PvP history found on zKillboard.',
    'pvp_kills'           => 'kills',
    'pvp_losses'          => 'losses',
    'pvp_isk_destroyed'   => 'ISK destroyed',
    'pvp_isk_lost'        => 'ISK lost',
    'pvp_danger'          => 'danger ratio',
    'pvp_open_zkill'      => 'Open on zKillboard',

    // In-game titles + corp roles panel
    'titles_heading'      => 'In-game titles & roles',
    'titles_subheading'   => 'Titles held in corp',
    'roles_subheading'    => 'Direct corp roles',
    'role_high_impact'    => 'High-impact role — must be stripped before kicking the character to avoid the 24h cooldown leak.',

    // Unregistered character warning (top of member profile when no
    // refresh token exists for this character)
    'unregistered_warning_heading' => 'This character is not registered with SeAT',
    'unregistered_warning_body'    => 'Without a SeAT refresh token, we have no visibility into their wallet, mining activity, ratting income, Discord identity, or assessment data. The character can still be tracked via the corp\'s director ESI scope (login time, in-game roles) but anything character-scoped is missing.',
    'unregistered_warning_action'  => 'Chase them to register at /auth/login, or consider kicking from the corporation. Unregistered alts in a corp are a security and compliance gap.',

    // Player Identity header link
    'player_identity_link'  => 'Player: :name',
    'player_identity_label' => 'Player',
    'player_identity_help'  => 'Open the persistent Player Identity profile — shows every character this human has owned, current and historical.',

    // Wallet Audit panel (director-only)
    'audit_heading'           => 'Wallet Audit',
    'audit_subtitle'          => 'Income / expense / fraud signals from CWM + MM',
    'audit_director_only_help' => 'Audit data is fraud-detection sensitive and not shown to recruiters.',
    'director_only_label'     => 'Director only',
    'audit_compliance'        => 'Tax compliance',
    'audit_income_tax_ratio'  => 'Income/tax ratio',
    'audit_income_tax_help'   => 'Paid tax divided by combined income. Low ratios on active members are suspicious.',
    'audit_net_position'      => 'Net position (6mo)',
    'audit_net_position_help' => 'Contributed minus withdrawn over last 6 months.',
    'audit_flags'             => 'Active flags',
    'audit_no_flags'          => 'No flags raised',

    'audit_income_profile'    => 'Income profile',
    'audit_contributed_lifetime' => 'Lifetime contributed',
    'audit_ratting_6mo'       => 'Ratting income (6mo)',
    'audit_mining_6mo'        => 'Mining value (6mo)',
    'audit_total_income'      => 'Combined income',
    'audit_top_sources'       => 'Top income categories',

    'audit_expense_profile'   => 'Expense profile',
    'audit_withdrawn_lifetime' => 'Lifetime withdrawn',
    'audit_net_position_6mo'  => 'Net 6mo (contributed - withdrawn)',
    'audit_recent_withdrawals' => 'Recent withdrawals',

    // Mining detail (favourite ores + systems + event attendance via MM)
    'mining_detail_heading'    => 'Mining detail',
    'mining_detail_window'     => 'last 6 months',
    'mining_top_ores'          => 'Favourite ores',
    'mining_top_systems'       => 'Favourite systems',
    'mining_detail_footnote'   => 'Ranked by total units mined over the window (ores) and number of mining operations (systems), from the Mining Manager ledger.',
    'mining_attendance_rate'   => 'op attendance',
    'mining_attendance_summary' => 'Attended :attended of the corp\'s :total scheduled mining ops in the last 6 months.',

    // Ratting detail subsection (income source + monthly trend)
    'audit_ratting_detail_heading' => 'Ratting detail',
    'audit_ratting_source'   => 'Income by source',
    'audit_ratting_trend'    => 'Monthly trend (6mo)',
    'audit_ratting_caveat'   => 'Source = wallet ref-type (bounties vs agent missions), NOT site type. EVE\'s wallet journal does not record whether a bounty came from a Sanctum, Haven, or belt — that granularity does not exist outside killmails.',

    'audit_interpretation_label' => 'How to read this panel',
    'audit_tip_compliance' => '<strong>Tax compliance</strong> &lt; 50% on an active member is a fraud signal. Cross-check their reported activity (mining / ratting) against actual income below.',
    'audit_tip_ratio'      => '<strong>Income/tax ratio</strong> &lt; 5% on an active member suggests they are earning ISK without paying tax. Check categories: is income flowing through channels you tax?',
    'audit_tip_net'        => '<strong>Net position</strong> negative + high withdrawals + low contributions = corp wallet is funding them. Check recent withdrawals for unusual recipients.',
    'audit_tip_flags'      => '<strong>NEG</strong> = net negative, <strong>SWD</strong> = silent wallet director, <strong>VTX</strong> = compliance under 30%. Three or more flags = escalate.',
];

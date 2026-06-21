<?php

return [
    'help_documentation' => 'HR Manager - Help & Documentation',
    'navigation'         => 'Navigation',

    // Sidebar labels
    'overview'           => 'Overview',
    'getting_started'    => 'Getting Started',
    'features'           => 'Features',
    'recruitment_site'   => 'Recruitment Site',
    'applications'       => 'Applications',
    'activity_tiers'     => 'Activity Tiers',
    'corp_health'        => 'Corp Health',
    'purge_workflow'     => 'Purge Workflow',
    'players_members'    => 'Players & Members',
    'notes'              => 'Notes',
    'notifications'      => 'Notifications',
    'integrations'       => 'Integrations',
    'permissions'        => 'Permissions',
    'commands'           => 'Commands',
    'faq'                => 'FAQ',

    // Legacy alias kept for older blade refs
    'templates'          => 'Form Templates',
    'member_profiles'    => 'Member Profiles',

    // Misc
    'search_placeholder' => 'Search help...',
    'tip_label'          => 'Tip',

    // ============================================================
    // OVERVIEW
    // ============================================================
    'plugin_info_title' => 'Plugin Information',
    'author'            => 'Author',
    'github_repo'       => 'GitHub Repository',
    'changelog'         => 'Full Changelog',
    'report_issues'     => 'Report Issues',
    'readme'            => 'README',
    'discord'           => 'SeAT Discord',
    'support_project'   => 'Support the Project',

    // Version Status card
    'version_status_title'  => 'Version Status',
    'installed'             => 'Installed',
    'latest_release'        => 'Latest release',
    'unknown'               => 'unknown',
    'view_release_notes'    => 'View release notes',
    'upgrade_recipe'        => 'Upgrade recipe (SeAT Docker stack)',
    'upgrade_recipe_note'   => 'Container boot pulls the latest plugin via Composer, runs new migrations, and re-seeds schedules automatically.',
    'version_check_note'    => 'Installed version resolved via Composer when available, falling back to hr-manager.config.php. Latest checked via Packagist (6h cache, safe on outages).',

    // Quick Links + Key Features cards
    'quick_links_title'     => 'Quick Links',
    'key_features_title'    => 'Key Features',
    'feat_public_tracking'      => 'Public tracking link',
    'feat_public_tracking_desc' => 'Applicants bookmark a private URL to check status without logging in.',
    'feat_multi_handler'        => 'Multi-handler tracking',
    'feat_multi_handler_desc'   => 'Multiple recruiters can collaborate on the same application with role labels.',
    'feat_titles_roles'         => 'In-game titles + roles',
    'feat_titles_roles_desc'    => 'Corp titles + direct roles surfaced per character with high-impact callouts.',
    'support_list'      => '<ul style="margin-top: 10px; margin-bottom: 0;">
        <li>⭐ Star the GitHub repository</li>
        <li>🐛 Report bugs and issues</li>
        <li>💡 Suggest new features</li>
        <li>🔧 Contribute code improvements</li>
        <li>🌟 Share with other SeAT users</li>
    </ul>',

    'welcome_title' => 'Welcome to HR Manager — Two Faces',
    'welcome_desc'  => 'The new era of recruitment for SeAT v5. A public recruitment funnel for applicants, a director-side assessment console for leadership — both faces of the same plugin, sharing the same data model.',

    'what_is_title' => 'What is HR Manager?',
    'what_is_desc'  => 'HR Manager is a two-sided recruitment and member-management plugin for EVE Online corporations on SeAT v5. The <strong>Recruiter Face</strong> (public landing pages, customizable forms, eligibility gates, IP-hashed analytics, public tracking link) brings prospects in. The <strong>Director Face</strong> (player-centric view, Corp Health classifier, purge workflow with 24h cooldown warnings, history timeline) keeps them once they\'re here. The plugin runs standalone and lights up extra signals when sibling plugins (Manager Core, Mining Manager, Corp Wallet Manager, SeAT Connector) are installed.',

    'key_benefit'      => 'Key benefit',
    'key_benefit_desc' => 'Recruiting and retention live in the same tool. You see the entire history of every human — from first landing-page visit through every alt, status change, and note — without leaving SeAT.',

    // Two Faces is the plugin's branding — public recruitment funnel +
    // director assessment console. Promoted to the headline card on the
    // Help Overview tab with a gradient-framed hero treatment.
    'brand_tagline'        => 'HR Manager · The new era of recruitment',
    'two_faces_title'      => 'Two Faces',
    'two_faces_intro'      => 'Most HR plugins solve half the problem. HR Manager treats recruitment and retention as <strong>one closed loop</strong>: a public funnel that brings prospects in, a director console that keeps them once they\'re here. Same data, two faces, one promise.',
    'face_recruiter_title' => 'The Recruiter Face',
    'face_recruiter_body'  => 'What your prospects see, without a SeAT account. Public landing pages at <code>/recruit/{ticker}/{slug}</code>, four visual templates, an eligibility engine that gates weak applications, three post-submission modes, IP-hashed analytics, and a public tracking page applicants can bookmark to check status.',
    'face_director_title'  => 'The Director Face',
    'face_director_body'   => 'What leadership sees while doing the actual work. Player-centric view (one row per human), Corp Health classifier, purge workflow with 24-hour cooldown warnings, in-game titles + roles surfacing, history timeline, wallet signals via Corp Wallet Manager when installed.',

    'brand_motto_part_1'   => 'Recruit',
    'brand_motto_part_2'   => 'Assess',
    'brand_motto_part_3'   => 'Retain',

    // ============================================================
    // GETTING STARTED
    // ============================================================
    'getting_started_title' => 'Getting Started',
    'getting_started_intro' => 'After installing the package and waiting for SeAT to auto-run migrations, you have an empty plugin. Follow these steps to bring it online for your corp.',

    'first_run_title'         => 'First-run checklist',
    'step_template_title'     => 'Create at least one form template',
    'step_template_body'      => 'Go to <strong>Templates</strong> and create a recruitment form. Tie it to your corp so applications are routed to your recruiters.',
    'step_landing_title'      => 'Create a recruitment landing',
    'step_landing_body'       => 'Open <strong>Recruitment → Landings</strong>, pick a visual template, set eligibility (sec status, SP, age, blacklists, etc.), and publish. The public URL becomes <code>/recruit/{ticker}/{slug}</code>.',
    'step_tiers_title'        => 'Map your activity tiers',
    'step_tiers_body'         => 'Go to <strong>Tiers</strong> and pick the default thresholds for L0 / L1 / L2 / L3, or override them per corp. These thresholds drive Corp Health classification.',
    'step_webhooks_title'     => 'Wire up Discord webhooks',
    'step_webhooks_body'      => 'In <strong>Settings → Webhooks</strong>, add Discord webhook URLs and pick which categories fire to each one. Use the Pick role button if you have Discord role data from SeAT Broadcast or SeAT Connector.',
    'step_director_token_title' => 'Add a director ESI token',
    'step_director_token_body'  => 'A character with the <code>Director</code> in-game role lets HR Manager pull corp member tracking, wallet, and role-history data. Without it the plugin still works but loses several signals (last login dates, recent logoff, wallet-flag depth).',

    'getting_started_tip' => 'You do not have to wire up Manager Core, Mining Manager, or Corp Wallet Manager. HR Manager runs standalone and adds extra signals when they are installed.',

    // ============================================================
    // FEATURES — detailed prose, not tooltip teasers
    // ============================================================
    'features_title' => 'Feature Highlights',
    'features_intro' => 'A walk-through of every feature in HR Manager, organized into ten groups. Each group covers what the feature does, its key capabilities, where to find it in the UI, any heads-up gotchas, and related cross-references. Use the sidebar nav to jump between sections when you only need one.',

    'feat_capabilities'   => 'Key capabilities',
    'feat_where_label'    => 'Where to find it',
    'feat_heads_up_label' => 'Heads up',

    // Legacy keys for the now-replaced feature-item grid (Key Features
    // card on the Overview still uses them).
    'feat_public_landings'      => 'Public landing pages',
    'feat_public_landings_desc' => 'Four visual templates, per-corp ticker URLs, IP-hashed analytics, eligibility gates.',
    'feat_eligibility'          => 'Eligibility engine',
    'feat_eligibility_desc'     => 'Sec status / SP / age / blacklist / whitelist gates with manual-review escape hatch.',
    'feat_tiers'                => 'Activity tiers',
    'feat_tiers_desc'           => 'L-1 Applicant through L3 Director with configurable per-corp thresholds.',
    'feat_corp_health'          => 'Corp Health classifier',
    'feat_corp_health_desc'     => 'Active / at risk / inactive / dead weight buckets with inactive director alerts.',
    'feat_purge'                => 'Purge workflow',
    'feat_purge_desc'           => 'T-7 / T-3 / T-48 / T-0 reminder ladder, blinking 24h cooldown warning.',
    'feat_history'              => 'History timeline',
    'feat_history_desc'         => 'Status changes, purges, notes, milestones, wallet events — survives corp moves.',
    'feat_wallet'               => 'Wallet signals',
    'feat_wallet_desc'          => 'Stalled / negative / tax / loyalty / drop flags from CWM when installed.',
    'feat_discord'              => 'Discord identity',
    'feat_discord_desc'         => 'Discord username + role pull via SeAT Connector on every member profile.',
    'feat_pvp'                  => 'Recent PvP',
    'feat_pvp_desc'             => 'zKillboard summary card on each member profile (30-min cache).',
    'feat_notifications'        => 'Group notifications',
    'feat_notifications_desc'   => 'Per-category Discord routing with role mention support.',

    // 1. Recruitment funnel
    'feat_recruitment_funnel'      => 'Recruitment Funnel',
    'feat_recruitment_funnel_desc' => 'The public-facing half of HR Manager. Prospective members never need a SeAT account: they hit a landing page, submit an application, get a bookmarkable tracking link, and can come back to check status without ever logging in. Eligibility gates filter weak applications before they reach you, and IP-hashed analytics tell you which landings perform.',
    'feat_rf_cap_1' => '<strong>Four visual templates</strong> — Classic (hero + body + CTA), Showcase (image-heavy), Minimal (text-only, fast-loading), Industrial (utility-style). Pick per landing.',
    'feat_rf_cap_2' => '<strong>Per-corp ticker URLs</strong> in the form <code>/recruit/{ticker}/{slug}</code>. Multiple landings per corp allowed (e.g. one for PvP recruits, one for industrial).',
    'feat_rf_cap_3' => '<strong>Eligibility engine</strong>: security status min/max, total SP minimum, character age in days, blacklist / whitelist corps and alliances, SeAT Connector requirement. Applicants who fail can still submit via a manual-review escape hatch — the recruiter sees which checks failed.',
    'feat_rf_cap_4' => '<strong>Three post-submission modes</strong>: show Discord invite, hand off to SeAT Connector for auto-roling, or render custom Markdown. Pair any of them with the always-visible <em>Next steps notes</em> field for free-form director-authored messages.',
    'feat_rf_cap_5' => '<strong>IP-hashed analytics</strong> dashboard per landing: visits, conversion to apply-click, conversion to submission, top referrers. No raw IPs stored; analytics survive privacy audits.',
    'feat_rf_where' => 'Sidebar &rarr; <strong>Recruitment Pages</strong> for landing CRUD. Sidebar &rarr; <strong>Templates</strong> for the application forms each landing uses. The public URL is shown at the top of every landing edit page.',

    // 2. Application workflow
    'feat_app_workflow'      => 'Application Workflow',
    'feat_app_workflow_desc' => 'Submitted applications enter a six-state lifecycle (Applied &rarr; Under Review &rarr; Interview &rarr; Accepted / Rejected / Withdrawn) with a full audit trail. Multi-handler tracking lets several recruiters collaborate on one application, re-applicant detection surfaces prior history, and watchlist matches flag known good or bad actors on arrival.',
    'feat_aw_cap_1' => '<strong>Status state machine</strong> with role-gated transitions. Recruiters can move to Under Review / Interview; directors can Accept / Reject. Every transition is logged with actor, timestamp, and optional comment.',
    'feat_aw_cap_2' => '<strong>Multi-handler tracking</strong>: any recruiter can <em>Join as handler</em> on an application. Auto-tracks anyone who changes status. Optional role labels ("Reviewer", "Interviewer", "Background check"). The applications index shows handler avatars + a green check if you\'re one.',
    'feat_aw_cap_3' => '<strong>Re-applicant prior history</strong>: when an applicant has a previously accepted application to the same corp, a card surfaces above the answers showing prior accepted apps + lifetime contribution + percentile rank via CWM (when installed). Helps recruiters judge "should we accept this person back".',
    'feat_aw_cap_4' => '<strong>Watchlist banners</strong> at the top of the detail page: red for blacklist match, green for whitelist match. Carries the reason, severity, and added-by info inline so the recruiter sees it before reading any answers.',
    'feat_aw_cap_5' => '<strong>URL auto-linkification</strong> in answers: when applicants paste zKill / Discord / forum URLs into text or textarea answers, they render as clickable links automatically. Same goes for the public tracking page.',
    'feat_aw_where' => 'Sidebar &rarr; <strong>Applications</strong> for the index + detail view. Joining as handler is a button on the detail-page sidebar.',

    // 3. Members & Players views
    'feat_member_player_views'      => 'Members & Players Views',
    'feat_member_player_views_desc' => 'Two complementary views of your corp roster. Members is per-character (one row per character including unregistered alts); Players is per-human (one row per SeAT user, aggregating their alts). Both are gated to <strong>director</strong> access because the profiles expose sensitive per-character ESI and assessment data; the page intro banners on each explain when to use which.',
    'feat_mpv_members_heading' => 'Members — per-character roster',
    'feat_mpv_members_body'    => 'Reads SeAT\'s authoritative <code>corporation_members</code> table (populated by a director-scoped <code>read_corporation_membership.v1</code> ESI token) so it shows EVERY character in the corp, registered or not. Names fall back through <code>character_infos</code> &rarr; <code>universe_names</code> cache &rarr; <code>"Character #N"</code> so even unregistered alts show real names when SeAT has resolved them. Each row links to a detail page with assessment data, wallet activity, in-game titles + roles, recent PvP, Discord identity, and notes.',
    'feat_mpv_players_heading' => 'Players — per-human rollup',
    'feat_mpv_players_body'    => 'One row per SeAT user account, aggregating every linked character. Tier resolved from the highest-tier registered alt; activity, wallet flags, and Corp Health verdicts roll up across the whole person. The detail page shows the alt grid (each alt with in-corp status + tier + activity flag), history timeline, and director-tier actions (LOA, mark for purge, clear status). Use this for HR decisions about a person rather than one alt at a time.',
    'feat_mpv_titles_heading' => 'In-game titles + roles surfacing',
    'feat_mpv_titles_body'    => 'When SeAT has director-scope sync data, member and player profiles show the in-game corp titles and direct corporation roles each character holds. High-impact roles (Director / Personnel Manager / Accountant / Junior Accountant / Diplomat / Security Officer) get a red warning tint and an exclamation icon so they stand out for the purge role-strip checklist.',
    'feat_mpv_where' => 'Sidebar &rarr; <strong>Members</strong> (per-character). Sidebar &rarr; <strong>Players</strong> (per-human). Both pages have a corporation picker dropdown that defaults to your main\'s corp.',

    // 4. Corp Health & classification
    'feat_corp_health_section'      => 'Corp Health & Classification',
    'feat_corp_health_section_desc' => 'A director-tier dashboard that classifies every player in your corp nightly. Activity tiers define what "active" means at each level of responsibility; the classifier buckets players into Active / At Risk / Inactive / Dead Weight; wallet signals from Corp Wallet Manager (when installed) sharpen the verdict with contribution and tax data.',
    'feat_ch_tiers_heading' => 'Activity tiers (L-1 through L3)',
    'feat_ch_tiers_body'    => 'Five tiers map players to responsibility levels: <strong>L-1 Applicant</strong> (excluded from classification), <strong>L0 Member</strong>, <strong>L1 Junior Officer</strong>, <strong>L2 Senior Officer</strong>, <strong>L3 Director</strong>. Each tier has an activity threshold in days; cross it and the classifier downgrades the player. Defaults are 90/30/14/14 days respectively. Configure per-corp overrides in <strong>Settings &rarr; Tiers</strong> or map a SeAT Connector / Discord role to a tier so new senior officers auto-pick up the right threshold.',
    'feat_ch_classifier_heading' => 'The classifier',
    'feat_ch_classifier_body'    => 'A nightly cron (<code>hr-manager:classify-players</code>) walks every active player and buckets them: <strong>Active</strong> (within threshold), <strong>At Risk</strong> (inside threshold but trending down), <strong>Inactive</strong> (past threshold), <strong>Dead Weight</strong> (significantly past threshold with no positive signals). Inactive directors get a separate critical-tier alert because corp survival depends on responsive leadership. Click "Run Now" on the Corp Health page to re-run on demand.',
    'feat_ch_signals_heading' => 'Wallet signals (CWM round-3)',
    'feat_ch_signals_body'    => 'When Corp Wallet Manager is installed, the classifier folds in wallet flags: <code>STL</code> stalled, <code>NEG</code> net negative, <code>TAX</code> tax compliance &lt;50%, <code>VTX</code> tax compliance &lt;30%, <code>SWD</code> silent wallet director (Accountant role with zero activity), <code>LYL</code> loyalty hold (long tenure softens classification). Plus the Wallet Activity panel on member profile shows lifetime contribution, contribution percentile in corp, top contribution categories, recent entries, monthly trend, and tax compliance bar.',
    'feat_ch_heads_up' => 'Last-login signals require a director-scoped ESI token with <code>esi-corporations.track_members.v1</code>. Without it, the classifier uses what activity it can detect (wallet / mining / login from registered characters) but quality degrades.',
    'feat_ch_where'    => 'Sidebar &rarr; <strong>Corp Health</strong>. Click any player\'s row to drill into their player profile. Run-now button in the page header. Tier mappings in <strong>Settings &rarr; Tiers</strong>.',

    // 5. Purge workflow
    'feat_purge_section'      => 'Purge Workflow',
    'feat_purge_section_desc' => 'Structured offboarding for inactive or unwanted members. Schedule a purge date, the system fires a four-step reminder ladder (T-7 / T-3 / T-48 / T-0), shows the operator a blinking warning when the EVE 24-hour role-removal cooldown is about to bite, and lists the exact titles + high-impact roles to strip across all of the player\'s alts.',
    'feat_p_cap_1' => '<strong>Mark for Purge</strong> action on the player profile (director permission). Set a target date and leave a reason.',
    'feat_p_cap_2' => '<strong>Reminder ladder</strong>: T-7d, T-3d, T-48h, T-0. Each fires a Discord notification (configurable per webhook). T-48h and T-0 messages also list the in-game titles + high-impact roles to strip across the player\'s entire roster — pulled from <code>corporation_member_titles</code> and <code>corporation_roles</code>.',
    'feat_p_cap_3' => '<strong>Blinking warning banner</strong> on the player profile when the purge is within 24 hours. Tier ramp: critical red gradient + pulsing animation (&lt;= 24h), warning amber (24-72h), info teal (72h+ / unscheduled). Respects <code>prefers-reduced-motion</code>.',
    'feat_p_cap_4' => '<strong>Discord roles are handled by your SeAT Connector</strong>, not HR. When the player loses corp access the Connector drops their corp role automatically; HR focuses on the in-game kick checklist.',
    'feat_p_cap_5' => '<strong>History event</strong> recorded at every step (scheduled / cancelled / reminder_t7 / t3 / t48 / t0 / executed) so the player timeline carries the full audit trail forever, even if they\'re kicked and re-recruited years later.',
    'feat_p_cap_6' => '<strong>Squad cleanup</strong>: the player profile and the purge board list the account\'s SeAT squad memberships, split three ways: <strong>removable</strong> (manual / hidden), <strong>kept</strong> (on your never-touch exclusions list), and <strong>auto</strong> (managed by SeAT). A one-button <em>Remove from these squads</em> clears only the removable ones, mirroring SeAT\'s native kick, so with SeAT Connector installed the matching Discord roles drop immediately. <strong>Opt-in auto cleanup</strong> (Settings &rarr; Squad cleanup, off by default) can clear a purged member\'s removable squads automatically: immediately once they are detected as having left the corp, otherwise at a configurable T-24h / T-12h before the kick date. A <strong>never-touch exclusions list</strong> protects keep-in-touch squads such as Former Member or Alliance access. Auto squads are never touched (SeAT would re-add an eligible member from filters) and resolve once the player no longer matches. Director-only, recorded on the history timeline.',
    'feat_p_heads_up' => 'HR Manager <strong>does not</strong> kick characters from the corp in-game. The director must perform the kick action. HR records the outcome and updates the player\'s history. EVE\'s 24-hour cooldown on role REMOVAL means the operator should strip in-game roles at least 24 hours before the kick, or the kicked character will still have role access during that window.',
    'feat_p_where'    => 'Player profile &rarr; <strong>Mark for Purge</strong> button (director only). The Corp Health page surfaces a count of players currently marked for purge.',

    // 6. Watchlist (blacklist + whitelist)
    'feat_watchlist'      => 'Watchlist — Blacklist & Whitelist',
    'feat_watchlist_desc' => 'A two-sided watchlist that surfaces during application review. Add characters by NAME or character ID even when they\'ve never authed in SeAT — the plugin resolves the other half via SeAT\'s cache and CCP ESI. Visible to recruiters; managed by directors. Entries can be scoped per-corporation or set global for cross-corp lists.',
    'feat_w_blacklist_heading' => 'Blacklist',
    'feat_w_blacklist_body'    => 'Known troublemakers, spies, scammers, bad-faith applicants. When an applicant matches a blacklist entry, a <strong>red gradient banner</strong> renders at the top of the application detail page with the reason, severity (low / medium / high), and added-by info. High-severity entries should be rejected by default. Add by character name or ID, attach a free-text reason, optional auto-expiry date.',
    'feat_w_whitelist_heading' => 'Whitelist',
    'feat_w_whitelist_body'    => 'Former members who left on good terms — 5-year veterans gone for IRL, retired corp leaders, friends from other corps you want to prioritize. When their character applies, a <strong>green "welcome back" gradient banner</strong> renders so recruiters fast-track them. Mutually exclusive with the blacklist for the same scope: adding to one list removes any existing entry on the other.',
    'feat_w_resolution_heading' => 'Name &harr; ID resolution',
    'feat_w_resolution_body'    => 'The resolution chain runs in four steps: (1) SeAT <code>character_infos</code> for registered chars, (2) <code>universe_names</code> cache, (3) 24h self-cache, (4) CCP ESI public endpoint (<code>/universe/ids/</code> or <code>/characters/{id}/</code>) with a 3-second timeout. The resolved (id, name) pair is snapshotted on the entry so it survives even if SeAT loses the row later or the character is renamed in-game.',
    'feat_w_where' => 'Sidebar &rarr; <strong>Watchlist</strong>. Two tabs at the top (Blacklist / Whitelist) with headline counts. Add Entry card is director-only.',

    // 7. History timeline
    'feat_history_section'      => 'History Timeline',
    'feat_history_section_desc' => 'An append-only timeline of everything that ever happened to a player across all their characters and corporation moves. Survives corp leaves and re-applications — re-applicants get their full prior history visible to recruiters reviewing their new application.',
    'feat_h_event_types_heading' => 'Event types HR records',
    'feat_h_ev_1' => '<strong>Application events</strong>: submitted, status changes, accepted, rejected, withdrawn, joined corp, prior application history',
    'feat_h_ev_2' => '<strong>Classifier transitions</strong>: flagged_at_risk, flagged_inactive, flagged_dead_weight, recovered, milestone_reached',
    'feat_h_ev_3' => '<strong>Wallet events from CWM</strong> (when installed): stalled, milestone, compliance_dropped, contribution_drop, unusual_recipient',
    'feat_h_ev_4' => '<strong>Purge milestones</strong>: scheduled, reminder_t7 / t3 / t48 / t0, cancelled, executed',
    'feat_h_ev_5' => '<strong>LOA + status</strong>: loa_marked, loa_cleared, mark_for_purge',
    'feat_h_ev_6' => '<strong>Director-only</strong>: silent_wallet_director, inactive_director — the critical-tier alerts that drive operator action',
    'feat_h_where' => 'Player profile &rarr; <strong>History</strong> card below Notes. Each event renders with a semantic icon, timestamp, source plugin badge, and payload summary.',

    // 8. Public tracking + corp-join detection
    'feat_public_tracking_section'      => 'Public Tracking & Corp-Join Detection',
    'feat_public_tracking_section_desc' => 'Two features that close the recruitment loop: applicants can bookmark a private link to check their status without logging into SeAT, and the system detects when accepted applicants actually appear in the corp (vs ghosting after acceptance).',
    'feat_pt_tracking_heading' => 'Public tracking link',
    'feat_pt_tracking_body'    => 'Every application gets an unguessable 48-character base62 slug at submit time. The URL <code>/recruit/track/{token}</code> is no-auth, no-CSRF, and shows the applicant: their character portrait + name, the corp they applied to, current status badge, a clean status timeline (transitions + timestamps only, NO recruiter comments), the joined-corp outcome panel once decided, and their own submitted answers. Notes / handlers / eligibility internals are never shown. The post-submission confirmation page surfaces the URL in a copy-friendly input.',
    'feat_pt_join_heading' => 'Corp-join detection',
    'feat_pt_join_body'    => 'A background cron (<code>hr-manager:detect-corp-joins</code>, every 30 minutes) scans SeAT\'s synced <code>character_corporation_histories</code> for accepted applications. When the applicant\'s character actually appears in the target corp\'s history after the decision date, <code>joined_corp_at</code> gets flipped and an <code>hr.application.joined_corp</code> event is published to EventBus. Bounded to a 90-day window — we stop scanning ancient applications. The Corp Health page surfaces an "accepted but never joined" backlog count.',
    'feat_pt_where' => 'The tracking URL surfaces on the post-submission confirmation page and on the admin Application detail sidebar (copy-to-clipboard input). The Corp-join outcome shows in the Info sidebar of any accepted application.',

    // 9. Notifications
    'feat_notifications_section'      => 'Notifications',
    'feat_notifications_section_desc' => 'Per-category Discord / Slack / mail routing with role-mention support. Webhooks are configured under Settings; each one opts in to specific categories independently so general-purpose channels stay quiet by default and critical alerts go to the right people.',
    'feat_n_cap_1' => '<strong>Per-webhook category toggles</strong>: application submitted / accepted / rejected / status change, classifier flags (at_risk / inactive / dead_weight), inactive director critical alert, purge reminders (T-7 / T-3 / T-48 / T-0), player status (LOA / marked for purge / cleared, fired the moment a director sets it), wallet signals (stalled / compliance_dropped / milestone). Each toggle independent.',
    'feat_n_cap_2' => '<strong>Discord role mentions</strong>: per-category mention input that the warlof seat-connector / SeAT Broadcast Discord role picker can populate. Multi-role mentions per binding so one message can ping multiple roles.',
    'feat_n_cap_3' => '<strong>Webhook scoping</strong>: per-corp webhooks via the <code>corporation_id</code> on the webhook row. Events fired for that corp route to that webhook; other corps see other channels.',
    'feat_n_cap_4' => '<strong>EventBus publishing</strong>: HR publishes a documented event family (<code>hr.application.*</code>, <code>hr.player.*</code>, <code>hr.purge.*</code>) so SeAT Broadcast and other Manager Core subscribers can react. Subscribers get the full payload shape including <code>handler_user_ids</code> on application transitions.',
    'feat_n_cap_5' => '<strong>Failure isolation</strong>: notification dispatches run inside try/catch/log so a webhook outage (Discord 500, expired token, network blip) cannot poison the upstream event chain. The application still saves; the notification just logs a warning.',
    'feat_n_where' => 'Sidebar &rarr; <strong>Settings &rarr; Webhooks</strong> (admin only). Each webhook has its own row of category toggles. Test buttons send a sample message per category.',

    // 10. Cross-plugin integrations
    'feat_integrations_section'      => 'Cross-Plugin Integrations',
    'feat_integrations_section_desc' => 'HR Manager runs standalone — none of these are required. When installed alongside, each one lights up extra signals via Manager Core\'s PluginBridge capability registry or EventBus subscriptions. Every integration is gated by <code>class_exists</code> / <code>Schema::hasTable</code> at runtime, so the plugin degrades gracefully when sibling plugins are missing.',
    'feat_i_cwm_heading' => 'Corp Wallet Manager',
    'feat_i_cwm_body'    => 'HR consumes seven PluginBridge capabilities (lifetime summary, contribution trend, net position, activity gaps, percentile, tax compliance, director attribution) and three CWM events (stalled / milestone / compliance_dropped) plus two newer subscriptions (contribution_drop_detected / unusual_recipient). Surfaces as: wallet flags on PlayerClassification, the Wallet Activity panel on member profile with top categories + recent entries + percentile + contribution trend, corp-level wallet aggregates on Corp Health, top-contributors leaderboard, milestone events in the player history timeline.',
    'feat_i_mm_heading' => 'Mining Manager',
    'feat_i_mm_body'    => 'HR pulls mining activity, tax payments, and ore preferences via three PluginBridge capabilities (mining history / tax history / ore breakdown). Subscribes to the <code>mining.*</code> EventBus pattern for real-time updates. Drives the Mining Statistics block on member profiles, the tax compliance percentage on assessments, and ore-preference signals on player history.',
    'feat_i_mc_heading' => 'Manager Core',
    'feat_i_mc_body'    => 'The optional integration hub. Provides PluginBridge capability registry (HR registers its own publish-side capabilities here for other plugins), EventBus pub/sub (HR publishes <code>hr.application.*</code> / <code>hr.player.*</code> / <code>hr.purge.*</code> and subscribes to CWM / MM events), shared pricing service, ESI fast-poll, and the diagnostic page that lists every cross-plugin subscription. Without MC, HR runs in standalone mode — no cross-plugin events, no shared pricing, but every page still renders.',
    'feat_i_bp_heading' => 'Blueprint Manager',
    'feat_i_bp_body'    => 'A two-channel integration through Manager Core. HR subscribes to <code>blueprint.request.*</code> on the EventBus (each created / approved / rejected / fulfilled request lands on the requester history timeline) and reads Blueprint Manager\'s <code>blueprint.getCharacterStats</code> / <code>getCorpSummary</code> capabilities on demand. Surfaces as a Blueprint Activity panel on the player profile (requests / fulfilled / rejected / pending, rejection-rate badge, favourite blueprint types, aggregated across alts) and a Blueprint Engagement card on Corp Health Economy. Fulfilled corp sourcing also strengthens the Industrialist role badge and acts as a positive engagement modifier.',
    'feat_i_sm_heading' => 'Structure Manager',
    'feat_i_sm_body'    => 'HR reads Structure Manager\'s <code>compliance.getForCorporation</code> capability through Manager Core (pull-only, on demand) and renders the result on the Corp Health structure-compliance tab: each corp Upwell structure verdicted against the alliance-recommended fit, with a slot-by-slot diff and Copy / Appraise buttons. Structure Manager owns the doctrines, the EFT editor, and the compute; HR only displays the report, and the Manage doctrines button links into Structure Manager. When Structure Manager is absent the tab shows a required notice (no local fallback, since Structure Manager owns structure data for the suite).',
    'feat_i_broadcast_heading' => 'SeAT Broadcast',
    'feat_i_broadcast_body'    => 'HR subscribes to two EventBus topics: <code>pings.broadcast.sent</code> (reactive fleet broadcasts) and <code>pings.formup.scheduled</code> (proactive form-up planning), accumulating each into its own table (forward-only, no backfill). Drives the FC Activity panel on the player profile (broadcasts led, per-week cadence, active span, type mix, plus a planning block) and the Corp Health fleet-commander roster (active / faded / new FCs) with an Organizers section ranking who plans ops. Also lights the FC role badge in the character role classifier. HR in turn publishes its own <code>hr.*</code> events that SeAT Broadcast can consume.',
    'feat_i_connector_heading' => 'warlof/seat-connector',
    'feat_i_connector_body'    => 'When installed, the member profile Quick Info panel surfaces the linked Discord username + the Discord role list. The cached identity lookup is bypassed on Refresh Data so admins can verify role rebinds without waiting for the 10-minute TTL. Role data also feeds the Discord role picker on the notification webhook configuration form.',
    'feat_i_zkill_heading' => 'zKillboard',
    'feat_i_zkill_body'    => 'Public ESI integration (no key required). Each member profile shows a Recent PvP card with kills, losses, ISK destroyed / lost, danger ratio, and a click-through link to the character\'s zKill page. Cached 30 minutes per character. Renders a graceful "zKillboard unreachable" message when the API is down — the rest of the profile is unaffected.',
    'feat_i_standalone_label' => 'Standalone mode',
    'feat_i_standalone_body'  => 'HR Manager fully functions without any of these. Sibling plugins are <strong>additive</strong>: install them when you want the extra signals; the existing data and workflows keep working either way. The Help Overview\'s "What is HR Manager?" card calls this out — the plugin\'s standalone story is part of its design.',

    // ============================================================
    // RECRUITMENT SITE
    // ============================================================
    'recruitment_title' => 'Public Recruitment Site',
    'recruitment_intro' => 'Each corp can publish multiple landing pages. The public URL is shareable, mobile-friendly, and does not require the visitor to be logged into SeAT.',

    'url_pattern_title' => 'URL pattern',
    'url_pattern_body'  => 'The <code>ticker</code> segment must match an existing corporation ticker in SeAT. The <code>slug</code> is per-landing and is set when you publish.',

    'visual_templates_title' => 'Visual templates',
    'tpl_classic_desc'    => 'Standard hero + body + CTA. Safe default.',
    'tpl_showcase_desc'   => 'Image-heavy layout for corps with art assets.',
    'tpl_minimal_desc'    => 'Plain, fast-loading, copy-focused.',
    'tpl_industrial_desc' => 'Industry-themed accent colors and iconography.',

    'post_submission_title' => 'Post-submission modes',
    'psm_discord_desc'   => 'Show a Discord invite link after the form succeeds.',
    'psm_connector_desc' => 'Hand the applicant off to SeAT Connector to link their Discord. The link is built automatically from your SeAT address (no config needed) and only shows when Connector is installed.',
    'psm_custom_desc'    => 'Display a custom HTML/Markdown message you author.',
    'psm_none_desc'      => 'No Discord step at all. Pick this deliberately when this corp does not onboard via Discord; the applicant just sees the confirmation (plus any Next steps notes you wrote).',
    'psm_connector_perm_title' => 'Connector mode needs the Connector view permission',
    'psm_connector_perm_body'  => 'The SeAT Connector identity page is permission-gated (<code>seat-connector.view</code>), so a brand-new applicant cannot reach the "link Discord" button on their own. The easy fix: turn on <strong>Settings &rarr; Recruiter Access &rarr; Auto-grant Connector link access to applicants</strong> and HR Manager mints that permission for each applicant automatically (a temporary, auto-managed role) until they join the corp. Prefer to manage it yourself? Set up a squad that grants the <code>seat-connector.view</code> permission to everyone who lands in your SeAT server. Either way HR only grants the page-view permission, never a Discord role.',

    'override_title' => 'Custom visual overrides',
    'override_body'  => 'You can publish a Blade override of any template via vendor:publish. Your published copy is loaded ahead of the bundled one.',

    'eligibility_title' => 'Eligibility engine',
    'eligibility_intro' => 'Applications can be gated before they reach your recruiters. The visitor sees a friendly explanation of which check they failed.',
    'elig_sec_status'      => '<strong>Security status</strong> minimum / maximum',
    'elig_total_sp'        => '<strong>Total SP</strong> minimum (pulled from the applicant\'s registered character)',
    'elig_age_days'        => '<strong>Character age</strong> minimum in days',
    'elig_blacklist_corps' => '<strong>Blacklist</strong> corp IDs (applications from these corps are blocked)',
    'elig_whitelist_alliances' => '<strong>Whitelist</strong> alliance IDs (only applicants from these alliances allowed)',
    'elig_connector'       => '<strong>SeAT Connector</strong> linked-Discord requirement',

    'elig_escape_hatch' => 'An escape hatch lets borderline applicants explain why they fail a check. Recruiters see the reason alongside the application.',

    // ============================================================
    // DISCORD ROLES (handled by SeAT Connector)
    // ============================================================
    'discord_connector_title' => 'Discord roles',
    'discord_connector_intro' => 'HR Manager does <strong>not</strong> assign Discord roles itself. Discord role membership is owned by your SeAT Connector (warlof/seat-connector): HR runs the recruitment and assessment workflow, while how a registered character maps to a Discord role is configured once in the Connector and applies across your whole install. This page covers how to prepare the Connector so applicants and members get the right roles.',

    'discord_connector_members_title' => 'Members get their role automatically',
    'discord_connector_members_body' => 'If your SeAT Connector has a corporation mapping (the standard setup: a Discord set bound to your corp), members get their corp Discord role the moment they join the corp in EVE. HR Manager does nothing here and needs no configuration. Accepting an application and the applicant joining the corp is enough for the Connector to grant the role on its next sync.',

    'discord_connector_applicants_title' => 'Let applicants reach the "link Discord" button',
    'discord_connector_applicants_body' => 'The Connector identity page (<code>/seat-connector/identities</code>) is gated by the <code>seat-connector.view</code> permission, which a brand-new applicant does not hold. You have two ways to give it to them:',
    'discord_connector_applicants_step1' => '<strong>Recommended &mdash; let HR do it automatically</strong>: turn on <em>Settings &rarr; Recruiter Access &rarr; Auto-grant Connector link access to applicants</em>. On apply, HR attaches a temporary, auto-managed SeAT role carrying <code>seat-connector.view</code> to the applicant (no Discord re-sync is triggered) and removes it once they join the corp. Zero Access-Management setup, and the link button just works.',
    'discord_connector_applicants_step2' => '<strong>Or grant it yourself</strong>: set up a squad that grants the <code>seat-connector.view</code> permission to everyone who lands in your SeAT server, so every logged-in user can reach the link button.',
    'discord_connector_applicants_step3' => '<strong>No Connector?</strong> Set the <em>Discord invite URL</em> on the landing instead. The apply flow then shows a "Join our Discord server" button and a recruiter assigns roles manually. (Either way, the applicant must link their identity before the Connector has a Discord ID to push a role to.)',

    'discord_connector_prospect_title' => 'Optional: a distinct @Prospect role',
    'discord_connector_prospect_body' => 'If you want applicants to carry a separate <code>@Prospect</code> Discord role before they are accepted, create that role in Discord, bind a Connector set to it, and key the set off whatever SeAT entity you assign to applicants (for example a permission-light SeAT role you grant on apply and revoke on accept). HR Manager does not wire SeAT Squads for this; role assignment and removal is configured entirely in your Connector.',

    // ============================================================
    // RECRUITER ACCESS — temporary SeAT role grants
    // ============================================================
    'access_title' => 'Temporary recruiter access',
    'access_intro' => 'When a recruiter joins an application\'s handler list, HR Manager can attach a temporary SeAT role granting them view access to the applicant\'s character data &mdash; wallet, mail, assets, skills &mdash; in SeAT\'s own UI. The role is scoped strictly to the applicant\'s character IDs (and any alts linked via PlayerIdentity); your existing Director and other roles are not touched. Auto-revoked on leave / application close / expiry.',

    'access_lifecycle_title' => 'Lifecycle',
    'access_lifecycle_join'  => '<strong>On handler join</strong>: SeAT role <code>hr-mgr:apply:{id}</code> attached, scoped to the applicant\'s character IDs (+ alts if enabled). Expires after the configured max duration (default 7 days).',
    'access_lifecycle_use'   => '<strong>While active</strong>: a panel on the application detail page shows the handler\'s active grant with deep-link buttons (Sheet / Wallet / Mail / Assets / Skills) into SeAT\'s native character pages. Each link opens in a new tab; SeAT enforces the permission transparently.',
    'access_lifecycle_close' => '<strong>On application close</strong> (accepted / rejected / withdrawn) <strong>or handler leave</strong>: role detached for the affected recruiter(s). When the last handler leaves, the role itself is deleted &mdash; the audit record stays.',
    'access_lifecycle_sweep' => '<strong>Daily cron sweeper</strong>: catches any grant whose <code>expires_at</code> passed without a lifecycle hook revoking it. Defensive backstop &mdash; should never actually fire if hooks ran cleanly.',

    'access_setup_title' => 'Enabling the feature',
    'access_setup_body'  => 'Off by default. Two-click opt-in from <strong>Settings &rarr; Recruiter Access</strong>:',
    'access_setup_step1' => 'Tick <strong>Enable temporary SeAT access for handlers</strong>.',
    'access_setup_step2' => 'Pick the <strong>permission set</strong> you want each grant to include &mdash; the default is sheet + journal + transactions + asset + mail + skill (the "due diligence" floor).',
    'access_setup_step3' => 'Set the <strong>maximum grant duration</strong> (1-30 days, default 7). Grants auto-revoke when the application closes regardless of this limit; this is just the safety net for stalled applications.',
    'access_setup_step4' => 'Decide whether to <strong>include alts via PlayerIdentity link</strong>. Recommended on &mdash; otherwise the handler only sees the main character, not the rest of the applicant\'s account.',

    'access_permissions_title' => 'Permission catalogue',
    'access_permissions_body'  => 'Each permission below maps directly to a SeAT character page. Tick the ones you want granted; everything else stays inaccessible to the handler.',
    'access_perm_col'          => 'Permission',
    'access_what_col'          => 'What the handler can see',
    'access_perm_sheet'        => 'Character overview, security status, employment history, attribute scores',
    'access_perm_journal'      => 'Wallet journal &mdash; every transaction (donations, ratting bounties, market fees, etc.)',
    'access_perm_transactions' => 'Market transactions &mdash; buy/sell history with item details and venue',
    'access_perm_mail'         => 'Sent + received mail, including bodies and recipient lists',
    'access_perm_asset'        => 'Every asset the character owns and where it\'s located',
    'access_perm_skill'        => 'Skill queue, current SP per skill, total SP, attribute mappings',
    'access_perm_contract'     => 'Contracts created or received (auctions, courier, exchange, etc.)',
    'access_perm_industry'     => 'Industry jobs &mdash; current and completed, with blueprint and runs detail',
    'access_perm_killmail'     => 'Kills and losses with full fitting + damage breakdown',
    'access_perm_notification' => 'In-game CCP notifications (structure attacks, contract status, etc.)',
    'access_perms_footnote'    => 'Full menu of 20 SeAT character permissions is selectable in Settings. The table above shows the most commonly granted set. Everything not ticked stays blocked.',

    'access_safety_title'     => 'Safety guarantees',
    'access_safety_isolated'  => '<strong>Your existing roles are never modified.</strong> The HR-managed role is purely additive. Removing it never touches your Director or other roles.',
    'access_safety_scope'     => '<strong>Scope is per-character, never wider.</strong> The role\'s affiliation filter lists exact character IDs only &mdash; never a corp or alliance wildcard. Handlers can\'t see other applicants, random pilots, or wider corp data through this role.',
    'access_safety_namespace' => '<strong>Strict <code>hr-mgr:apply:</code> namespace.</strong> HR Manager only ever creates, modifies, or deletes roles whose title starts with this prefix. Operator-managed roles named something else are never touched, even if grant data has been corrupted.',
    'access_safety_pivot'     => '<strong>Detach-by-pivot, never delete-by-role-id-alone.</strong> Revoke = <code>DELETE FROM role_user WHERE user_id=X AND role_id=Y</code>. Surgical; other roles each recruiter has stay attached.',
    'access_safety_concurrent' => '<strong>Multi-handler applications:</strong> each handler\'s pivot is detached independently. The role itself is only deleted when ZERO handlers remain attached.',
    'access_safety_audit'     => '<strong>Every grant + revoke logged</strong> to <code>hr_manager_recruiter_access_grants</code> with reason. Auditable trail: who got access to which applicant, from when to when, and why access ended.',

    'access_caveat_title'  => 'Caveats worth knowing',
    'access_caveat_sso'    => '<strong>Depends on the applicant having granted ESI scopes during SSO.</strong> If your recruitment SSO scope config requests only <code>publicData</code>, SeAT has no wallet / mail / asset data to show the recruiter. <strong>Settings &rarr; SSO &amp; Scopes</strong> tells you exactly which of these scopes your chosen profile carries; bump the profile in <strong>SeAT &rarr; Settings &rarr; SSO Scopes</strong> to include the ones you want recruiters to be able to see.',
    'access_grant_now_note' => 'The per-join grant only fires going forward. If you enabled Recruiter Access <em>after</em> some recruiters already joined applications, enabling it grants every current handler retroactively, and any handler missing a grant can also self-grant with a one-click <strong>Grant access now</strong> button on the application page (no need to leave and re-join).',
    'access_caveat_manual' => '<strong>Don\'t manually attach yourself to <code>hr-mgr:apply:*</code> roles via SeAT&apos;s Access Management.</strong> HR Manager\'s revoke detaches by pivot &mdash; if you manually attached yourself, the revoke would still remove you. If you need permanent access to a specific applicant\'s data, create your own role outside the <code>hr-mgr:*</code> namespace.',

    // SSO & Scopes help card
    'sso_help_title'   => 'SSO scope profile (Settings → SSO & Scopes)',
    'sso_help_intro'   => 'When a brand-new applicant logs in through your recruitment page, the ESI scopes they grant are decided by a SeAT SSO scope profile. This tab lets you pick which profile the funnel uses and verifies it carries the scopes HR needs. SeAT still owns the scope profiles themselves (SeAT → Settings → SSO Scopes); HR only selects one and checks it. The verdict is one of:',
    'sso_help_broken'  => '<strong>Broken</strong>: the required <code>publicData</code> scope is missing. SeAT normally forces it, so this points to a malformed profile.',
    'sso_help_minimal' => '<strong>Minimal works, more needed</strong>: applicants can apply and you can see their public info, but the listed assessment features (skills / wallet / assets / mail) have no data because the profile does not request those scopes.',
    'sso_help_full'    => '<strong>Full</strong>: the profile carries every scope HR uses, so the recruiter-access deep links have real data to read.',
    'sso_help_optional' => 'Beyond the required (<code>publicData</code>) and recommended (skills / wallet / assets / mail) tiers, the profile can also request an <strong>optional intel tier</strong> (clones / implants, corp roles, killmails, standings, contacts). These are never needed to apply: they light up extra <strong>applicant assessment</strong> signals when granted (implants, current-corp roles, standings), and a missing one counts as an unlit signal, never a problem.',
    'sso_help_note'    => 'Only the first application login is steered through the chosen profile. A profile you picked that later gets deleted in SeAT self-heals to the SeAT default.',
    'sso_overwrite_title' => 'Important: SeAT overwrites scopes on every login',
    'sso_overwrite_body'  => 'SeAT <strong>replaces</strong> a character\'s ESI scopes every time they log in (it never merges). So if you point the recruitment funnel at a profile that is <em>narrower</em> than your SeAT default, and an <strong>existing</strong> character logs in fresh through recruitment, they lose any scope that profile does not request, and that character\'s ESI updates for the dropped data stop. The SSO &amp; Scopes tab shows a red warning listing the exact scopes at risk whenever this is the case. Fix: make your recruitment profile a <strong>superset</strong> of your default (request at least as much), or leave the funnel on the SeAT default. Brand-new applicants are never affected, this only matters for characters that already exist in SeAT with broader scopes (for example, a member of one corp applying to another corp in the same alliance SeAT).',
    'sso_updates_title' => 'Does any of this affect SeAT\'s character updates?',
    'sso_updates_body'  => 'No, with the single exception above. HR Manager\'s recruitment + auth flow does not interfere with how SeAT keeps characters updated:',
    'sso_updates_buckets' => '<strong>Update buckets</strong> (SeAT spreads character ESI updates across time): untouched. New applicant tokens are created and assigned to buckets by SeAT\'s own login controller, exactly like any other character. HR never writes the bucket tables.',
    'sso_updates_tokens'  => '<strong>Refresh tokens</strong>: HR only ever reads them, it never creates, edits, or deletes a token. Logging in / linking is handled entirely by SeAT.',
    'sso_updates_recruiter' => '<strong>Recruiter Access</strong> grants only touch SeAT\'s permission tables (roles), which are about who can see which page, completely separate from ESI scopes and tokens. They have zero effect on updates.',
    'sso_updates_caveat'  => '<strong>The one caveat</strong> is the scope overwrite described above, and it only happens if you deliberately route the funnel through a narrower profile. The tab + diagnostic both warn you before it can.',

    'access_recruiter_ux_title' => 'What the handler sees',
    'access_recruiter_ux_body'  => 'A pinned panel at the top of the application detail page titled <strong>Your temporary SeAT access</strong>, with an "expires in" badge in the header. Each of the applicant\'s characters gets its own row with deep-link buttons (Sheet / Wallet / Mail / Assets / Skills). Clicking a link opens SeAT\'s native page in a new tab &mdash; the recruiter does their due diligence in SeAT\'s own UI, not a copy in HR Manager. The panel disappears when the grant is revoked.',

    // ============================================================
    // APPLICATIONS
    // ============================================================
    'applications_title' => 'Application Workflow',
    'applications_intro' => 'Submitted applications land in an inbox scoped to the recruiter\'s corp. Each application progresses through a fixed set of statuses.',

    'workflow_title' => 'Status ladder',
    'status_applied_desc'      => 'Initial state when the application is submitted.',
    'status_under_review_desc' => 'A recruiter is reviewing the application.',
    'status_interview_desc'    => 'Applicant is scheduled for or in an interview.',
    'status_accepted_desc'     => 'Application approved. Recruiter can hand off via Discord / Connector / custom.',
    'status_rejected_desc'     => 'Application denied. A rejection notification fires if enabled.',
    'status_withdrawn_desc'    => 'Applicant withdrew their own application.',

    'transitions_body' => 'Every status change is logged in the application history with timestamp, actor, and optional note. The applicant receives an in-SeAT notification when their status changes if they have a SeAT account.',

    'templates_title' => 'Form Templates',
    'templates_intro' => 'Form templates define the questions asked in application forms. Directors can create custom templates for different recruitment scenarios (PvP, industrial, alliance probationer, etc.).',
    'template_lock_title' => 'Editing a template that has been used',
    'template_lock_body'  => 'Once a template has collected any application, its <strong>questions lock</strong>. The details (name, active, corp) stay editable, but the questions become read-only. This is deliberate: each answer snapshots its question text at submit time, so past applications always keep their original Q&A no matter what, and locking avoids a confusing record where the live template no longer matches what applicants saw. To change the questions, use <strong>Duplicate</strong> to make a fresh editable copy and point your landing at it. Used templates can\'t be deleted either (deactivate or duplicate instead); a hard delete is also blocked at the database level so it can never remove applications.',
    'question_types_title' => 'Supported question types',
    'type_text'     => 'short text input',
    'type_textarea' => 'multi-line text input',
    'type_select'   => 'dropdown with predefined options',
    'type_checkbox' => 'multiple choice selection',
    'type_radio'    => 'single choice from predefined options',
    'type_number'   => 'numeric input',
    'type_url'      => 'link input',

    // ============================================================
    // ACTIVITY TIERS
    // ============================================================
    'tiers_title' => 'Activity Tiers',
    'tiers_intro' => 'Activity tiers categorize members by responsibility. Each tier has its own activity threshold (in days since last activity) that the classifier uses to decide whether a member is active, at risk, inactive, or dead weight.',

    'col_tier'              => 'Tier',
    'col_label'             => 'Label',
    'col_default_threshold' => 'Default threshold',
    'col_description'       => 'Description',
    'no_threshold'          => 'n/a',

    'tier_applicant_desc' => 'Pre-acceptance only. Excluded from classification.',
    'tier_member_desc'    => 'Standard member. Largest population.',
    'tier_junior_desc'    => 'Junior officers, squad leads, FC trainees.',
    'tier_senior_desc'    => 'Senior officers, line FCs, department heads.',
    'tier_director_desc'  => 'In-game directors. Inactive director alerts apply.',

    'tier_resolution_body' => 'A player\'s effective tier is resolved by their highest-tier registered character. If you haven\'t mapped a user to a tier, the classifier defaults to <code>L0 Member</code>.',

    // ============================================================
    // CORP HEALTH
    // ============================================================
    'corp_health_title' => 'Corp Health Classifier',
    'corp_health_intro' => 'The Corp Health view buckets every player in the corp into one of four categories based on their tier threshold + activity signals. Each bucket gets its own count and drill-down.',

    'categories_title'         => 'Buckets',
    'cat_active_desc'          => 'Within their tier threshold and contributing.',
    'cat_at_risk_desc'         => 'Inside threshold but trending down (stalled wallet, no recent activity, etc.).',
    'cat_inactive_desc'        => 'Past threshold. Candidate for follow-up or purge.',
    'cat_dead_weight_desc'     => 'Significantly past threshold AND no positive signals at all.',

    'wallet_signals_title' => 'Wallet signals (when Corp Wallet Manager is installed)',
    'wallet_signals_intro' => 'Each player carries a set of compact wallet flags rendered as colored badges:',
    'flag_stalled_desc'    => 'No wallet activity in N days.',
    'flag_negative_desc'   => 'Net negative contribution to corp wallet over window.',
    'flag_tax_desc'        => 'Tax compliance below 50% over the assessment window.',
    'flag_vtx_desc'        => 'Tax compliance below 30% — escalated warning.',
    'flag_swd_desc'        => 'Silent wallet director: holds Accountant / Junior Accountant role with zero wallet activity.',
    'flag_loyalty_desc'    => 'Loyalty hold: long tenure with the corp, applied automatically to soften classification.',

    'inactive_director_label' => 'Inactive director alert',
    'inactive_director_body'  => 'A separate critical-severity alert fires when an in-game director crosses their L3 threshold. Directors with the keys to your wallet should not be inactive without anyone noticing.',

    // Wallet Insights cluster
    'wi_title'  => 'Wallet Insights (director-tier)',
    'wi_intro'  => 'When Corp Wallet Manager is installed, the Corp Health page surfaces a cluster of director-only cards that roll up the per-member wallet data HR caches into corp-wide radar. Each card self-hides when the underlying data is absent, and the whole cluster vanishes if no card has anything to show. Reads from the 5-minute-cached status struct so it adds no per-render query cost.',
    'wi_card_col' => 'Card',
    'wi_what_col' => 'What it surfaces',
    'wi_untaxed'    => 'Members who earned ISK (ratting / mining) but pay under 50% tax. The clearest tax-dodge signal — affirmative evidence rather than mere silence. Cross-check each on their member profile.',
    'wi_anomalies'  => 'Members carrying a wallet flag: net-negative position or compliance under 50%. NEG / VTX / LOW chips. The "which 5 of 200 need a second look" radar.',
    'wi_freeloaders' => 'Current members with near-zero lifetime contribution. An EARNING chip flags anyone who pulled 100M+ from ratting/mining but contributed almost nothing — active but not paying.',
    'wi_loyalty'    => 'Top positive net-position members over 6 months. The counterweight to the punitive sections: who to thank, who to promote.',
    'wi_outflows'   => 'Where the corp wallet\'s ISK is going, grouped by recipient, over the last 3 months. Top 10 destinations + an unattributed bucket (CCP rarely structures the acting director on outgoing entries).',
    'wi_footnote'   => 'Cards 1-4 read HR\'s own cached assessment data — no live CWM call per render. Card 5 (outflows) calls Corp Wallet Manager through Manager Core\'s PluginBridge. All five are gated behind the <code>hr-manager.director</code> permission.',

    // Character roles + FC activity
    'roles_title'      => 'Character roles & FC activity',
    'roles_intro'      => 'Every character profile carries role badges inferred from <strong>observed activity</strong> — never guessed. A PI colony existing means the character does PI; bounty income means it ratted; an industry job means it builds. The badges report what the character demonstrably did over the last 6 months. A multibox main can carry several. The <strong>Corp Health → Composition</strong> tab aggregates these into a per-character activity distribution across the whole roster.',
    'roles_badge_col'  => 'Badge',
    'roles_signal_col' => 'Signal (what earns it)',
    'roles_ratter'     => 'CWM ratting income by wallet ref-type — bounties (anomaly/belt) vs agent mission rewards. 100M+ ISK over the window.',
    'roles_miner'      => 'Mining Manager ore value — 100M+ over the window.',
    'roles_trader'     => 'SeAT market transactions — 40+ in the window with at least one sell (filters out "bought a few ships").',
    'roles_industry'   => 'SeAT character_planets (active colonies) / character_industry_jobs (jobs split manufacturing / science / reactions). Both work standalone — no Manager Core needed.',
    'roles_pvper'      => 'zKillboard — 20+ lifetime kills. Read via a cache peek (never a cold fetch), so bulk profile rendering can\'t hammer zKill.',
    'roles_fc'         => 'SeAT Broadcast — 3+ fleet broadcasts. Human-level (resolved via the character\'s owner account).',
    'roles_standalone' => 'PI, Industry, Trader and PvP badges work on a fully standalone install. Ratter / Miner / FC need their source plugin (CWM / MM / SeAT Broadcast via Manager Core).',

    'fc_title'  => 'FC activity (via SeAT Broadcast)',
    'fc_body'   => 'When SeAT Broadcast is installed, HR subscribes to its <code>pings.broadcast.sent</code> EventBus topic and accumulates each broadcast into its own table. The <strong>player profile</strong> shows a fleet-command profile: broadcasts led, per-week cadence, active span, and a type mix. Automated structure-defense pings are excluded from the fleet counts.',
    'fc_roster' => 'The <strong>Corp Health → Composition</strong> tab carries a fleet-commander roster: <strong>active</strong> FCs ranked by broadcasts (with cadence), <strong>faded</strong> FCs who led before but went quiet 30+ days, and <strong>new</strong> FCs who just started. <em>Forward-only</em>: the picture builds from when HR subscribed — there is no backfill of pre-existing broadcast history, so the "new FC" tag is noisy in the first ~30 days after install.',

    // ============================================================
    // PURGE WORKFLOW
    // ============================================================
    'purge_title' => 'Purge Workflow',
    'purge_intro' => 'Purge is the structured process for removing inactive members. HR Manager schedules a purge date and fires four reminder pings on the way to it so the affected player has time to react or appeal.',

    'reminder_ladder_title' => 'Reminder ladder',
    't7_desc'  => 'First warning, 7 days out.',
    't3_desc'  => 'Second warning, 3 days out.',
    't48_desc' => 'Final warning, 48 hours out.',
    't0_desc'  => 'Purge day. Operator confirms in-game kick; HR records the outcome.',

    'purge_no_auto_kick' => 'HR Manager <strong>never</strong> kicks players from your corp in-game. The CEO / Director must perform the kick action. HR records the outcome and updates the player\'s history.',

    // ============================================================
    // PLAYERS / MEMBERS
    // ============================================================
    'players_title' => 'Players View',
    'players_intro' => 'The Players page rolls every human up into a single row. One person with five alts shows up once. Director access only (the profile carries sensitive per-character data).',
    'players_summary_1' => '<strong>Tier</strong> resolved from highest-tier registered character.',
    'players_summary_2' => '<strong>Health bucket</strong> from the classifier (active / at risk / inactive / dead weight).',
    'players_summary_3' => '<strong>Activity sparkline</strong> for the last 12 weeks of contribution.',
    'players_summary_4' => '<strong>Wallet flags</strong> (when Corp Wallet Manager is installed).',

    'members_title' => 'Members View',
    'members_intro' => 'The Members page lists every character (registered or unregistered) visible to ESI corp-member tracking. Use this when you need character-level detail. Director access only (the profile carries sensitive per-character data).',
    'members_features_1' => '<strong>Corp picker</strong> drops down to your accessible corps; defaults to your main\'s corp.',
    'members_features_2' => '<strong>Display names</strong> fall back to universe_names cache for unregistered alts (no more "Unknown" rows).',
    'members_features_3' => '<strong>Registration column</strong> shows which characters have a SeAT refresh token.',
    'members_features_4' => '<strong>Member profile</strong> pulls Discord identity, recent PvP via zKillboard, and wallet activity when sibling plugins are installed.',

    // ============================================================
    // NOTES
    // ============================================================
    'notes_title' => 'Notes',
    'notes_intro' => 'Notes are polymorphic — you can attach them to applications, members, or players. Notes survive across status changes and corp moves so the recruiting history of a person is never lost.',

    'notes_scopes_title' => 'Scopes',
    'notes_scope_player'      => 'Note attached to the human. Persists across alts, corp moves, and re-applications.',
    'notes_scope_member'      => 'Note attached to a specific character within a corporation.',
    'notes_scope_application' => 'Note attached to a specific application. Visible during review.',

    'privacy_title' => 'Privacy',
    'privacy_body'  => '<strong>Private notes</strong> are visible only to the author. Even directors and admins cannot read them. <strong>Public notes</strong> are visible to all users with recruiter+ access to the parent entity.',

    // ============================================================
    // NOTIFICATIONS
    // ============================================================
    'notifications_title' => 'Notification Categories',
    'notifications_intro' => 'HR Manager fires notifications via per-category webhooks. Each category has its own toggle in Settings → Notifications so you can mute the ones you do not care about.',

    'toggle_categories_title'  => 'Categories you can toggle',
    'toggle_app_submitted'     => 'New application submitted',
    'toggle_app_accepted'      => 'Application accepted',
    'toggle_app_rejected'      => 'Application rejected',
    'toggle_status_change'     => 'Application status change',
    'toggle_inactive_director' => 'Inactive director alert (critical)',
    'toggle_dead_weight'       => 'Dead-weight member detected',
    'toggle_purge_reminder'    => 'Purge reminder ladder (T-7 / T-3 / T-48 / T-0)',
    'toggle_wallet_stalled'    => 'Wallet stalled (via CWM)',
    'toggle_wallet_compliance' => 'Wallet tax compliance breach (via CWM)',
    'toggle_wallet_milestone'  => 'Wallet milestone (via CWM)',

    'discord_role_picker_body' => 'When you have SeAT Broadcast or SeAT Connector installed and Discord roles synced, a <strong>Pick role</strong> button replaces the plain text input. Roles are merged and deduped across providers so you get one consistent picker.',

    // ============================================================
    // INTEGRATIONS
    // ============================================================
    'integrations_title' => 'Plugin Integrations',
    'integrations_intro' => 'HR Manager runs standalone and lights up extra signals when sibling plugins are installed. No hard dependencies — every integration is gated by <code>class_exists</code> at runtime.',

    'col_plugin'   => 'Plugin',
    'col_provides' => 'Provides',
    'col_fallback' => 'Fallback when missing',

    'int_mc_provides'        => 'Pricing, EventBus, PluginBridge capability registry.',
    'int_mc_fallback'        => 'Internal ESI poll, no pricing, no cross-plugin events.',
    'int_mm_provides'        => 'Mining activity signals per character / per player.',
    'int_mm_fallback'        => 'Player rows show "no mining data" — classification still works.',
    'int_cwm_provides'       => 'Wallet flags, tax compliance, contribution trend, milestone events.',
    'int_cwm_fallback'       => 'Wallet flag column is hidden; classification still uses login / role signals.',
    'int_bp_provides'        => 'Blueprint request activity + engagement (player profile panel, Corp Health Economy card, Industrialist role signal).',
    'int_bp_fallback'        => 'Blueprint panels hide; other role + engagement signals still classify.',
    'int_sm_provides'        => 'Per-structure doctrine compliance on the Corp Health structure-compliance tab (Structure Manager owns the doctrines + data).',
    'int_sm_fallback'        => 'Tab shows a "Structure Manager required" notice; the Manage doctrines button links into Structure Manager.',
    'int_broadcast_provides' => 'FC activity + form-up planning: broadcasts led, cadence, and the Corp Health fleet-commander roster.',
    'int_broadcast_fallback' => 'FC panels hide and the FC role badge never lights up; the rest of the profile renders normally.',
    'int_connector_provides' => 'Discord username + role list per linked character.',
    'int_connector_fallback' => 'Quick Info card hides Discord row.',
    'int_zkill_provides'     => 'Recent PvP summary on each member profile.',
    'int_zkill_fallback'     => 'Card shows "zKillboard unreachable"; rest of profile renders normally.',

    'integrations_principle' => 'The plugin always renders. If a service is missing, its card shows a graceful "unavailable" badge and the rest of the page works.',

    // ============================================================
    // PERMISSIONS
    // ============================================================
    'permissions_title' => 'Permission Model',
    'permissions_intro' => 'HR Manager uses a 4-tier permission model. Higher tiers inherit all lower-tier access. Role permissions only gate page access — data is further scoped by corporation_id at the controller level.',

    'permission_level'   => 'Permission Level',
    'permission_access'  => 'Access',
    'perm_view_desc'      => 'Help page only.',
    'perm_recruiter_desc' => 'View and process applications, manage own notes, character checks. (Member + player profiles are director-only.)',
    'perm_director_desc'  => 'All recruiter access + member + player profiles (sensitive data), Corp Health, manage templates, assign recruiters, accept/reject applications, schedule purges.',
    'perm_admin_desc'     => 'Full control: settings, webhooks, delete applications and templates, diagnostic page.',

    'coherence_recommendation' => 'Recommended: grant <strong>recruiter</strong> to your recruitment team and <strong>director</strong> to corp leadership. Reserve <strong>admin</strong> for SeAT site admins who own the install.',

    // ============================================================
    // COMMANDS
    // ============================================================
    'commands_title' => 'Artisan Commands',
    'commands_intro' => 'HR Manager ships a small set of artisan commands. All run on cron via SeAT\'s scheduler; you should not need to invoke them by hand under normal operation.',

    'cmd_cache_assessments' => 'Refresh the cached MemberAssessment rows for every tracked character. Runs hourly.',
    'cmd_cleanup'           => 'Garbage-collect expired application drafts, stale tokens, and orphaned notes.',
    'cmd_classify'          => 'Run the Corp Health classifier across every active player. Runs every six hours.',
    'cmd_purge'             => 'Dispatch the next due purge reminder (T-7 / T-3 / T-48 / T-0). Runs every fifteen minutes.',
    'cmd_diagnose'          => 'Print a self-test summary to stdout for support tickets.',

    'commands_schedule_note' => 'Cron schedules are registered by HR Manager\'s ScheduleSeeder and visible in <strong>Settings → Schedules</strong>.',

    // ============================================================
    // FAQ
    // ============================================================
    'faq_title' => 'Frequently Asked Questions',

    'q_no_mc' => 'Do I need Manager Core installed?',
    'a_no_mc' => 'No. HR Manager runs standalone. Manager Core unlocks cross-plugin EventBus, shared pricing, and the PluginBridge capability registry but every page still renders without it.',

    'q_director_token' => 'Why is my Corp Health view showing stale last-login dates?',
    'a_director_token' => 'HR Manager pulls login / logoff timestamps from <code>corporation_member_trackings</code>, which is only populated when an ESI token with the in-game <code>Director</code> role is present. Add a director token under <strong>Seat → Settings → API → Refresh tokens</strong>.',

    'q_unmapped_tier' => 'A player is showing tier "L0 Member" but they\'re actually a Junior Officer. How do I fix it?',
    'a_unmapped_tier' => 'Go to <strong>Tiers</strong> and assign the user to the correct tier. The classifier defaults to L0 when no explicit mapping exists.',

    'q_zkill_slow' => 'The Recent PvP card is slow / showing "unreachable".',
    'a_zkill_slow' => 'zKillboard is rate-limited and occasionally unavailable. HR Manager caches results for 30 minutes per character and renders a graceful unavailable badge when the API is down. Click Refresh Data on the profile to force a re-fetch.',

    'q_unregistered_alts' => 'Why do I see characters with no SeAT data?',
    'a_unregistered_alts' => 'Those are unregistered alts visible to ESI corp-member tracking. The plugin shows them with a friendly fallback name (from universe_names) so you can chase them to register. The Registered column marks who has a refresh token.',

    'q_member_count_mismatch' => 'The Members page shows a different count than my in-game corporation window.',
    'a_member_count_mismatch' => 'Member tracking lags a few minutes behind in-game state. SeAT also includes recently-departed characters until the next sync. If the gap persists for hours, check that your director token is healthy.',

    // ============================================================
    // LEGACY KEYS (older blade compatibility)
    // ============================================================
    'overview_text'     => 'HR Manager is a comprehensive recruitment and member management tool for EVE Online corporations using SeAT.',
    'overview_features' => 'Key features include:',
    'feature_applications'      => 'Application forms with customizable templates for new recruits',
    'feature_notes'             => 'Private and public notes system for recruiters and directors',
    'feature_member_assessment' => 'Member assessment dashboard with cross-plugin data integration',
    'feature_character_checks'  => 'ESI-based character checks (employment history, security status, skill points)',
    'feature_cross_plugin'      => 'Integration with Mining Manager and Corp Wallet Manager for activity tracking',

    'applications_text'    => 'Applications are the core of the recruitment process. Prospective members fill out application forms and recruiters review them.',
    'application_workflow' => 'Application Workflow',
    'workflow_text'        => 'Each application progresses through the following statuses:',
    'status_applied'       => 'Applied',
    'status_under_review'  => 'Under Review',
    'status_interview'     => 'Interview',
    'status_accepted'      => 'Accepted',
    'status_rejected'      => 'Rejected',
    'status_withdrawn'     => 'Withdrawn',

    'members_text'         => 'Member profiles provide a comprehensive view of each corporation member, aggregating data from multiple sources.',
    'members_data_sources' => 'Data sources include:',
    'data_mining'  => 'Mining Manager: mining activity, ore values, tax payments',
    'data_ratting' => 'Corp Wallet Manager: ratting income, bounty taxes',
    'data_tax'     => 'Tax compliance: percentage of taxes paid vs owed',
    'data_esi'     => 'ESI data: employment history, security status, skill points',

    'notes_text'         => 'Notes can be attached to both applications and member profiles.',
    'notes_private'      => 'Private notes',
    'notes_private_desc' => 'Visible ONLY to the author. No one else can see private notes, not even directors or admins.',
    'notes_public'       => 'Public notes',
    'notes_public_desc'  => 'Visible to all recruiters and directors who have access to the application or member profile.',

    'templates_text' => 'Form templates define the questions asked in application forms. Directors can create custom templates for different recruitment scenarios.',
    'templates_recruiting_corp_intro' => 'Each template can be tied to a specific recruiting corporation. Applications submitted via a corp-tied template are routed to recruiters in that corp. Global templates (no corp set) only surface in the admin inbox when the applicant is outside the recruiter\'s corp.',
    'templates_question_types' => 'Supported question types:',

    'permissions_text' => 'HR Manager uses a 4-tier permission model. Higher tiers inherit all lower tier access.',
];

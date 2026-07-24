<?php

return [
    // --- Applicant assessment panel (recruiter-facing auto screen) ---
    'assess_heading'          => 'Applicant assessment',
    'assess_subtitle'         => 'Automated screen of public and granted data. Intel for your decision, not a gate.',
    'assess_verdict_green'    => 'looks clean',
    'assess_verdict_amber'    => 'review recommended',
    'assess_verdict_red'      => 'flags found',
    'assess_no_flags'         => 'No flags raised by the configured criteria.',
    'assess_corp_history'     => 'Corp history',
    'assess_age'              => 'Character age',
    'assess_sec'              => 'Security status',
    'assess_sp'               => 'Skill points',
    'assess_implants'         => 'Implants',
    'assess_corp_roles'       => 'Corp roles',
    'assess_standings'        => 'Standings',
    'assess_scope_ungranted'  => 'scope not granted',
    'assess_pending'          => 'not synced yet',
    'assess_current_corp'     => 'Current corp',
    'assess_npc_corp'         => 'NPC corp',
    'assess_player_corp'      => 'Player corp',
    'assess_characters'       => 'Applicant characters (this account)',
    'assess_char_applicant'   => 'Applying',
    'assess_refresh'          => 'Refresh',
    'assess_refresh_help'     => 'Queue a fresh ESI sync (skills, implants, roles, contacts, plus public info and corp history). The numbers update on the next page load.',
    'assess_refresh_queued'   => 'Assessment data refresh queued. The numbers update once SeAT finishes syncing, usually under a minute. Reload the page to see them.',

    'applications'      => 'Applications',
    'application'       => 'Application',
    'application_number'      => 'Application #',
    'application_number_help' => 'Application number. Quote this when reporting an issue or in the Diagnostics Application Trace.',
    'all_applications'  => 'All Applications',
    'new_application'   => 'New Application',
    'applicant'         => 'Applicant',
    'status'            => 'Status',
    'submitted'         => 'Submitted',
    'assigned_to'       => 'Assigned To',
    'corporation'       => 'Corporation',
    'actions'           => 'Actions',
    'view'              => 'View',
    'stale_badge'       => 'Stale',
    'stale_badge_title' => 'Awaiting review for over :days days.',
    'no_applications'   => 'No applications found.',

    // Statuses
    'status_applied'       => 'Applied',
    'status_under_review'  => 'Under Review',
    'status_interview'     => 'Interview',
    'status_accepted'      => 'Accepted',
    'status_rejected'      => 'Rejected',
    'status_withdrawn'     => 'Withdrawn',

    // Detail
    'application_detail'  => 'Application Detail',
    'answers'             => 'Answers',
    'status_history'      => 'Status History',
    'no_status_history'   => 'No status history.',
    'by_actor'            => 'By :name',
    'reverted_by'         => 'Reverted as a mistake by :name',

    // Undo last status change (director-only correction)
    'revert_heading'            => 'Undo Last Status Change',
    'revert_intro'              => 'Undo an accidental status change. This voids the last change (<strong>:voided</strong>), restores the application to <strong>:restored</strong>, and <strong>hides the mistake from the applicant\'s tracking page</strong> while keeping it in the internal history.',
    'revert_accepted_warning'   => 'This undoes an Accepted decision. It reverts the status and hides it from the applicant, but does NOT roll back anything the acceptance already triggered (Discord onboarding, access, notifications already sent). Handle those manually.',
    'revert_reason_label'       => 'Reason (required)',
    'revert_reason_placeholder' => 'Why is this being reverted? Recorded in the internal history + audit log.',
    'revert_button'             => 'Undo :voided (restore :restored)',
    'revert_confirm'            => 'Undo the last status change and hide it from the applicant? The internal history keeps the record.',
    'revert_success'            => 'Status reverted: :voided undone, restored to :restored (hidden from the applicant).',
    'revert_nothing'            => 'Nothing to revert on this application.',
    'revert_permission_required' => 'Director permission is required to revert an application status.',
    'character_checks'    => 'Character Checks',
    'cross_plugin_data'   => 'Activity Data',
    'change_status'       => 'Change Status',
    'assign_recruiter'    => 'Assign Recruiter',
    'select_status'       => 'Select Status',
    'select_recruiter'    => 'Select Recruiter',
    'comment'             => 'Comment (optional)',
    'update_status'       => 'Update Status',
    'confirm_delete'      => 'Are you sure you want to delete this application?',

    // Handlers (multi-recruiter collaboration on one application)
    'handlers_heading'        => 'Handlers',
    'no_handlers'             => 'No one is handling this yet. Join to take ownership.',
    'join_as_handler'         => 'Join as handler',
    'update_my_role'          => 'Update my role',
    'handler_role_placeholder' => 'Optional role (Reviewer, Interviewer...)',
    'handler_remove'          => 'Remove handler',
    'handler_remove_confirm'  => 'Remove this handler from the application?',
    'handler_joined'          => 'You joined as a handler.',
    'handler_removed'         => 'Handler removed.',
    'handler_updated'         => 'Handler role updated.',
    'you_are_a_handler'       => 'You are a handler on this application.',

    // Recruiter Access panel — shown to handlers who have an active
    // temporary SeAT role granting view access to the applicant's
    // character data. Auto-revoked on handler leave, application
    // close, or expiry sweep.
    'access_panel_heading'    => 'Your temporary SeAT access',
    'access_expires_in'       => 'expires in :rel',
    'access_panel_body'       => 'You can open the applicant\'s character data in SeAT directly. Each link opens in a new tab. Access is auto-revoked when you leave the handler list, when the application closes, or when the grant expires.',
    'access_panel_footnote'   => 'These deep links rely on SeAT\'s native permission system. If a link 403s, the SeAT permission for that page isn\'t in your configured set (Settings → Recruiter Access).',
    'access_no_pages'         => 'No viewable pages — your granted permission set has no character pages. Add some in Settings → Recruiter Access.',
    'access_resync_btn'       => 'Re-sync characters',
    'access_resync_help'      => 'The character list is a snapshot from when access was granted. Click to re-run the grant and pick up any alt the applicant registered afterwards.',

    // Director deep-link card — the directors-only sibling of the recruiter
    // panel above. No temporary grant is involved: a director already holds
    // the SeAT permissions, so these open with their own access. Signed with
    // the applicant's name so it reads distinctly from the temporary panel.
    // Acceptance guard — a HIGH blacklist match blocks approval unless cleared or
    // overridden with a written justification (logged + notified).
    'decide_permission_required' => 'You need the Personnel Manager (or Director) permission to accept or reject applications.',
    'accept_blocked_blacklist' => 'This applicant is on the blacklist (HIGH). Clear the blacklist entry first, or tick "Accept despite the blacklist match" and give a written reason.',
    'accept_override_prefix'   => '[Blacklist override]',
    'accept_override_done'     => 'Accepted with a documented blacklist override — logged to the timeline and sent to your security channel.',
    'accept_guard_heading'     => 'Blacklist match — approval blocked',
    'accept_guard_body'        => 'This account is on your blacklist (:name, HIGH severity). Clear that entry to accept normally, or override below with a written reason.',
    'accept_override_label'    => 'Accept despite the blacklist match',
    'accept_override_placeholder' => 'Why are you accepting a blacklisted applicant? (special conditions, probation, diplomatic exception…)',
    'accept_override_hint'     => 'Required. Recorded on the timeline and sent to your security webhook — no silent overrides.',

    // zKillboard PvP breakdown panel (lazy-loaded per account)
    'pvp_panel_heading' => 'PvP Activity (zKillboard)',
    'pvp_load_btn'      => 'Load PvP breakdown',
    'pvp_load_hint'     => 'Fetches rolling 1 / 3 / 6 / 12-month kill stats for every character on the account (cached 1h).',
    'pvp_loading'       => 'Loading…',
    'pvp_none'          => 'No PvP record found on any character of this account.',
    'pvp_col_character' => 'Character',
    'pvp_col_alltime'   => 'All-time K/L',
    'pvp_col_danger'    => 'Danger',
    'pvp_applying'      => 'Applying',
    'pvp_most_active'   => 'Most active',
    'pvp_win_hint'      => 'Kills in the last :n month(s)',
    'pvp_isk_destroyed' => 'ISK destroyed: :isk',
    'pvp_footnote'      => 'Kills per rolling window (ships destroyed), summed from zKillboard\'s monthly data. The All-time column is lifetime kills / losses; Danger is zKill\'s danger ratio. Hover a window cell for ISK destroyed.',
    'pvp_error'         => 'Could not load PvP stats — try again.',

    'director_deeplink_heading'  => 'Director deep link',
    'director_deeplink_footnote' => 'You don\'t need temporary access to see this — these open SeAT\'s own character pages with your own director permissions, in a new tab.',

    // "Grant access now" fallback — shown to a handler who has no active
    // grant (joined before the feature was enabled, or it expired). One
    // click re-grants without leaving and re-joining the handler list.
    'access_grant_prompt'     => 'You\'re a handler on this application but don\'t have an active SeAT access grant. Grant yourself temporary view access to the applicant\'s character data.',
    'access_grant_now_btn'    => 'Grant access now',
    'access_granted_now'      => 'Temporary SeAT access granted. The character links should appear below.',
    'access_grant_failed'     => 'Could not grant access. Check that the applicant has a resolvable character and that the feature is enabled (Settings → Recruiter Access).',
    'access_feature_off'      => 'Recruiter Access is disabled. Enable it in Settings → Recruiter Access first.',
    'access_not_handler'      => 'You must be a handler on this application to grant yourself access.',

    // Outcome (did the accepted applicant actually join?)
    'outcome_label'    => 'Outcome',
    'outcome_joined'   => 'Joined corp',
    'outcome_pending'  => 'Not joined yet',
    'outcome_late'     => 'Not joined (3+ days)',
    'outcome_ghosted'  => 'Ghosted (14+ days)',

    // Public tracking link (sidebar card on the admin detail view)
    'public_tracking_link'      => 'Public tracking link',
    'public_tracking_link_help' => 'Share with the applicant. They can check their status without logging in. Notes and recruiter comments are never shown.',
    'open_in_new_tab'           => 'Open in new tab',

    // Re-applicant prior history card (CWM Round-3 surfacing)
    'prior_history_heading'      => 'Prior history with this corporation',
    'prior_history_body'         => 'This applicant has :n previously accepted application(s) to this corporation. Their prior contribution history is summarised below.',
    'never_joined'               => 'Never joined',
    'returning_player'           => 'Returning player',
    'returning_player_title'     => 'Accepted to this corporation before (:n prior accepted application(s)). See Prior history below.',
    'view_application'           => 'View application',
    'prior_contribution_label'   => 'Prior contribution',
    'lifetime_contributed'       => 'lifetime contributed',
    'percentile_last_3_months'   => 'rank in corp (last 3 months)',

    // Form
    'apply_title'         => 'Apply to Corporation',
    'apply_submit'        => 'Submit Application',
    'apply_confirmation'  => 'Application Submitted',
    'apply_confirmation_text' => 'Your application has been submitted successfully. A recruiter will review it shortly.',
    'apply_already_pending'   => 'You already have a pending application.',
];

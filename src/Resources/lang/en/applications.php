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
    'access_link_sheet'       => 'Sheet',
    'access_link_wallet'      => 'Wallet',
    'access_link_mail'        => 'Mail',
    'access_link_assets'      => 'Assets',
    'access_link_skills'      => 'Skills',
    'access_panel_footnote'   => 'These deep links rely on SeAT\'s native permission system. If a link 403s, the SeAT permission for that page isn\'t in your configured set (Settings → Recruiter Access).',

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

<?php

return [
    // Public landing
    'apply_now'             => 'Apply Now',
    'recruiting'            => 'Recruiting',
    'about_corp'            => 'About the Corporation',
    'login_required_to_apply' => 'You will be redirected to log in via EVE SSO when you click Apply.',
    // Public-landing footer credit. HR Manager is hyperlinked so corp
    // directors who land on a public page can find the plugin. Keep the
    // string short — both classic and minimal templates render this on a
    // single muted footer line. Holds raw HTML so the templates load it
    // with {!! trans(...) !!}, not {{ trans(...) }}.
    'footer_credit'         => 'Recruitment powered by <a href="https://github.com/MattFalahe/HR-Manager" target="_blank" rel="noopener" style="color:inherit; text-decoration: underline; opacity: 0.85;">HR Manager</a> · Built for SeAT v5 · Recruit. Assess. Retain.',
    // Legacy alias retained so any published override of the templates
    // from an earlier dev install does not break before its owner pulls
    // the renamed key.
    'powered_by_seat'       => 'Recruitment powered by <a href="https://github.com/MattFalahe/HR-Manager" target="_blank" rel="noopener" style="color:inherit; text-decoration: underline; opacity: 0.85;">HR Manager</a> · Built for SeAT v5 · Recruit. Assess. Retain.',

    // Apply page (post-auth)
    'apply_title'           => 'Application',
    'applying_to'           => 'Applying to',
    'as_character'          => 'as',
    // Linked-characters card on the apply form
    'linked_chars_heading'  => 'Your linked characters',
    'linked_chars_body'     => 'These are the characters on your SeAT account that recruiters can review with your application. Linking your alts gives a complete picture and usually speeds up a decision.',
    'linked_chars_main'     => 'Main',
    'linked_chars_add_btn'  => 'Link another character',
    'linked_chars_help'     => 'Optional. You will be sent to EVE Online SSO to log in the character, then returned here. You can submit with just your main if you prefer.',
    'link_discord_now_btn'  => 'Link Discord now',
    'link_discord_now_help' => 'Optional. Opens the SeAT Connector page in a new tab so you can link your Discord account and join the server. You can also do this after you submit.',
    'eligibility_passed'    => 'You meet all eligibility requirements.',
    'eligibility_failed_heading' => 'You do not currently meet all eligibility requirements:',
    'eligibility_failed_subtext' => 'You can still submit your application — a recruiter will review it manually.',
    'eligibility_override_label' => 'I understand; submit my application for manual review',
    'eligibility_failed'    => 'Your application requires the manual-review checkbox to be ticked.',
    'eligibility_override_manual_review_label' => 'I understand my application needs manual review — a recruiter will check my details by hand.',

    // Manual-review banner shown when the applicant took the
    // ?manual_review=1 path off the stalled hydrating screen, OR
    // when eligibility evaluation hit data-missing failures (rules
    // we couldn\'t check because SeAT data wasn\'t loaded).
    'manual_review_heading' => 'Submitting for manual review',
    'manual_review_body'    => 'SeAT could not load all your character data in time. The eligibility checks below could not be fully run — a recruiter will verify your details manually after submission.',
    'manual_review_subtext' => 'Your application will be flagged as "manual review" so a recruiter knows to double-check the items below.',
    'manual_review_data_missing_chip' => 'Not loaded',

    // Discord link CTAs surfaced inside the eligibility failure box
    // when the require_seat_connector rule failed. Connector path used
    // when warlof/seat-connector is installed (preferred — establishes
    // a real linked identity). Invite path is the fallback when
    // Connector is absent but the landing has a Discord invite URL —
    // gets the applicant into the server so a recruiter can finish
    // the link manually.
    'link_discord_via_connector' => 'Link Discord via SeAT Connector',
    'link_discord_help'          => 'Opens in a new tab. After linking, return here and refresh to re-evaluate eligibility.',
    'join_discord_invite'        => 'Join our Discord server',
    'join_discord_invite_help'   => 'Opens in a new tab. A recruiter will help you link your identity once you join.',
    'already_pending'       => 'You already have a pending application. Wait for it to be reviewed.',
    'no_character_heading'  => 'Link an EVE character first',
    'no_character_body'     => 'You need at least one EVE character linked to your SeAT account before applying. Add one from your SeAT profile, then come back.',
    'no_template_heading'   => 'This recruitment page is not accepting applications right now',
    'no_template_body'      => 'The administrator has not configured an application form yet. Try again later.',

    // Hydrating screen — shown when SeAT hasn't finished loading the
    // character data the eligibility rules need (new SSO login race).
    // Tone is playful so first-time applicants don't read a 10-second
    // wait as "I was rejected".
    'hydrating_heading'        => 'Loading your character data',
    'hydrating_body'           => 'Just bribing the CCP devs to release your character data. This usually takes 15-90 seconds — don\'t close the tab.',
    'hydrating_signal_security_status'     => 'Security status',
    'hydrating_signal_skill_points'        => 'Skill points',
    'hydrating_signal_character_age'       => 'Character age',
    'hydrating_signal_corporation_history' => 'Corp history',
    'hydrating_signal_affiliation'         => 'Current alliance',
    'hydrating_status_start' => 'Hamsters spinning up the wheel...',
    'hydrating_status_1'     => 'Reading skill book labels in Amarrian...',
    'hydrating_status_2'     => 'Convincing ESI that yes, you really do exist...',
    'hydrating_status_3'     => 'Calculating how many ISK you definitely should have by now...',
    'hydrating_status_4'     => 'Cross-referencing your kill mail with your excuse log...',
    'hydrating_status_5'     => 'Asking CONCORD politely for your security paperwork...',
    'hydrating_status_6'     => 'Compiling your corp history into something legally defensible...',
    'hydrating_timeout_note' => 'The page will refresh automatically once SeAT has your data. No clicking required.',
    'hydrating_stalled_heading'      => 'The hamsters ran out of coffee',
    'hydrating_stalled_body'         => 'Without coffee the hamsters can\'t reach the CCP devs, so your character data is still not loaded. You can submit your application for manual review — a recruiter will check your details by hand — or retry the loader in a few minutes.',
    'hydrating_stalled_manual_review' => 'Submit for manual review',
    'hydrating_stalled_retry'        => 'Retry loader',
    'hydrating_stalled_back'         => 'Back to landing',

    // Tiny interstitial rendered after Apply Now to prune browser
    // cookies (Cloudflare bot tokens, XSRF rotations, analytics) that
    // accumulate over many visits and push the EVE SSO redirect
    // headers over the front proxy's per-request limit.
    'apply_redirect_title'         => 'Redirecting to application',
    'apply_redirect_heading'       => 'Preparing your application',
    'apply_redirect_body'          => 'Tidying up the browser tabs before EVE SSO. This takes a moment.',
    'apply_redirect_fallback_pre'  => 'Not redirecting?',
    'apply_redirect_fallback_link' => 'Click here to continue',
    // Director-only nudges on the no-template page. Visible only to users
    // holding hr-manager.director — applicants never see these.
    'director_hint_title'   => 'Director shortcut',
    'director_hint_body'    => 'This landing is published but has no application form bound. Edit the landing and pick a Default Template, or create a new template first (must be scoped to this landing\'s corporation and marked Active).',
    'bind_template_btn'     => 'Edit landing & bind template',
    'create_template_btn'   => 'Create new template',
    'submit_application'    => 'Submit Application',
    'back_to_landing'       => 'Back to landing',

    // Confirmation
    'submitted_heading'     => 'Application submitted',
    'submitted_body'        => 'Your application has been submitted. A recruiter will review it shortly.',
    'next_steps'            => 'Next steps',
    'next_steps_fallback'   => 'Your application is in the queue. A recruiter will reach out once they have reviewed it.',
    'join_discord_invite'   => 'Join our Discord while we review your application:',
    'connect_discord_seat'  => 'Connect your Discord through SeAT to be auto-roled when accepted:',
    'open_discord'          => 'Open Discord',
    'open_seat_identities'  => 'Open SeAT identities',
    'application_id'        => 'Application ID',

    // Public applicant tracking page (/recruit/track/{token})
    'track_title'             => 'Application status',
    'track_submitted_label'   => 'Submitted',
    'track_timeline_heading'  => 'Progress',
    'track_no_history'        => 'No status changes yet.',
    'track_answers_heading'   => 'Your answers',
    'track_no_answers'        => 'No answers recorded.',
    'track_joined_heading'    => 'Welcome aboard!',
    'track_joined_body'       => 'You joined the corporation on :date. Congratulations.',
    'track_accepted_heading'  => 'You are accepted',
    'track_accepted_body'     => 'Apply to the corporation in-game when you are ready. We will detect the join automatically.',
    'track_not_joined_heading'    => 'Waiting for you to join',
    'track_not_joined_body'       => 'Your application was accepted but we have not seen you in the corporation yet. Apply in-game from the corporation finder to join.',

    // Surfacing of the tracking link on the post-submit confirmation page
    'track_your_application'      => 'Track your application',
    'track_your_application_help' => 'Bookmark this link to check your status anytime, without logging in. The link is private to you.',
    'open_tracking_page'          => 'Open status page',
];

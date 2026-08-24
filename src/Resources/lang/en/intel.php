<?php

return [
    'title' => 'Intel Database',

    // Page intro banner
    'intro_what_label' => 'What this page does',
    'intro_what_body'  => 'A long-memory <strong>intel database</strong> for EVE characters — even ones never in your corp, who left long ago, or who you watch for coalition situational awareness. Director-tier by default; can be shared with recruiters per-note when the install setting allows. Notes scope per-corp or globally and accept tags (spy / fc / industrialist / etc.) for filtering.',
    'intro_visibility_director' => 'You see all intel · director access',
    'intro_visibility_recruiter' => 'Shared notes only · recruiter access',
    'intro_when_label' => 'When to use',
    'intro_when_1'     => 'Recording observations about an EVE character regardless of whether they\'re in your corp — drama, FCs you respect, known scammers, alts you\'ve confirmed',
    'intro_when_2'     => 'Sharing a note with recruiters during applications: when an applicant has prior history, the intel surfaces on the application detail page automatically',
    'intro_when_3'     => 'Long-term institutional memory across corp moves, leadership changes, and multi-year campaigns',

    // Add form
    'add_note'              => 'Add Intel Note',
    'add_note_for'          => 'Add note for :name',
    'input_label'           => 'Main character',
    'input_placeholder'     => 'e.g. "John Smith" or 90456792',
    'input_help'            => 'A character name OR a numeric ID. Names resolve via SeAT cache + CCP ESI.',
    'alts_label'            => 'Possible alts (one per line)',
    'alts_placeholder'      => "Suspected Alt One\nSuspected Alt Two\n90456792",
    'alts_help'             => 'Files the same note against each of these too, annotated as a possible alt of the main above. HR cannot prove an alt link for someone who was never in SeAT (EVE\'s API has no account concept), so these are recorded as CLAIMS: if both characters later register, HR confirms the link when they share an account, or flags it as refuted when they turn out to be different people.',
    'alt_links_added'       => 'Recorded :count possible alt link(s) against :main — HR will confirm or refute them if these characters register in SeAT.',
    'scope_label'           => 'Scope',
    'scope_global'          => 'Global (every corp you manage)',
    'scope_corp'            => 'Corp-scoped',
    'expires_label'         => 'Expires',
    'body_label'            => 'Note',
    'body_placeholder'      => 'What do you want recorded about this character? Be factual — these notes are durable and may be read by recruiters if shared.',
    'tags_label'            => 'Tags',
    'tags_placeholder'      => 'comma-separated: spy, fc, industrialist',
    'tags_help'             => 'Suggested',
    'recruiter_visible_label' => 'Share with recruiters',
    'recruiter_visible_help'  => 'When ticked AND the install-level recruiter-view setting is on, recruiters can see this note. Authors always see their own.',
    'save_note'             => 'Save Note',

    // Index
    'recent_notes'          => 'Recent Notes',
    'all_tags'              => 'All tags',
    'search_placeholder'    => 'Search name / ID / body...',
    'empty'                 => 'No intel notes yet. Add the first one above.',
    'character_col'         => 'Character',
    'scope_col'             => 'Scope',
    'body_col'              => 'Note',
    'tags_col'              => 'Tags',
    'added_col'             => 'Added',
    'shared_with_recruiters' => 'Shared with recruiters',
    'shared_with_recruiters_short' => 'Shared',

    // Show
    'character_intel'        => 'Intel — :name',
    'back_to_index'          => 'Back to Intel index',
    'notes_for_character'    => 'Notes for this character',
    'no_notes_for_character' => 'No intel notes for this character yet.',

    // The whole human, not just the character on screen
    'same_human_heading'   => 'Same human',
    'same_account_intro'   => 'Other characters on this SeAT account or HR player identity. Proven, not claimed.',
    'claimed_alts_intro'   => 'Claimed alt links. A director asserted these; they are not proof, and each settles on its own once both characters appear in SeAT.',
    'claim_is_alt_of'      => 'Claimed to be a possible alt of',
    'claim_has_alt'        => 'Claimed as a possible alt of this character:',

    'account_notes_heading' => "Notes on this person's other characters",
    'account_notes_intro'   => 'Intel is filed per character, so these are about the same human but recorded elsewhere. Shown here so a dossier is one place rather than several.',
    'account_note_count'    => '{1}:count note|[2,*]:count notes',

    'player_panel_heading' => '{1}:count intel note on this player|[2,*]:count intel notes on this player',
    'player_panel_intro'   => "Filed against this person's characters. Shown here because a profile is a whole account, and a note written on one character is about the same human.",
    // Writing a player note straight into the intel database instead
    'as_intel_label'        => 'Record this in the intel database instead',
    'as_intel_help'         => "Files it against this player's main character rather than as a player note. Intel outlives their membership, so it stays readable after they leave, and it shows on every one of their characters. It is stored in one place only, not copied, so there is no second version to keep in step. Scoped to this corp, and never shared with recruiters.",
    'as_intel_saved'        => 'Recorded in the intel database against :name.',
    'as_intel_no_character' => 'Nothing was saved: this player has no character HR can file intel against. Intel is filed per character, and this account has none HR can see.',
    'as_intel_failed'       => 'Could not write to the intel database. Check the SeAT logs.',

    'app_view_all'       => 'Open the dossier to read all :count',
    'read_full'          => 'read full',
    'open_dossier'       => 'Open',
    'open_dossier_title' => "Open this character's intel dossier",
    'added_by'               => 'Added by',
    'expires_at'             => 'expires',
    'confirm_delete'         => 'Delete this intel note?',

    // Flash
    'note_added'             => 'Intel note added.',
    'include_alts'           => 'Also file this note against the character\'s known alts',
    'include_alts_help'      => 'Files the same note on every other character proven to be the SAME person — via a shared SeAT account or an HR player identity — so the intel surfaces whichever alt a recruiter looks up. Never spans different people, and does nothing when the character isn\'t linked to an account HR knows.',
    'alts_also_added'        => 'Also filed against :count alt(s) from the same account: :names.',
    'extra_added'            => 'Also filed against :count more from your list: :names.',
    'extra_failed'           => 'Could not resolve (skipped): :names.',
    'note_added_with_hit'    => 'Intel note added. Heads up: this character is currently inside a corp you watch. A match alert was sent to that corp\'s webhook.',
    'note_removed'           => 'Intel note removed.',

    // Application detail card
    'app_intel_heading'      => 'Intel on this applicant',
    'app_intel_body'         => ':n note(s) in the intel database. Review before deciding.',
    'app_no_intel'           => 'No prior intel on this applicant in the database.',
];

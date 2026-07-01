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
    'input_label'           => 'Character name or ID',
    'input_placeholder'     => 'e.g. "John Smith" or 90456792',
    'input_help'            => 'Type a character name OR a numeric ID. Names resolve via SeAT cache + CCP ESI.',
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
    'added_by'               => 'Added by',
    'expires_at'             => 'expires',
    'confirm_delete'         => 'Delete this intel note?',

    // Flash
    'note_added'             => 'Intel note added.',
    'note_added_with_hit'    => 'Intel note added. Heads up: this character is currently inside a corp you watch. A match alert was sent to that corp\'s webhook.',
    'note_removed'           => 'Intel note removed.',

    // Application detail card
    'app_intel_heading'      => 'Intel on this applicant',
    'app_intel_body'         => ':n note(s) in the intel database. Review before deciding.',
    'app_no_intel'           => 'No prior intel on this applicant in the database.',
];

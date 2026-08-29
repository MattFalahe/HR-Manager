<?php

return [
    'title' => 'Former Members',

    'intro_what_label' => 'What this page does',
    'intro_what_body'  => 'A frozen record of people who <strong>used to be</strong> in your corps. Figures are captured on the day someone leaves, not worked out afterwards, because a departing member often revokes their ESI key and their history stops being readable. Nothing here updates: it is what was true when they left. If somebody <strong>rejoins</strong>, they drop off this page on their own and go back to Members and Players, where their data resumes updating.',

    'list_heading'       => 'People who have left',
    'search_placeholder' => 'Name or character ID',
    'search'             => 'Search',
    'open'               => 'Open',
    'back_to_index'      => 'Back to Former Members',

    'col_person' => 'Person',
    'col_corps'  => 'Was in',
    'col_tenure' => 'Tenure',
    'col_left'   => 'Left',
    'col_how'    => 'How',

    'days'    => 'days',
    'unknown' => 'not known',

    'char_count'         => '{1}:count character|[2,*]:count characters',
    'badge_blacklisted'  => 'blacklisted',
    'badge_reconstructed'      => 'reconstructed',
    'badge_reconstructed_help' => 'Rebuilt after the fact from what survived in SeAT, so it is partial. It was not captured on the day they left.',

    'departure_resigned' => 'Left',
    'departure_purged'   => 'Purged',
    'departure_kicked'   => 'Removed',
    'departure_unknown'  => 'Not known',

    'empty'      => 'No former members recorded yet.',
    'empty_help' => 'Departures are recorded from the moment this feature is installed. To rebuild records for people who left <em>before</em> that, run <code>hr-manager:backfill-member-archives</code>; it reconstructs what it can from SeAT\'s corporation histories and marks every row as reconstructed.',

    'rejoin_note' => 'Anyone who rejoins disappears from this list automatically and reappears under Members and Players. Their archived record stays as the history of the membership that ended.',

    'reconstructed_notice' => '{1}:count record here was rebuilt after the fact and is partial.|[2,*]:count records here were rebuilt after the fact and are partial.',
    'reconstructed_heading' => 'Rebuilt after the fact',
    'reconstructed_body'    => 'This was reconstructed from SeAT\'s corporation histories rather than captured on the day they left, so it holds only what survived. Contribution figures and the reason they left are usually missing, and what is present may be incomplete. Treat it as a pointer, not as a full account.',

    // Detail page
    'record_for'  => 'Former member: :name',
    'in_corp'     => 'in',
    'joined'      => 'Joined',
    'left'        => 'Left',
    'tenure'      => 'Tenure',
    'went_to'     => 'Went to',
    'how_they_left' => 'How they left',

    'tier_at_departure'  => 'Tier when they left',
    'class_at_departure' => 'Status when they left',
    'wallet_contributed' => 'Wallet contributed',
    'mining_contributed' => 'Ore mined (units)',

    'token_state'     => 'ESI token',
    'token_live'      => 'Still valid',
    'token_lost'      => 'Already revoked',
    'token_lost_help' => 'Their ESI token was already gone when this record was taken, so the figures above are only as complete as the last successful sync before that. This is the reason the record is frozen at all: waiting would have lost more.',

    'notes_heading'         => 'Notes',
    'no_notes'              => 'No notes were written about this player.',
    'no_notes_unregistered' => 'No notes: this person never had a SeAT account, and player notes are filed against an account.',

    'intel_heading' => 'Intel',
    'no_intel'      => 'No intel on any of their characters.',
];

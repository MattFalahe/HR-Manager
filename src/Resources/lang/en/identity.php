<?php

return [
    'profile_title'         => 'Player Identity: :name',
    'linked_to_seat_user'   => 'Linked to SeAT user',
    'unlinked_ghost'        => 'No SeAT account (ghost identity)',
    'alts_count'            => ':n character(s) currently owned',

    'characters_heading'    => 'Characters owned',
    'no_characters'         => 'No characters currently mapped to this identity.',
    'reason_label'          => 'Reason',
    'current'               => 'Current',
    'historical'            => 'Historical',

    // Humanized mapping reason codes (keyed reason_<code>). Shown in
    // place of the raw snake_case reason so directors see plain
    // language. Unknown codes fall back to the raw string.
    'reason_auto_seat'          => 'Auto-linked from SeAT account',
    'reason_auto_member_track'  => 'Auto-linked from corp member tracking',
    'reason_ghost_unregistered' => 'Unregistered character (no SeAT account)',
    'reason_manual'             => 'Manually assigned',
    'reason_account_takeover'   => 'Reassigned (account takeover)',
    'reason_merge'              => 'Moved here by an identity merge',

    // Reassign
    'reassign_btn'          => 'Reassign',
    'reassign_btn_help'     => 'Only needed if this character was SOLD or transferred to a DIFFERENT human. Auto-linked characters on the same SeAT account never need reassigning.',
    'reassign_title'        => 'Reassign :name to a different identity',
    'reassign_help'         => 'Use this when a character has been passed from one player to another (account takeover, character sale, etc.). The current mapping closes; a new mapping opens. The audit trail is preserved.',
    'target_label'          => 'Target identity',
    'target_placeholder'    => 'Identity ID, u:<seat_user_id>, or character name',
    'target_help'           => 'Type the numeric identity ID, "u:42" to reference SeAT user 42, or a character name (we will resolve it).',
    'reason_input_label'    => 'Reason for reassignment',
    'reason_input_placeholder' => 'e.g. "Character sold to John on Discord 2026-06-06"',
    'cancel'                => 'Cancel',
    'reassign_confirm'      => 'Reassign',
    'reassign_done'         => 'Character reassigned. Audit trail updated.',
    'reassign_target_not_found' => 'Could not resolve the target identity.',
    'reassign_failed'       => 'Reassignment failed. Check the logs.',

    // Merge
    'merge_heading'         => 'Merge another identity into this one',
    'merge_help'            => 'Combine two identities into one. Use when you discover that two records actually represent the same human (e.g. they re-authed under a new SeAT account after losing the old one). All mappings from the merged-from identity get re-pointed at this one and the audit trail is preserved.',
    'merge_from_label'      => 'Identity to merge FROM',
    'merge_from_placeholder' => 'Identity ID (numeric)',
    'merge_from_help'       => 'The identity ID that will be merged into this profile and then soft-deleted.',
    'merge_notes_label'     => 'Merge notes (optional)',
    'merge_btn'             => 'Merge into this identity',
    'merge_confirm'         => 'Merge that identity into this one? The other identity will be soft-deleted; characters re-point here.',
    'merge_done'            => 'Identities merged.',
    'merge_failed'          => 'Merge failed. The from-identity may not exist or already be merged.',
];

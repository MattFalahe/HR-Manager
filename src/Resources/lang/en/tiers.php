<?php

return [
    // Tier slug -> display label. Keys must match TierLevel::SLUGS so the
    // Support\TierLevel::label() helper can resolve them.
    'applicant'       => 'Applicant',
    'member'          => 'Member',
    'junior_officer'  => 'Junior Officer',
    'senior_officer'  => 'Senior Officer',
    'director'        => 'Director',

    // Used in tier-mapping forms + player profile badges
    'tier'            => 'Tier',
    'tier_level'      => 'Tier Level',
    'threshold_days'  => 'Inactivity Threshold (days)',
    'threshold_help'  => 'Days without activity before this tier is flagged as inactive. Blank = use the tier default.',
    'no_threshold'    => 'no threshold',
    'not_set'         => 'Not set',
    'unmapped'        => 'Unmapped',
];

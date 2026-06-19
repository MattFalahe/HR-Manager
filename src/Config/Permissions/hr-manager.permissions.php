<?php

/**
 * HR Manager Permissions
 *
 * 4-tier permission model:
 *   view      - Base access (help page only)
 *   recruiter - View/process applications, manage notes, character checks
 *   director  - Member + player profiles (sensitive data), Corp Health, templates, accept/reject applications
 *   admin     - Full control: settings, webhooks, delete actions
 *
 * Higher tiers inherit all lower tier permissions via controller logic.
 */
return [
    'view' => [
        'label' => 'hr-manager::permissions.view_label',
        'description' => 'hr-manager::permissions.view_description',
        'division' => 'corporation',
    ],

    'recruiter' => [
        'label' => 'hr-manager::permissions.recruiter_label',
        'description' => 'hr-manager::permissions.recruiter_description',
        'division' => 'corporation',
    ],

    'director' => [
        'label' => 'hr-manager::permissions.director_label',
        'description' => 'hr-manager::permissions.director_description',
        'division' => 'corporation',
    ],

    'admin' => [
        'label' => 'hr-manager::permissions.admin_label',
        'description' => 'hr-manager::permissions.admin_description',
        'division' => 'corporation',
    ],
];

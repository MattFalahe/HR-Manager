<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-corp onboarding welcome template: the Markdown body (with {member} /
 * {corp} variables) plus the Discord role snowflakes to @-mention as the
 * new-member-care team. A corp with no row falls back to the hardcoded default
 * in OnboardingService.
 */
class OnboardingTemplate extends Model
{
    protected $table = 'hr_manager_onboarding_templates';

    protected $fillable = [
        'corporation_id',
        'is_enabled',
        'body',
        'mention_role_ids',
    ];

    protected $casts = [
        'mention_role_ids' => 'array',
        'is_enabled'       => 'boolean',
    ];
}

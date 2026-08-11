<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A queued onboarding welcome for one newly-joined person. Created on the join,
 * fired once after send_after (the Connector-role grace window). sent_at is the
 * fired-once marker.
 */
class OnboardingWelcome extends Model
{
    protected $table = 'hr_manager_onboarding_welcomes';

    protected $fillable = [
        'corporation_id',
        'user_id',
        'character_id',
        'send_after',
        'sent_at',
    ];

    protected $casts = [
        'send_after' => 'datetime',
        'sent_at'    => 'datetime',
    ];
}

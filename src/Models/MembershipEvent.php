<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A logged corp membership change (join / leave) written by
 * MembershipChangeService. The no_application joins double as a review queue:
 * unreviewed ones (reviewed_at null) surface on Corp Health for a director to
 * acknowledge.
 */
class MembershipEvent extends Model
{
    public const CHANGE_JOINED     = 'joined';
    public const CHANGE_LEFT       = 'left';
    public const CHANGE_REGISTERED = 'registered'; // a previously-unregistered member linked a SeAT account

    public const CLASS_KNOWN_ALT      = 'known_alt';
    public const CLASS_APPLIED        = 'applied';
    public const CLASS_NO_APPLICATION = 'no_application';
    public const CLASS_UNREGISTERED   = 'unregistered';

    protected $table = 'hr_manager_membership_events';

    protected $fillable = [
        'corporation_id',
        'character_id',
        'main_character_id',
        'change_type',
        'classification',
        'player_still_present',
        'occurred_at',
        'reviewed_at',
        'reviewed_by',
        'review_note',
    ];

    protected $casts = [
        'corporation_id'       => 'integer',
        'character_id'         => 'integer',
        'main_character_id'    => 'integer',
        'player_still_present' => 'boolean',
        'occurred_at'          => 'datetime',
        'reviewed_at'          => 'datetime',
        'reviewed_by'          => 'integer',
    ];

    /** Unreviewed "joined without a valid application" flags — the review queue. */
    public function scopeNeedsReview($query)
    {
        return $query->where('classification', self::CLASS_NO_APPLICATION)
            ->whereNull('reviewed_at');
    }
}

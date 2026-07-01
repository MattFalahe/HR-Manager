<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HR's forward-only snapshot of a tracked corp's roster. Diffed against SeAT's
 * live roster by MembershipChangeService to detect joins + leaves. main_character_id
 * is captured at seed/join time so a leave can still name the person.
 */
class CorpMember extends Model
{
    protected $table = 'hr_manager_corp_members';

    protected $fillable = [
        'corporation_id',
        'character_id',
        'main_character_id',
        'is_registered',
        'registered_at',
        'first_seen_at',
    ];

    protected $casts = [
        'corporation_id'    => 'integer',
        'character_id'      => 'integer',
        'main_character_id' => 'integer',
        'is_registered'     => 'boolean',
        'registered_at'     => 'datetime',
        'first_seen_at'     => 'datetime',
    ];

    /** Current members not linked to a SeAT account — the pending-registration flag. */
    public function scopeUnregistered($query)
    {
        return $query->where('is_registered', false);
    }
}

<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Web\Models\User;

class CharacterIdentityMapping extends Model
{
    protected $table = 'hr_manager_character_identity_mappings';

    public const REASON_AUTO_SEAT          = 'auto_seat';
    public const REASON_AUTO_MEMBER_TRACK  = 'auto_member_track';
    public const REASON_GHOST_UNREGISTERED = 'ghost_unregistered';
    public const REASON_MANUAL             = 'manual';
    public const REASON_ACCOUNT_TAKEOVER   = 'account_takeover';
    public const REASON_MERGE              = 'merge';

    protected $fillable = [
        'character_id',
        'player_identity_id',
        'effective_from',
        'effective_to',
        'assigned_by',
        'reason',
        'notes',
    ];

    protected $casts = [
        'character_id'       => 'integer',
        'player_identity_id' => 'integer',
        'assigned_by'        => 'integer',
        'effective_from'     => 'datetime',
        'effective_to'       => 'datetime',
    ];

    public function identity()
    {
        return $this->belongsTo(PlayerIdentity::class, 'player_identity_id');
    }

    public function assignedByUser()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('effective_to');
    }

    public function scopeForCharacter($query, int $characterId)
    {
        return $query->where('character_id', $characterId);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->effective_to === null;
    }
}

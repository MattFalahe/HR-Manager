<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Seat\Web\Models\User;

class PlayerIdentity extends Model
{
    use SoftDeletes;

    protected $table = 'hr_manager_player_identities';

    protected $fillable = [
        'primary_name',
        'seat_user_id',
        'merged_into_id',
        'merged_by',
        'merged_at',
        'merge_notes',
        'notes_summary',
    ];

    protected $casts = [
        'seat_user_id'   => 'integer',
        'merged_into_id' => 'integer',
        'merged_by'      => 'integer',
        'merged_at'      => 'datetime',
    ];

    public function mappings()
    {
        return $this->hasMany(CharacterIdentityMapping::class, 'player_identity_id')
            ->orderBy('effective_from');
    }

    /**
     * Currently-owned character mappings (effective_to IS NULL).
     */
    public function currentMappings()
    {
        return $this->hasMany(CharacterIdentityMapping::class, 'player_identity_id')
            ->whereNull('effective_to');
    }

    public function seatUser()
    {
        return $this->belongsTo(User::class, 'seat_user_id');
    }

    /**
     * Character IDs this identity currently owns.
     *
     * @return array<int>
     */
    /** Folded into another identity — kept so its SeAT account still resolves. */
    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    public function mergedInto()
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function currentCharacterIds(): array
    {
        return $this->currentMappings()->pluck('character_id')->map(fn($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Character IDs this identity has EVER owned (current + historical).
     *
     * @return array<int>
     */
    public function allCharacterIds(): array
    {
        return $this->mappings()->pluck('character_id')->unique()->map(fn($id) => (int) $id)->all();
    }
}

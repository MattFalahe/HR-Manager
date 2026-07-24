<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

class MemberAssessment extends Model
{
    protected $table = 'hr_manager_member_assessments';

    protected $fillable = [
        'character_id',
        'corporation_id',
        'total_mining_value',
        'total_mining_tax',
        'tax_compliance_pct',
        'total_ratting_income',
        'ore_preferences',
        'active_months',
        'last_mining_date',
        'last_ratting_date',
        'security_status',
        'total_sp',
        'employment_count',
        'member_since',
        'lifetime_contribution',
        'net_position_6mo',
        'wallet_compliance_pct_6mo',
        'last_contribution_at',
        'cached_at',
    ];

    protected $casts = [
        'ore_preferences'      => 'array',
        'last_mining_date'     => 'date',
        'last_ratting_date'    => 'date',
        'member_since'         => 'datetime',
        'cached_at'            => 'datetime',
        'total_mining_value'   => 'decimal:2',
        'total_mining_tax'     => 'decimal:2',
        'total_ratting_income' => 'decimal:2',
        'tax_compliance_pct'   => 'decimal:2',
        'security_status'      => 'decimal:2',
        'lifetime_contribution' => 'decimal:2',
        'net_position_6mo'      => 'decimal:2',
        'wallet_compliance_pct_6mo' => 'decimal:2',
        'last_contribution_at'  => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Character\CharacterInfo::class, 'character_id', 'character_id');
    }

    public function scopeForCharacter($query, int $characterId)
    {
        return $query->where('character_id', $characterId);
    }

    public function isFresh(int $minutes = 60): bool
    {
        return $this->cached_at && $this->cached_at->greaterThan(now()->subMinutes($minutes));
    }
}

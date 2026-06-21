<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

class RoleTierMapping extends Model
{
    protected $table = 'hr_manager_role_tier_mappings';

    protected $fillable = [
        'corporation_id',
        'discord_role_id',
        'tier_level',
        'threshold_days',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tier_level'     => 'integer',
        'threshold_days' => 'integer',
    ];

    /**
     * Scope to mappings for a given corp, plus global mappings (corp_id NULL)
     * which act as fallback when no corp-specific mapping exists for a role.
     */
    public function scopeForCorporation($query, ?int $corporationId)
    {
        return $query->where(function ($q) use ($corporationId) {
            if ($corporationId !== null) {
                $q->where('corporation_id', $corporationId);
            }
            $q->orWhereNull('corporation_id');
        });
    }
}

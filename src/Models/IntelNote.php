<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Seat\Web\Models\User;

class IntelNote extends Model
{
    use SoftDeletes;

    protected $table = 'hr_manager_intel_notes';

    protected $fillable = [
        'character_id',
        'character_name',
        'scope_corporation_id',
        'scope_alert_corp_id',
        'scope_alert_sent_at',
        'body',
        'tags',
        'recruiter_visible',
        'author_id',
        'expires_at',
    ];

    protected $casts = [
        'character_id'         => 'integer',
        'scope_corporation_id' => 'integer',
        'scope_alert_corp_id'  => 'integer',
        'scope_alert_sent_at'  => 'datetime',
        'author_id'            => 'integer',
        'tags'                 => 'array',
        'recruiter_visible'    => 'boolean',
        'expires_at'           => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeForCharacter($query, int $characterId)
    {
        return $query->where('character_id', $characterId);
    }

    public function scopeForCorporation($query, int $corporationId)
    {
        return $query->where(function ($q) use ($corporationId) {
            $q->whereNull('scope_corporation_id')
              ->orWhere('scope_corporation_id', $corporationId);
        });
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeRecruiterVisible($query)
    {
        return $query->where('recruiter_visible', true);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}

<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One temporary SeAT role attachment issued to a recruiter for an
 * applicant's character data. See migration docblock for lifecycle.
 */
class RecruiterAccessGrant extends Model
{
    protected $table = 'hr_manager_recruiter_access_grants';

    protected $fillable = [
        'application_id',
        'user_id',
        'role_id',
        'character_ids',
        'permission_set',
        'granted_at',
        'expires_at',
        'revoked_at',
        'revoke_reason',
    ];

    protected $casts = [
        'character_ids'  => 'array',
        'permission_set' => 'array',
        'granted_at'     => 'datetime',
        'expires_at'     => 'datetime',
        'revoked_at'     => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeExpired($query)
    {
        return $query->whereNull('revoked_at')->where('expires_at', '<', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}

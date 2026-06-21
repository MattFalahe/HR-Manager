<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One temporary `seat-connector.view` grant issued to an applicant so
 * they can reach the Connector identity page and link Discord while
 * their application is in flight. See migration docblock for lifecycle.
 */
class ApplicantConnectorGrant extends Model
{
    protected $table = 'hr_manager_applicant_connector_grants';

    protected $fillable = [
        'application_id',
        'user_id',
        'role_id',
        'permission',
        'granted_at',
        'expires_at',
        'revoked_at',
        'revoke_reason',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
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
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}

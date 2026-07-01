<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Best-effort page-view analytics for the public landing pages. IP and
 * user-agent are hashed (SHA-256 of value + app key) so the same visitor
 * counts as one unique without storing the raw IP/UA.
 */
class RecruitmentView extends Model
{
    protected $table = 'hr_manager_recruitment_views';

    protected $fillable = [
        'landing_id',
        'ip_hash',
        'referrer_domain',
        'user_agent_hash',
        'clicked_apply',
        'viewed_at',
    ];

    protected $casts = [
        'clicked_apply' => 'boolean',
        'viewed_at'     => 'datetime',
    ];

    public function landing()
    {
        return $this->belongsTo(RecruitmentLanding::class, 'landing_id');
    }

    public function scopeForLanding($query, int $landingId)
    {
        return $query->where('landing_id', $landingId);
    }

    public function scopeSince($query, \DateTimeInterface $since)
    {
        return $query->where('viewed_at', '>=', $since);
    }
}

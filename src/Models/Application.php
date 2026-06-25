<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Seat\Eveapi\Models\Character\CharacterInfo;

class Application extends Model
{
    use SoftDeletes;

    protected $table = 'hr_manager_applications';

    protected $fillable = [
        'character_id',
        'template_id',
        'corporation_id',
        'landing_id',
        'status',
        'eligibility_passed',
        'eligibility_failures',
        'submitted_at',
        'reviewed_at',
        'decided_at',
        'decided_by',
        'decision_notes',
        'joined_corp_at',
        'joined_corp_id',
        'tracking_token',
    ];

    protected $casts = [
        'submitted_at'         => 'datetime',
        'reviewed_at'          => 'datetime',
        'decided_at'           => 'datetime',
        'joined_corp_at'       => 'datetime',
        'eligibility_passed'   => 'boolean',
        'eligibility_failures' => 'array',
    ];

    /**
     * Public progress page URL — shareable, no auth required. Returns
     * null when tracking_token is empty (very old pre-backfill rows).
     */
    public function getPublicTrackingUrlAttribute(): ?string
    {
        if (!$this->tracking_token) {
            return null;
        }
        return url('/recruit/track/' . $this->tracking_token);
    }

    /**
     * "Did they join the corp?" outcome state for accepted apps:
     *   'joined'        - joined_corp_at is set
     *   'pending'       - accepted recently (<3 days), no join yet
     *   'late'          - accepted 3-13 days ago, no join yet
     *   'ghosted'       - accepted 14+ days ago, no join yet
     *   'not_applicable'- not in 'accepted' state
     */
    public function getJoinOutcomeAttribute(): string
    {
        if ($this->status !== 'accepted') {
            return 'not_applicable';
        }
        if ($this->joined_corp_at) {
            return 'joined';
        }
        if (!$this->decided_at) {
            return 'pending';
        }
        $days = $this->decided_at->diffInDays(now());
        if ($days < 3)  return 'pending';
        if ($days < 14) return 'late';
        return 'ghosted';
    }

    public function template()
    {
        return $this->belongsTo(FormTemplate::class, 'template_id')->withTrashed();
    }

    public function landing()
    {
        return $this->belongsTo(RecruitmentLanding::class, 'landing_id');
    }

    public function answers()
    {
        return $this->hasMany(ApplicationAnswer::class, 'application_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(ApplicationStatusHistory::class, 'application_id')
            ->orderBy('created_at', 'desc');
    }

    public function notes()
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    public function handlers()
    {
        return $this->hasMany(ApplicationHandler::class, 'application_id')
            ->orderBy('joined_at');
    }

    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    public function decider()
    {
        return $this->belongsTo(\Seat\Web\Models\User::class, 'decided_by');
    }

    public function scopeForCorporation($query, int $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['applied', 'under_review', 'interview']);
    }

    public function scopeDecided($query)
    {
        return $query->whereIn('status', ['accepted', 'rejected', 'withdrawn']);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * A still-open application that has gone unactioned for longer than the
     * configured `stale_days` window. Drives the "Stale" badge on the
     * applications list so a review backlog is visible at a glance.
     */
    public function isStale(int $days): bool
    {
        return in_array($this->status, ['applied', 'under_review', 'interview'], true)
            && $this->submitted_at !== null
            && $this->submitted_at->lt(now()->subDays(max(1, $days)));
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['applied', 'under_review', 'interview']);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['accepted', 'rejected', 'withdrawn']);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return self::badgeForStatus($this->status);
    }

    /**
     * Static badge-class mapping. Used by status-history rendering
     * where we have a raw status string from a history row rather
     * than a full Application instance.
     */
    public static function badgeForStatus(?string $status): string
    {
        return match ($status) {
            'applied'      => 'badge-applied',
            'under_review' => 'badge-under-review',
            'interview'    => 'badge-interview',
            'accepted'     => 'badge-accepted',
            'rejected'     => 'badge-rejected',
            'withdrawn'    => 'badge-withdrawn',
            default        => 'badge-secondary',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'applied'      => trans('hr-manager::applications.status_applied'),
            'under_review' => trans('hr-manager::applications.status_under_review'),
            'interview'    => trans('hr-manager::applications.status_interview'),
            'accepted'     => trans('hr-manager::applications.status_accepted'),
            'rejected'     => trans('hr-manager::applications.status_rejected'),
            'withdrawn'    => trans('hr-manager::applications.status_withdrawn'),
            default        => ucfirst($this->status),
        };
    }
}

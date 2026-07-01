<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationStatusHistory extends Model
{
    protected $table = 'hr_manager_application_status_history';

    protected $fillable = [
        'application_id',
        'old_status',
        'new_status',
        'changed_by',
        'comment',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function changer()
    {
        return $this->belongsTo(\Seat\Web\Models\User::class, 'changed_by');
    }

    public function getNewStatusLabelAttribute(): string
    {
        return match ($this->new_status) {
            'applied'      => trans('hr-manager::applications.status_applied'),
            'under_review' => trans('hr-manager::applications.status_under_review'),
            'interview'    => trans('hr-manager::applications.status_interview'),
            'accepted'     => trans('hr-manager::applications.status_accepted'),
            'rejected'     => trans('hr-manager::applications.status_rejected'),
            'withdrawn'    => trans('hr-manager::applications.status_withdrawn'),
            default        => ucfirst($this->new_status),
        };
    }

    public function getNewStatusBadgeClassAttribute(): string
    {
        return match ($this->new_status) {
            'applied'      => 'badge-applied',
            'under_review' => 'badge-under-review',
            'interview'    => 'badge-interview',
            'accepted'     => 'badge-accepted',
            'rejected'     => 'badge-rejected',
            'withdrawn'    => 'badge-withdrawn',
            default        => 'badge-secondary',
        };
    }
}

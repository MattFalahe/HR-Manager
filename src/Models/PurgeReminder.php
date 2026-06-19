<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dedup record for purge reminder dispatch. Cron is safe to run multiple
 * times daily — the unique constraint on (player_status_id, milestone)
 * prevents duplicate reminders.
 */
class PurgeReminder extends Model
{
    protected $table = 'hr_manager_purge_reminders';

    public const MILESTONE_T7       = 't7';
    public const MILESTONE_T3       = 't3';
    public const MILESTONE_T48      = 't48';
    public const MILESTONE_T0       = 't0';
    public const MILESTONE_EXECUTED = 'executed';

    public const ALL_MILESTONES = [
        self::MILESTONE_T7,
        self::MILESTONE_T3,
        self::MILESTONE_T48,
        self::MILESTONE_T0,
        self::MILESTONE_EXECUTED,
    ];

    protected $fillable = [
        'player_status_id',
        'milestone',
        'dispatched_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
    ];

    public function playerStatus()
    {
        return $this->belongsTo(PlayerStatus::class, 'player_status_id');
    }
}

<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerStatus extends Model
{
    protected $table = 'hr_manager_player_status';

    public const STATUS_ACTIVE           = 'active';
    public const STATUS_LOA              = 'loa';
    public const STATUS_MARKED_FOR_PURGE = 'marked_for_purge';

    protected $fillable = [
        'user_id',
        'corporation_id',
        'status',
        'loa_until',
        'purge_scheduled_for',
        'purge_roles_removed_at',
        'purge_left_corp_at',
        'purge_left_corp_to',
        'purge_squads_removed_at',
        'purge_notes',
        'reason',
        'status_set_by',
        'status_set_at',
    ];

    protected $casts = [
        'loa_until'               => 'date',
        'purge_scheduled_for'     => 'date',
        'purge_roles_removed_at'  => 'datetime',
        'purge_left_corp_at'      => 'datetime',
        'purge_squads_removed_at' => 'datetime',
        'purge_left_corp_to'      => 'integer',
        'status_set_at'           => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(\Seat\Web\Models\User::class, 'user_id');
    }

    public function purgeReminders()
    {
        return $this->hasMany(PurgeReminder::class, 'player_status_id');
    }

    /**
     * LOA is "currently in effect" iff the row is flagged LOA AND either
     * loa_until is null (open-ended) or in the future. The classifier uses
     * this to suppress inactivity alerts during a sanctioned absence.
     */
    public function isLoaActive(): bool
    {
        if ($this->status !== self::STATUS_LOA) {
            return false;
        }
        if (!$this->loa_until) {
            return true;
        }
        return $this->loa_until->isFuture() || $this->loa_until->isToday();
    }
}

<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recorded broadcast (FC action) from SeAT Broadcast's
 * pings.broadcast.sent EventBus topic. See migration for the
 * forward-only accumulation contract.
 */
class FcActivity extends Model
{
    protected $table = 'hr_manager_fc_activity';

    protected $fillable = [
        'user_id',
        'character_id',
        'corporation_id',
        'kind',
        'broadcast_type',
        'mention_type',
        'category_group',
        'severity',
        'structure_name',
        'system_name',
        'scheduled_for',
        'is_structure_alert',
        'is_scheduled',
        'event_id',
        'occurred_at',
    ];

    protected $casts = [
        'is_structure_alert' => 'boolean',
        'is_scheduled'       => 'boolean',
        'occurred_at'        => 'datetime',
        'scheduled_for'      => 'datetime',
    ];

    /**
     * Real FC fleet activity — excludes automated structure-defense
     * pings, which aren't someone calling a fleet.
     */
    public function scopeFleetActivity($query)
    {
        return $query->where('is_structure_alert', false);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Reactive broadcasts (pings.broadcast.sent). Legacy rows (added before
     * the formup migration) backfill to kind='broadcast', so this also
     * covers them.
     */
    public function scopeBroadcasts($query)
    {
        return $query->where('kind', 'broadcast');
    }

    /**
     * Proactive formups (pings.formup.scheduled) — an FC scheduling a fleet
     * for a tactical event.
     */
    public function scopeFormups($query)
    {
        return $query->where('kind', 'formup');
    }
}

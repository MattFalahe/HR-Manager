<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persistent timeline event. Sources:
 *   - HR-internal: corp_joined, corp_left, application_*, purge_*,
 *                  tier_changed, classification_changed
 *   - MM subscriber: mining.tax_created etc. (persist-then-invalidate
 *                    upgrade of the original cache-invalidation handler)
 *   - Future: industry milestones, zKill kills, contributor rank changes
 *
 * Player profile renders a chronological timeline. Idempotency key (any
 * stable source identifier) prevents double insertion if MC's EventBus
 * replays an event.
 */
class MemberHistoryEvent extends Model
{
    protected $table = 'hr_manager_member_history_events';

    protected $fillable = [
        'user_id',
        'character_id',
        'corporation_id',
        'event_type',
        'source_plugin',
        'payload',
        'idempotency_key',
        'occurred_at',
        'recorded_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(\Seat\Web\Models\User::class, 'user_id');
    }

    public function character()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Character\CharacterInfo::class, 'character_id', 'character_id');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCharacters($query, array $characterIds)
    {
        return $query->whereIn('character_id', $characterIds);
    }
}

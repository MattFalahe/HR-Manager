<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\MemberHistoryEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Append-only event log for player timelines. Writers (HR-internal +
 * MC EventBus subscribers) call record(); the player profile timeline +
 * future reporting reads back from this table.
 *
 * Idempotency: a stable source_reference (e.g. 'esi-notif:NNN',
 * 'mining-tax:NNN', 'hr-app:NNN') becomes the idempotency_key. Replays
 * of the same event silently no-op via the unique constraint, so MC
 * EventBus retries don't double-record.
 */
class HistoryEventService
{
    /**
     * Record an event. Returns the model on first write, or null on
     * idempotent replay (already recorded under this key).
     *
     * @param string $eventType  Stable dotted key e.g. 'hr.player.joined_corp'
     * @param array $payload     Free-form event data
     * @param array $context     ['user_id', 'character_id', 'corporation_id', 'occurred_at', 'idempotency_key', 'source_plugin']
     */
    public function record(string $eventType, array $payload, array $context = []): ?MemberHistoryEvent
    {
        $occurredAt = $context['occurred_at'] ?? now();
        if (is_string($occurredAt)) {
            $occurredAt = Carbon::parse($occurredAt);
        }

        $data = [
            'user_id'         => $context['user_id'] ?? null,
            'character_id'    => $context['character_id'] ?? null,
            'corporation_id'  => $context['corporation_id'] ?? null,
            'event_type'      => $eventType,
            'source_plugin'   => $context['source_plugin'] ?? 'hr-manager',
            'payload'         => $payload,
            'idempotency_key' => $context['idempotency_key'] ?? null,
            'occurred_at'     => $occurredAt,
            'recorded_at'     => now(),
        ];

        try {
            return MemberHistoryEvent::create($data);
        } catch (QueryException $e) {
            // Unique violation on idempotency_key — already recorded, ignore.
            if (str_contains($e->getMessage(), 'hr_history_idempotency_unique')
                || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                Log::debug('[HR Manager] History event already recorded under idempotency key', [
                    'event'    => $eventType,
                    'key'      => $data['idempotency_key'],
                ]);
                return null;
            }
            throw $e;
        }
    }

    /**
     * Player timeline. Combines user-level events (user_id match), per-character
     * events (character_id IN), and corp-level events for any of those
     * characters' tracked corps.
     */
    public function timelineForPlayer(int $userId, array $characterIds, int $limit = 200): \Illuminate\Database\Eloquent\Collection
    {
        return MemberHistoryEvent::where(function ($q) use ($userId, $characterIds) {
            $q->where('user_id', $userId);
            if (!empty($characterIds)) {
                $q->orWhereIn('character_id', $characterIds);
            }
        })
            ->orderBy('occurred_at', 'desc')
            ->limit($limit)
            ->get();
    }
}

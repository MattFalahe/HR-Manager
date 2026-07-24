<?php

namespace HrManager\Services;

use HrManager\Models\StructureIncident;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Records Structure Manager `structure.alert.*` events into HR's own
 * incident table and rolls them up for the Corp Health Structure Health
 * card. Pure consumer: SM publishes, HR stores + counts. No changes to
 * Structure Manager or Manager Core; HR-side only.
 *
 * Standalone-safe: the writer no-ops without the table, the reader returns
 * empty without it, and the whole feature only populates when Manager Core
 * is present to carry the events.
 */
class StructureIncidentService
{
    /** Incident types that count as "went critical" for the tally. */
    private const CRITICAL_TYPES = [
        'shield_reinforced', 'armor_reinforced', 'hull_reinforced',
        'destroyed', 'fuel_critical',
    ];

    private const REINFORCED_TYPES = [
        'shield_reinforced', 'armor_reinforced', 'hull_reinforced',
    ];

    /**
     * EventBus handler. Registered as the hr.onStructureAlert capability and
     * invoked for every `structure.alert.*` event. 3-arg signature (the event
     * name carries the alert flavour). Idempotent on event_id so a redelivery
     * or SM re-detection never double-counts.
     */
    public function record(string $eventName, string $publisher, array $payload): void
    {
        if (!Schema::hasTable('hr_manager_structure_incidents')) {
            return;
        }

        // structure.alert.armor_reinforced -> armor_reinforced
        $type = str_starts_with($eventName, 'structure.alert.')
            ? substr($eventName, strlen('structure.alert.'))
            : $eventName;

        $corpId = (int) ($payload['corporation_id'] ?? 0);
        if ($corpId <= 0) {
            return; // can't scope it to a corp, drop
        }

        // Per-occurrence unique id from SM's AlertEventEnvelope. Fall back to a
        // composite so a publisher that omits event_id still dedups sanely.
        $eventId = (string) ($payload['event_id']
            ?? $payload['idempotency_key']
            ?? $payload['source_reference']
            ?? ($type . ':' . ($payload['structure_id'] ?? 'na') . ':' . ($payload['eve_time'] ?? '')));

        $occurredAt = now();
        if (!empty($payload['eve_time'])) {
            try {
                $occurredAt = \Carbon\Carbon::parse($payload['eve_time']);
            } catch (\Throwable $e) {
                $occurredAt = now();
            }
        }

        try {
            StructureIncident::updateOrCreate(
                ['event_id' => $eventId],
                [
                    'corporation_id' => $corpId,
                    'structure_id'   => isset($payload['structure_id']) ? (int) $payload['structure_id'] : null,
                    'structure_name' => $payload['structure_name'] ?? null,
                    'incident_type'  => substr($type, 0, 40),
                    'severity'       => isset($payload['severity']) ? substr((string) $payload['severity'], 0, 20) : null,
                    'occurred_at'    => $occurredAt,
                    'payload'        => $payload,
                ]
            );

            // Refresh the Corp Health card so the new count shows without
            // waiting for the per-tab cache to expire.
            app(CorpStatusService::class)->bustCache($corpId);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] StructureIncidentService::record failed: ' . $e->getMessage());
        }
    }

    /**
     * Roll-up for the Structure Health card: how many incidents of each class
     * in the trailing window + the most-hit structure. Reads HR's own table,
     * so it works regardless of MC (just empty when no events have arrived).
     *
     * @return array{available:bool, days:int, reinforced:int, fuel_critical:int,
     *               destroyed:int, total_critical:int, most_hit:?array}
     */
    public function getCorpSummary(int $corporationId, int $days = 90): array
    {
        $empty = [
            'available'      => false,
            'days'           => $days,
            'reinforced'     => 0,
            'fuel_critical'  => 0,
            'destroyed'      => 0,
            'total_critical' => 0,
            'most_hit'       => null,
        ];

        if (!Schema::hasTable('hr_manager_structure_incidents')) {
            return $empty;
        }

        try {
            $since = now()->subDays($days);
            $rows = StructureIncident::where('corporation_id', $corporationId)
                ->where('occurred_at', '>=', $since)
                ->whereIn('incident_type', self::CRITICAL_TYPES)
                ->get(['incident_type', 'structure_id', 'structure_name']);

            if ($rows->isEmpty()) {
                $zero = $empty;
                $zero['available'] = true; // tracking, just nothing yet
                return $zero;
            }

            // Most-hit structure (by incident count) over the window.
            $mostHit = null;
            $byStructure = $rows->whereNotNull('structure_id')->groupBy('structure_id');
            $topCount = 0;
            foreach ($byStructure as $sid => $group) {
                if ($group->count() > $topCount) {
                    $topCount = $group->count();
                    $mostHit = [
                        'name'  => $group->first()->structure_name ?: ('Structure #' . $sid),
                        'count' => $group->count(),
                    ];
                }
            }
            // Only surface a "most hit" when it was hit more than once.
            if ($mostHit !== null && $mostHit['count'] < 2) {
                $mostHit = null;
            }

            return [
                'available'      => true,
                'days'           => $days,
                'reinforced'     => $rows->whereIn('incident_type', self::REINFORCED_TYPES)->count(),
                'fuel_critical'  => $rows->where('incident_type', 'fuel_critical')->count(),
                'destroyed'      => $rows->where('incident_type', 'destroyed')->count(),
                'total_critical' => $rows->count(),
                'most_hit'       => $mostHit,
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] StructureIncidentService::getCorpSummary failed: ' . $e->getMessage());
            return $empty;
        }
    }
}

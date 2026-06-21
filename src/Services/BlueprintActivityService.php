<?php

namespace HrManager\Services;

use HrManager\Models\MemberAssessment;
use Illuminate\Support\Facades\Log;

/**
 * Blueprint Manager integration (consumer side). Two channels:
 *
 *   1. EventBus (push) — recordEvent() accumulates blueprint.request.*
 *      lifecycle events into the player history timeline (idempotent via
 *      the request id) and nudges the assessment cache, exactly like the
 *      mining-event handler. Forward-only; no backfill.
 *
 *   2. PluginBridge (pull) — getForPlayer() / getCorpSummary() read
 *      Blueprint Manager's own stats capability through Manager Core,
 *      giving full history on demand for the profile panel + Corp Health.
 *
 * Everything degrades to "unavailable" when Manager Core or Blueprint
 * Manager isn't installed, so HR runs standalone.
 */
class BlueprintActivityService
{
    /**
     * EventBus handler for blueprint.request.* — records the lifecycle
     * event on the requester's timeline and invalidates their assessment
     * cache so the next read folds in the fresh signal. character_id in the
     * payload is always the REQUESTER (per the topic contract).
     */
    public function recordEvent(string $eventName, string $publisher, array $payload): void
    {
        $characterId = $payload['character_id'] ?? null;

        app(HistoryEventService::class)->record(
            $eventName,
            $payload,
            [
                'character_id'    => $characterId ? (int) $characterId : null,
                'corporation_id'  => $payload['corporation_id'] ?? null,
                'occurred_at'     => $payload['occurred_at'] ?? now(),
                'source_plugin'   => $publisher,
                'idempotency_key' => $payload['idempotency_key']
                    ?? ($eventName . ':' . ($payload['request_id'] ?? '')),
            ]
        );

        if ($characterId) {
            MemberAssessment::where('character_id', (int) $characterId)
                ->update(['cached_at' => null]);
        }
    }

    /**
     * Per-PLAYER blueprint engagement: calls the per-character capability
     * for each of the player's characters and aggregates (a player with a
     * builder alt should show the alt's requests too). Returns
     * ['available' => false] when MC / Blueprint Manager is absent, or
     * ['available' => true, 'has_data' => false] when installed but the
     * player has no requests.
     *
     * @param array<int> $characterIds
     */
    public function getForPlayer(array $characterIds, int $corporationId): array
    {
        $characterIds = array_values(array_unique(array_filter(array_map('intval', $characterIds), fn ($id) => $id > 0)));
        if (empty($characterIds) || $corporationId <= 0) {
            return ['available' => false];
        }

        $cross = app(CrossPluginDataService::class);
        $totals = [
            'total_requests' => 0,
            'pending'        => 0,
            'approved'       => 0,
            'fulfilled'      => 0,
            'rejected'       => 0,
            'total_quantity' => 0,
        ];
        $favourites = [];   // type_id => ['type_name' => ..., 'count' => int]
        $lastRequest = null;
        $hasData = false;

        foreach ($characterIds as $cid) {
            $res = $cross->getBlueprintCharacterStats($cid, $corporationId);
            if (!($res['available'] ?? false)) {
                // MC or Blueprint Manager unavailable — bail whole panel.
                return ['available' => false];
            }
            $d = $res['data'] ?? null;
            if (!is_array($d) || (int) ($d['total_requests'] ?? 0) === 0) {
                continue;
            }
            $hasData = true;
            foreach (array_keys($totals) as $k) {
                $totals[$k] += (int) ($d[$k] ?? 0);
            }
            foreach (($d['favourite_types'] ?? []) as $ft) {
                $tid = (int) ($ft['type_id'] ?? 0);
                if ($tid === 0) {
                    continue;
                }
                if (!isset($favourites[$tid])) {
                    $favourites[$tid] = ['type_id' => $tid, 'type_name' => $ft['type_name'] ?? ('Type #' . $tid), 'count' => 0];
                }
                $favourites[$tid]['count'] += (int) ($ft['count'] ?? 0);
            }
            $lr = $d['last_request'] ?? null;
            if ($lr !== null && ($lastRequest === null || strcmp((string) $lr, (string) $lastRequest) > 0)) {
                $lastRequest = $lr;
            }
        }

        if (!$hasData) {
            return ['available' => true, 'has_data' => false];
        }

        // Sort favourites by count desc, keep top 5.
        usort($favourites, fn ($a, $b) => $b['count'] <=> $a['count']);
        $favourites = array_slice(array_values($favourites), 0, 5);

        $total = $totals['total_requests'];
        return [
            'available'       => true,
            'has_data'        => true,
            'total_requests'  => $total,
            'pending'         => $totals['pending'],
            'approved'        => $totals['approved'],
            'fulfilled'       => $totals['fulfilled'],
            'rejected'        => $totals['rejected'],
            'total_quantity'  => $totals['total_quantity'],
            'rejection_rate'  => $total > 0 ? round(($totals['rejected'] / $total) * 100, 1) : 0.0,
            'last_request'    => $lastRequest,
            'favourite_types' => $favourites,
        ];
    }

    /**
     * Corp-wide blueprint engagement rollup for the Corp Health card.
     * Passes through Blueprint Manager's getCorpSummary capability.
     */
    public function getCorpSummary(int $corporationId): array
    {
        if ($corporationId <= 0) {
            return ['available' => false];
        }
        $res = app(CrossPluginDataService::class)->getBlueprintCorpSummary($corporationId);
        if (!($res['available'] ?? false) || !is_array($res['data'] ?? null)) {
            return ['available' => false];
        }
        return ['available' => true] + $res['data'];
    }
}

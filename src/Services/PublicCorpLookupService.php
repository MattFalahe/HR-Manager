<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tokenless public lookup of a character's CURRENT corporation, via CCP's
 * public ESI affiliation endpoint (no auth, batched, official). Used as the
 * FALLBACK for the Purge board's corp-removal detection when SeAT's
 * director-token data can't confirm a departure (e.g. the token lapsed, or
 * the leaver was never registered): it tells "actually left for corp X" apart
 * from "SeAT just lost track."
 *
 * Mirrors ZkillService's polite direct-HTTP pattern: identifying User-Agent,
 * fast timeout, warm cache, best-effort (failures return nothing rather than
 * throwing). Never touches refresh_tokens or ESI scopes, so it can't affect
 * SeAT auth or anyone's keys.
 *
 * evewho (https://evewho.com/api/character/{id}) and zKill are equivalent
 * public sources; ESI affiliation is preferred because it's official, batched
 * and returns the current corp directly.
 */
class PublicCorpLookupService
{
    private const CACHE_TTL    = 3600; // 1 hour — departures aren't minute-sensitive
    private const FETCH_TIMEOUT = 4;
    private const USER_AGENT   = 'SeAT HR Manager Plugin (https://github.com/MattFalahe/hr-manager)';
    private const ESI_ENDPOINT = 'https://esi.evetech.net/latest/characters/affiliation/';

    /**
     * Resolve current corporation_id for each character. Returns
     * [characterId => corporationId]; characters whose lookup failed are
     * simply absent from the result (so callers can treat "missing" as
     * "couldn't confirm"). Serves warm cache first; only the misses hit ESI.
     *
     * @param array<int> $characterIds
     * @return array<int,int>
     */
    public function currentCorps(array $characterIds): array
    {
        $characterIds = array_values(array_unique(array_filter(
            array_map('intval', $characterIds),
            fn ($id) => $id > 0
        )));
        if (empty($characterIds)) {
            return [];
        }

        $result = [];
        $misses = [];
        foreach ($characterIds as $id) {
            $cached = Cache::get("hr-pubcorp-{$id}");
            if ($cached !== null) {
                if ((int) $cached > 0) {
                    $result[$id] = (int) $cached;
                }
            } else {
                $misses[] = $id;
            }
        }

        // ESI affiliation accepts up to 1000 ids per call.
        foreach (array_chunk($misses, 1000) as $chunk) {
            $fetched = $this->fetchAffiliations($chunk);
            foreach ($chunk as $id) {
                $corp = (int) ($fetched[$id] ?? 0);
                // Cache the answer (0 = "no answer") so a failed lookup doesn't
                // hammer ESI on every tab load within the TTL.
                Cache::put("hr-pubcorp-{$id}", $corp, self::CACHE_TTL);
                if ($corp > 0) {
                    $result[$id] = $corp;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<int> $characterIds
     * @return array<int,int>
     */
    private function fetchAffiliations(array $characterIds): array
    {
        try {
            $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'application/json',
                ])
                ->timeout(self::FETCH_TIMEOUT)
                ->post(self::ESI_ENDPOINT, array_values($characterIds));

            if (!$response->successful()) {
                Log::warning('[HR Manager] public affiliation lookup http ' . $response->status());
                return [];
            }

            $out = [];
            foreach ((array) $response->json() as $row) {
                if (isset($row['character_id'], $row['corporation_id'])) {
                    $out[(int) $row['character_id']] = (int) $row['corporation_id'];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] public affiliation lookup failed: ' . $e->getMessage());
            return [];
        }
    }
}

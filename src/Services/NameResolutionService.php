<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves character / corporation / alliance names from external
 * sources when SeAT's own tables don't have them.
 *
 * Pattern adopted from Mining Manager's ExternalCharacterService
 * (per Matt's suggestion 2026-06-06): Laravel Http facade, ESI
 * primary, zKillboard fallback, 24h Cache::remember, 5-second
 * timeout.
 *
 * Adds a batch resolution method (POST /universe/names/) so the
 * Members index can resolve 50+ unknown names in one round trip
 * instead of one ESI call per row.
 *
 * Side effect: resolved names are persisted to SeAT's
 * universe_names cache so subsequent lookups across HR Manager AND
 * any other plugin using the same cache benefit immediately.
 */
class NameResolutionService
{
    private const CACHE_TTL = 24 * 60 * 60; // 24h
    private const HTTP_TIMEOUT = 5;

    /**
     * Resolve a single character name. Walks the source chain:
     *   1. SeAT character_infos (registered)
     *   2. SeAT universe_names cache
     *   3. ESI /characters/{id}/  (1h cache)
     *   4. zKillboard            (fallback)
     *
     * "Unknown" stored by SeAT's failed sync is treated as missing
     * so we fall through to ESI rather than displaying the placeholder.
     */
    public function getCharacterName(int $characterId): ?string
    {
        if ($characterId <= 0) {
            return null;
        }

        // 1. SeAT registered character
        $name = DB::table('character_infos')
            ->where('character_id', $characterId)
            ->value('name');
        if ($this->isUsableName($name)) {
            return (string) $name;
        }

        // 2. SeAT universe_names cache
        if (Schema::hasTable('universe_names')) {
            $name = DB::table('universe_names')
                ->where('entity_id', $characterId)
                ->where('category', 'character')
                ->value('name');
            if ($this->isUsableName($name)) {
                return (string) $name;
            }
        }

        // 3 + 4. External lookup (cached 24h per character ID).
        return Cache::remember(
            'hr-name-char-' . $characterId,
            self::CACHE_TTL,
            function () use ($characterId) {
                $name = $this->getCharacterFromESI($characterId)['name'] ?? null;
                if ($name) {
                    $this->persistName($characterId, $name, 'character');
                    return (string) $name;
                }

                $name = $this->getCharacterNameFromZKill($characterId);
                if ($name) {
                    $this->persistName($characterId, $name, 'character');
                    return (string) $name;
                }

                return null;
            }
        );
    }

    /**
     * Batch-resolve many character IDs in one pass. Used by the
     * Members index where 50+ unknown names can be on the same
     * page. Returns [id => name] for every ID that resolved.
     *
     * Walks the same source chain as getCharacterName but uses
     * ESI's POST /universe/names/ endpoint for the external call
     * (handles up to 1000 IDs in a single round trip).
     *
     * @param array<int> $characterIds
     * @return array<int, string>
     */
    public function getCharacterNames(array $characterIds): array
    {
        $characterIds = array_values(array_unique(array_filter(
            array_map('intval', $characterIds),
            fn($id) => $id > 0
        )));

        if (empty($characterIds)) {
            return [];
        }

        $resolved = [];

        // 1. SeAT character_infos — skip the "Unknown" placeholder
        $infos = DB::table('character_infos')
            ->whereIn('character_id', $characterIds)
            ->pluck('name', 'character_id')
            ->toArray();
        foreach ($infos as $id => $name) {
            if ($this->isUsableName($name)) {
                $resolved[(int) $id] = (string) $name;
            }
        }

        $missing = array_values(array_diff($characterIds, array_keys($resolved)));

        // 2. SeAT universe_names cache — same placeholder filter
        if (!empty($missing) && Schema::hasTable('universe_names')) {
            $cached = DB::table('universe_names')
                ->whereIn('entity_id', $missing)
                ->where('category', 'character')
                ->pluck('name', 'entity_id')
                ->toArray();
            foreach ($cached as $id => $name) {
                if ($this->isUsableName($name)) {
                    $resolved[(int) $id] = (string) $name;
                }
            }
            $missing = array_values(array_diff($missing, array_keys($resolved)));
        }

        // 3. ESI batch endpoint (POST /universe/names/, up to 1000 IDs).
        if (!empty($missing)) {
            $batchResolved = $this->batchResolveViaEsi($missing);
            foreach ($batchResolved as $id => $name) {
                $resolved[$id] = $name;
            }
        }

        return $resolved;
    }

    /**
     * Resolve SeAT user IDs (NOT character IDs) to a display name — the
     * user's main character name, falling back to the users.name column.
     * Used wherever HR shows "who did this" (status-history actor, decision
     * notes, handler list) so the UI never renders a bare "User #12".
     *
     * @param array<int> $userIds
     * @return array<int, string>  user_id => name
     */
    public function getUserNames(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds), fn ($id) => $id > 0
        )));
        if (empty($userIds)) {
            return [];
        }
        try {
            return DB::table('users')
                ->whereIn('users.id', $userIds)
                ->leftJoin('character_infos as ci', 'ci.character_id', '=', 'users.main_character_id')
                ->selectRaw('users.id, COALESCE(ci.name, users.name) as name')
                ->pluck('name', 'id')
                ->map(fn ($n) => (string) $n)
                ->toArray();
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] NameResolution: getUserNames failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Batch-resolve to a name map that ALWAYS has an entry for every
     * requested id: the resolved name, or '#<id>' when nothing could
     * resolve it. Convenience for rosters/tables that render an id+name
     * column and want a usable string for unregistered members rather than
     * a hole. Same resolution chain as getCharacterNames().
     *
     * @param array<int> $characterIds
     * @return array<int, string>
     */
    public function getCharacterNamesWithFallback(array $characterIds): array
    {
        $resolved = $this->getCharacterNames($characterIds);
        $out = [];
        foreach (array_unique(array_map('intval', $characterIds)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $out[$id] = $resolved[$id] ?? ('#' . $id);
        }
        return $out;
    }

    /**
     * Resolve a name to a character_id (best-effort). Used by the
     * watchlist add form when an operator types a name instead of
     * an ID. Single-name only — no batch variant needed.
     *
     * @return array{character_id:?int, character_name:?string}
     */
    public function getIdFromCharacterName(string $name): array
    {
        $name = trim($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 37) {
            return ['character_id' => null, 'character_name' => null];
        }

        // 1. SeAT character_infos (case-insensitive exact)
        $row = DB::table('character_infos')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first(['character_id', 'name']);
        if ($row) {
            return ['character_id' => (int) $row->character_id, 'character_name' => (string) $row->name];
        }

        // 2. SeAT universe_names cache
        if (Schema::hasTable('universe_names')) {
            $row = DB::table('universe_names')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->where('category', 'character')
                ->first(['entity_id', 'name']);
            if ($row) {
                return ['character_id' => (int) $row->entity_id, 'character_name' => (string) $row->name];
            }
        }

        // 3. ESI POST /universe/ids/
        return Cache::remember(
            'hr-name-id-' . mb_strtolower($name),
            self::CACHE_TTL,
            function () use ($name) {
                try {
                    $response = Http::timeout(self::HTTP_TIMEOUT)
                        ->withHeaders([
                            'Accept'     => 'application/json',
                            'User-Agent' => $this->userAgent(),
                        ])
                        ->post('https://esi.evetech.net/latest/universe/ids/', [$name]);
                    if ($response->successful()) {
                        $data = $response->json();
                        $chars = $data['characters'] ?? [];
                        if (is_array($chars) && !empty($chars[0]['id']) && !empty($chars[0]['name'])) {
                            $cid = (int) $chars[0]['id'];
                            $cname = (string) $chars[0]['name'];
                            $this->persistName($cid, $cname, 'character');
                            return ['character_id' => $cid, 'character_name' => $cname];
                        }
                    }
                } catch (\Throwable $e) {
                    Log::debug('[HR Manager] NameResolution: ESI /universe/ids/ failed: ' . $e->getMessage());
                }
                return ['character_id' => null, 'character_name' => null];
            }
        );
    }

    /**
     * Resolve an alliance name with ESI + universe_names fallback.
     */
    public function getAllianceName(int $allianceId): ?string
    {
        if ($allianceId <= 0) {
            return null;
        }

        if (Schema::hasTable('alliance_infos')) {
            $name = DB::table('alliance_infos')->where('alliance_id', $allianceId)->value('name');
            if ($name) return (string) $name;
        }

        if (Schema::hasTable('universe_names')) {
            $name = DB::table('universe_names')
                ->where('entity_id', $allianceId)
                ->where('category', 'alliance')
                ->value('name');
            if ($name) return (string) $name;
        }

        return Cache::remember(
            'hr-name-alliance-' . $allianceId,
            self::CACHE_TTL,
            function () use ($allianceId) {
                try {
                    $response = Http::timeout(self::HTTP_TIMEOUT)
                        ->withHeaders(['User-Agent' => $this->userAgent()])
                        ->get('https://esi.evetech.net/latest/alliances/' . $allianceId . '/');
                    if ($response->successful()) {
                        $name = $response->json()['name'] ?? null;
                        if ($name) {
                            $this->persistName($allianceId, $name, 'alliance');
                            return (string) $name;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::debug('[HR Manager] NameResolution: alliance ESI failed: ' . $e->getMessage());
                }
                return null;
            }
        );
    }

    // -----------------------------------------------------------------
    // Internal helpers — mirror MM's ExternalCharacterService pattern
    // -----------------------------------------------------------------

    /**
     * MM-pattern: one ESI call per character with a 1h cache. Returns
     * the full /characters/{id}/ response so both name and corp_id
     * lookups share the cache.
     */
    private function getCharacterFromESI(int $characterId): ?array
    {
        $cacheKey = 'hr-name-esi-char-' . $characterId;
        return Cache::remember($cacheKey, 60 * 60, function () use ($characterId) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withHeaders(['User-Agent' => $this->userAgent()])
                    ->get('https://esi.evetech.net/latest/characters/' . $characterId . '/');
                if ($response->successful()) {
                    return $response->json();
                }
            } catch (\Throwable $e) {
                Log::debug('[HR Manager] NameResolution: ESI /characters/ failed: ' . $e->getMessage());
            }
            return null;
        });
    }

    /**
     * zKill character endpoint fallback. Used only when ESI fails or
     * doesn't return a name (very rare).
     */
    private function getCharacterNameFromZKill(int $characterId): ?string
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->get('https://zkillboard.com/api/characterID/' . $characterId . '/');
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['characterName'])) {
                    return (string) $data[0]['characterName'];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] NameResolution: zKill character lookup failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * POST /universe/names/ — bulk ID to name resolution, up to 1000
     * mixed-category IDs per call. We filter to characters only on
     * the way out.
     *
     * Chunks the input at 1000 (ESI hard limit) and persists every
     * resolved name into universe_names so subsequent lookups skip
     * the network call entirely.
     *
     * @param array<int> $ids
     * @return array<int, string>
     */
    private function batchResolveViaEsi(array $ids): array
    {
        $resolved = [];
        $chunks = array_chunk($ids, 1000);

        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withHeaders([
                        'Accept'     => 'application/json',
                        'User-Agent' => $this->userAgent(),
                    ])
                    ->post('https://esi.evetech.net/latest/universe/names/', array_values($chunk));

                if (!$response->successful()) {
                    Log::info('[HR Manager] NameResolution: ESI /universe/names/ returned ' . $response->status());
                    continue;
                }

                $data = $response->json();
                if (!is_array($data)) {
                    continue;
                }

                $batch = [];
                foreach ($data as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = (int) ($row['id'] ?? 0);
                    $name = (string) ($row['name'] ?? '');
                    $category = (string) ($row['category'] ?? '');
                    if ($id <= 0 || $name === '') {
                        continue;
                    }
                    if ($category === 'character') {
                        $resolved[$id] = $name;
                    }
                    $batch[$id] = ['name' => $name, 'category' => $category];
                }

                $this->persistBatch($batch);
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] NameResolution: ESI batch failed: ' . $e->getMessage());
            }
        }

        return $resolved;
    }

    /**
     * Write a single resolved name into universe_names. firstOrCreate
     * keeps things idempotent — SeAT's other syncs that touch this
     * table won't conflict.
     */
    private function persistName(int $entityId, string $name, string $category): void
    {
        if (!Schema::hasTable('universe_names')) {
            return;
        }
        try {
            DB::table('universe_names')->updateOrInsert(
                ['entity_id' => $entityId],
                ['name' => $name, 'category' => $category, 'updated_at' => now(), 'created_at' => now()]
            );
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] NameResolution: persistName failed: ' . $e->getMessage());
        }
    }

    /**
     * Bulk-persist a batch of resolved (id, name, category) rows
     * into universe_names. One INSERT IGNORE per chunk via raw SQL
     * for speed.
     *
     * @param array<int, array{name:string, category:string}> $batch
     */
    private function persistBatch(array $batch): void
    {
        if (empty($batch) || !Schema::hasTable('universe_names')) {
            return;
        }
        try {
            foreach ($batch as $id => $row) {
                DB::table('universe_names')->updateOrInsert(
                    ['entity_id' => $id],
                    [
                        'name'       => $row['name'],
                        'category'   => $row['category'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] NameResolution: persistBatch failed: ' . $e->getMessage());
        }
    }

    private function userAgent(): string
    {
        return 'SeAT-HrManager/' . config('hr-manager.version', 'unknown');
    }

    /**
     * Filter for "this is a real character name we can show". SeAT
     * persists the literal string "Unknown" in character_infos.name
     * when an ESI sync failed and it needed to insert SOMETHING.
     * Empty strings and whitespace-only also count as missing so the
     * caller can fall through to ESI re-resolution.
     */
    public function isUsableName($name): bool
    {
        if ($name === null) {
            return false;
        }
        $trimmed = trim((string) $name);
        if ($trimmed === '') {
            return false;
        }
        // Case-insensitive — SeAT historically used both "Unknown"
        // and "unknown".
        return strtolower($trimmed) !== 'unknown';
    }
}

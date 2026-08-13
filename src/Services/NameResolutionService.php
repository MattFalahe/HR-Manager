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
     * Resolve a mixed batch of IDs to name AND category, for any entity type.
     *
     * getCharacterNames() answers "what is this character called" and discards
     * everything else. This answers "what IS this", which is what a standings
     * list needs: the operator pastes an ID and we have to know whether they
     * handed us an alliance, a corp or a character before we can file it.
     *
     * @param array<int> $ids
     * @return array<int, array{name:string, category:string}>
     */
    public function resolveEntityNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
        if (empty($ids)) {
            return [];
        }

        $resolved = [];

        // Local tables first. Each carries its own category implicitly, which
        // is more trustworthy than universe_names — that cache is populated by
        // whoever got there first and its category can be stale.
        $local = [
            ['alliance_infos',    'alliance_id',    'alliance'],
            ['corporation_infos', 'corporation_id', 'corporation'],
            ['character_infos',   'character_id',   'character'],
        ];
        foreach ($local as [$table, $idCol, $category]) {
            $missing = array_values(array_diff($ids, array_keys($resolved)));
            if (empty($missing) || !Schema::hasTable($table)) {
                continue;
            }
            try {
                $rows = DB::table($table)->whereIn($idCol, $missing)->pluck('name', $idCol)->toArray();
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($rows as $id => $name) {
                if ($this->isUsableName($name)) {
                    $resolved[(int) $id] = ['name' => (string) $name, 'category' => $category];
                }
            }
        }

        $missing = array_values(array_diff($ids, array_keys($resolved)));

        if (!empty($missing) && Schema::hasTable('universe_names')) {
            try {
                $rows = DB::table('universe_names')
                    ->whereIn('entity_id', $missing)
                    ->get(['entity_id', 'name', 'category']);
                foreach ($rows as $row) {
                    if ($this->isUsableName($row->name)) {
                        $resolved[(int) $row->entity_id] = [
                            'name'     => (string) $row->name,
                            'category' => (string) $row->category,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('[HR Manager] NameResolution: universe_names lookup failed: ' . $e->getMessage());
            }
            $missing = array_values(array_diff($ids, array_keys($resolved)));
        }

        // ESI, 1000 per call. An ID that resolves to nothing here does not
        // exist (or no longer does); the caller decides what to do about it.
        foreach (array_chunk($missing, 1000) as $chunk) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withHeaders(['Accept' => 'application/json', 'User-Agent' => $this->userAgent()])
                    ->post('https://esi.evetech.net/latest/universe/names/', array_values($chunk));

                if (!$response->successful()) {
                    Log::info('[HR Manager] NameResolution: ESI /universe/names/ returned ' . $response->status());
                    continue;
                }

                $batch = [];
                foreach ((array) $response->json() as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id       = (int) ($row['id'] ?? 0);
                    $name     = (string) ($row['name'] ?? '');
                    $category = (string) ($row['category'] ?? '');
                    if ($id <= 0 || $name === '') {
                        continue;
                    }
                    $resolved[$id] = ['name' => $name, 'category' => $category];
                    $batch[$id]    = ['name' => $name, 'category' => $category];
                }

                $this->persistBatch($batch);
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] NameResolution: entity batch failed: ' . $e->getMessage());
            }
        }

        return $resolved;
    }

    /**
     * The reverse: names to IDs, for any entity type. Keyed by the LOWERCASED
     * name the caller passed, so a caller that let someone type "goonswarm
     * federation" can still find their result.
     *
     * ESI's /universe/ids/ matches exactly (bar case), which is the right
     * behaviour here — a standings list built from fuzzy matches would be
     * quietly wrong in exactly the way that matters.
     *
     * @param array<string> $names
     * @return array<string, array{id:int, name:string, category:string}>
     */
    public function resolveNamesToEntities(array $names): array
    {
        $clean = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if ($n !== '' && mb_strlen($n) <= 100) {
                $clean[mb_strtolower($n)] = $n;
            }
        }
        if (empty($clean)) {
            return [];
        }

        $resolved = [];
        $needles  = array_values($clean);

        $local = [
            ['alliance_infos',    'alliance_id',    'alliance'],
            ['corporation_infos', 'corporation_id', 'corporation'],
            ['character_infos',   'character_id',   'character'],
        ];
        foreach ($local as [$table, $idCol, $category]) {
            $pending = array_diff_key($clean, $resolved);
            if (empty($pending) || !Schema::hasTable($table)) {
                continue;
            }
            try {
                $rows = DB::table($table)
                    ->whereIn(DB::raw('LOWER(name)'), array_keys($pending))
                    ->get([$idCol . ' as entity_id', 'name']);
                foreach ($rows as $row) {
                    $resolved[mb_strtolower((string) $row->name)] = [
                        'id'       => (int) $row->entity_id,
                        'name'     => (string) $row->name,
                        'category' => $category,
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        $pending = array_values(array_diff_key($clean, $resolved));

        // ESI caps /universe/ids/ at 500 names per call.
        foreach (array_chunk($pending, 500) as $chunk) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withHeaders(['Accept' => 'application/json', 'User-Agent' => $this->userAgent()])
                    ->post('https://esi.evetech.net/latest/universe/ids/', array_values($chunk));

                if (!$response->successful()) {
                    Log::info('[HR Manager] NameResolution: ESI /universe/ids/ returned ' . $response->status());
                    continue;
                }

                $data = (array) $response->json();
                $buckets = [
                    'alliances'    => 'alliance',
                    'corporations' => 'corporation',
                    'characters'   => 'character',
                ];

                $batch = [];
                foreach ($buckets as $bucket => $category) {
                    foreach ((array) ($data[$bucket] ?? []) as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $id   = (int) ($row['id'] ?? 0);
                        $name = (string) ($row['name'] ?? '');
                        if ($id <= 0 || $name === '') {
                            continue;
                        }
                        $resolved[mb_strtolower($name)] = [
                            'id'       => $id,
                            'name'     => $name,
                            'category' => $category,
                        ];
                        $batch[$id] = ['name' => $name, 'category' => $category];
                    }
                }

                $this->persistBatch($batch);
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] NameResolution: name batch failed: ' . $e->getMessage());
            }
        }

        return $resolved;
    }

    /**
     * Who these characters belong to right now: corporation, and alliance when
     * their corp is in one.
     *
     * CURRENT affiliation is all EVE exposes. There is no endpoint that answers
     * "who did this character fly for in 2024", so any caller reasoning about a
     * past event has to treat this as what is true today and record when it
     * asked. Callers that care must freeze the answer rather than re-deriving
     * it later and quietly rewriting history.
     *
     * @param array<int> $characterIds
     * @return array<int, array{corporation_id:?int, alliance_id:?int}>
     */
    public function resolveAffiliations(array $characterIds): array
    {
        $characterIds = array_values(array_unique(array_filter(
            array_map('intval', $characterIds), fn ($v) => $v > 0
        )));
        if (empty($characterIds)) {
            return [];
        }

        $out = [];

        // SeAT's own affiliation table first: it is synced far more often than
        // character_infos and costs nothing.
        if (Schema::hasTable('character_affiliations')) {
            try {
                $rows = DB::table('character_affiliations')
                    ->whereIn('character_id', $characterIds)
                    ->get(['character_id', 'corporation_id', 'alliance_id']);
                foreach ($rows as $r) {
                    $out[(int) $r->character_id] = [
                        'corporation_id' => $r->corporation_id ? (int) $r->corporation_id : null,
                        'alliance_id'    => $r->alliance_id ? (int) $r->alliance_id : null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug('[HR Manager] NameResolution: affiliation table read failed: ' . $e->getMessage());
            }
        }

        $missing = array_values(array_diff($characterIds, array_keys($out)));

        // ESI, 1000 per call. Anyone outside SeAT lands here, which for this
        // caller is most of them.
        foreach (array_chunk($missing, 1000) as $chunk) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->withHeaders(['Accept' => 'application/json', 'User-Agent' => $this->userAgent()])
                    ->post('https://esi.evetech.net/latest/characters/affiliation/', array_values($chunk));

                if (!$response->successful()) {
                    Log::info('[HR Manager] NameResolution: ESI /characters/affiliation/ returned ' . $response->status());
                    continue;
                }

                foreach ((array) $response->json() as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $cid = (int) ($row['character_id'] ?? 0);
                    if ($cid <= 0) {
                        continue;
                    }
                    $out[$cid] = [
                        'corporation_id' => isset($row['corporation_id']) ? (int) $row['corporation_id'] : null,
                        'alliance_id'    => isset($row['alliance_id']) ? (int) $row['alliance_id'] : null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] NameResolution: affiliation batch failed: ' . $e->getMessage());
            }
        }

        return $out;
    }

    /**
     * Every corporation currently in an alliance.
     *
     * Local tables first: corporation_infos already carries alliance_id for
     * every corp SeAT has resolved, and for an alliance whose corps are all
     * known that answers without a network call. ESI is asked as well and the
     * two are merged, because SeAT only knows the corps it has had a reason to
     * look at, which on a fresh install is a fraction of an alliance.
     *
     * @return array<int>
     */
    public function allianceCorporationIds(int $allianceId): array
    {
        if ($allianceId <= 0) {
            return [];
        }

        $out = [];

        if (Schema::hasTable('corporation_infos')) {
            try {
                foreach (DB::table('corporation_infos')
                    ->where('alliance_id', $allianceId)
                    ->pluck('corporation_id') as $c) {
                    $out[(int) $c] = (int) $c;
                }
            } catch (\Throwable $e) {
                Log::debug('[HR Manager] NameResolution: local alliance corp lookup failed: ' . $e->getMessage());
            }
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withHeaders(['Accept' => 'application/json', 'User-Agent' => $this->userAgent()])
                ->get('https://esi.evetech.net/latest/alliances/' . $allianceId . '/corporations/');

            if ($response->successful()) {
                foreach ((array) $response->json() as $c) {
                    $c = (int) $c;
                    if ($c > 0) {
                        $out[$c] = $c;
                    }
                }
            } else {
                Log::info('[HR Manager] NameResolution: ESI alliance corporations returned ' . $response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] NameResolution: alliance corporation lookup failed: ' . $e->getMessage());
        }

        return array_values($out);
    }

    /**
     * The alliance each corporation currently sits in.
     *
     * Same caveat as resolveAffiliations: this is today's answer, not the
     * answer on the date of whatever the caller is looking at.
     *
     * @param array<int> $corporationIds
     * @return array<int, ?int> corporation_id => alliance_id (null when unallied)
     */
    public function resolveCorporationAlliances(array $corporationIds): array
    {
        $corporationIds = array_values(array_unique(array_filter(
            array_map('intval', $corporationIds), fn ($v) => $v > 0
        )));
        if (empty($corporationIds)) {
            return [];
        }

        $out = [];

        if (Schema::hasTable('corporation_infos')) {
            try {
                $rows = DB::table('corporation_infos')
                    ->whereIn('corporation_id', $corporationIds)
                    ->get(['corporation_id', 'alliance_id']);
                foreach ($rows as $r) {
                    $out[(int) $r->corporation_id] = $r->alliance_id ? (int) $r->alliance_id : null;
                }
            } catch (\Throwable $e) {
                Log::debug('[HR Manager] NameResolution: corporation_infos read failed: ' . $e->getMessage());
            }
        }

        // One call each for the rest; ESI has no batch corporation endpoint.
        // Cached for a day, and the set is small in practice because a scan's
        // counterparties cluster into a handful of corps.
        foreach (array_diff($corporationIds, array_keys($out)) as $corpId) {
            $out[$corpId] = Cache::remember(
                'hr-corp-alliance-' . $corpId,
                self::CACHE_TTL,
                function () use ($corpId) {
                    try {
                        $response = Http::timeout(self::HTTP_TIMEOUT)
                            ->withHeaders(['User-Agent' => $this->userAgent()])
                            ->get('https://esi.evetech.net/latest/corporations/' . $corpId . '/');
                        if ($response->successful()) {
                            $alliance = $response->json()['alliance_id'] ?? null;
                            return $alliance ? (int) $alliance : null;
                        }
                    } catch (\Throwable $e) {
                        Log::debug('[HR Manager] NameResolution: corp alliance lookup failed: ' . $e->getMessage());
                    }
                    return null;
                }
            );
        }

        return $out;
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

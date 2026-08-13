<?php

namespace HrManager\Services;

use HrManager\Models\StandingEntry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Editing side of HR's own standings list: everything the settings tab needs
 * to add, retype, revalue and remove entries.
 *
 * Kept apart from StandingsReferenceService deliberately. That one is read on
 * every assessment and every page that shows a standing, so it stays small and
 * cheap; this one runs only when an admin is actually editing, and can afford
 * to call ESI.
 */
class StandingsAdminService
{
    public function __construct(
        private NameResolutionService $names,
        private StandingsReferenceService $reference
    ) {
    }

    /**
     * The list as the settings tab shows it: every HR entry, with the SeAT
     * value it overrides where there is one.
     *
     * Names come from the stored snapshot, never from a live lookup. A list of
     * a few hundred entities would otherwise mean a few hundred ESI calls on
     * page render; unresolved names show as an ID with a button to fill them
     * in, which is a slower answer but an honest one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        if (!Schema::hasTable('hr_manager_standings')) {
            return [];
        }

        // Only carries a SeAT value in hybrid mode, which is the only mode
        // where an HR entry is overriding anything.
        $resolved = $this->reference->resolvedStandings();

        $out = [];
        foreach (StandingEntry::orderBy('standing')->orderBy('entity_type')->orderBy('entity_name')->get() as $row) {
            $key      = $row->entity_type . ':' . $row->entity_id;
            $seatVal  = $resolved[$key]['seat_standing'] ?? null;

            $out[] = [
                'id'            => (int) $row->id,
                'entity_id'     => (int) $row->entity_id,
                'entity_type'   => (string) $row->entity_type,
                'entity_name'   => $row->entity_name,
                'standing'      => (int) $row->standing,
                'notes'         => $row->notes,
                'palette'       => StandingEntry::palette((int) $row->standing),
                'seat_standing' => $seatVal === null ? null : (int) $seatVal,
                'overrides'     => $seatVal !== null && (int) $seatVal !== (int) $row->standing,
                'updated_at'    => $row->updated_at,
            ];
        }

        return $out;
    }

    /**
     * Add or revalue entries from free text: one entity per line, either an ID
     * or an exact name, the two mixable in the same paste.
     *
     * $type is a fallback, not an instruction. When we can resolve what an
     * entity actually IS we file it that way, because an entry filed under the
     * wrong category silently never matches anything — the worst failure this
     * feature has, since it looks configured and does nothing. Reclassifications
     * are reported back so the operator sees it happened.
     *
     * @return array{added:int, updated:int, unchanged:int, retyped:array<string>, unresolved:array<string>}
     */
    public function addBulk(string $raw, string $type, int $standing, ?int $setBy = null, ?string $notes = null): array
    {
        $out = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'retyped' => [], 'unresolved' => []];

        if (!Schema::hasTable('hr_manager_standings') || !StandingEntry::isValidStanding($standing)) {
            return $out;
        }

        [$ids, $names] = $this->splitTokens($raw);
        if (empty($ids) && empty($names)) {
            return $out;
        }

        // Resolve both halves in as few round trips as possible.
        $byId   = empty($ids) ? [] : $this->names->resolveEntityNames($ids);
        $byName = empty($names) ? [] : $this->names->resolveNamesToEntities($names);

        $targets = [];

        foreach ($ids as $id) {
            $hit = $byId[$id] ?? null;
            $cat = $hit['category'] ?? null;

            if ($cat !== null && !StandingEntry::isValidType($cat)) {
                // A real entity, but a faction or a ship type or a station —
                // nothing an applicant's contacts can ever match against.
                $out['unresolved'][] = (string) $id . ' (' . $cat . ')';
                continue;
            }

            // No resolution (ESI down, ID retired) still files under the picked
            // type: an ID list used to work without any lookup at all and must
            // keep working when the network does not.
            if ($cat === null && !StandingEntry::isValidType($type)) {
                $out['unresolved'][] = (string) $id;
                continue;
            }

            $finalType = $cat ?? $type;
            if ($cat !== null && StandingEntry::isValidType($type) && $cat !== $type) {
                $out['retyped'][] = ($hit['name'] ?? (string) $id) . ' -> ' . $cat;
            }

            $targets[$finalType . ':' . $id] = [
                'entity_id'   => $id,
                'entity_type' => $finalType,
                'entity_name' => $hit['name'] ?? null,
            ];
        }

        foreach ($names as $name) {
            $hit = $byName[mb_strtolower($name)] ?? null;
            if ($hit === null || !StandingEntry::isValidType($hit['category'])) {
                // A name we cannot resolve is not usable at all — unlike an ID,
                // there is nothing to store.
                $out['unresolved'][] = $name;
                continue;
            }

            if (StandingEntry::isValidType($type) && $hit['category'] !== $type) {
                $out['retyped'][] = $hit['name'] . ' -> ' . $hit['category'];
            }

            $targets[$hit['category'] . ':' . $hit['id']] = [
                'entity_id'   => $hit['id'],
                'entity_type' => $hit['category'],
                'entity_name' => $hit['name'],
            ];
        }

        foreach ($targets as $t) {
            try {
                $existing = StandingEntry::where('entity_type', $t['entity_type'])
                    ->where('entity_id', $t['entity_id'])
                    ->first();

                if ($existing === null) {
                    StandingEntry::create([
                        'entity_id'   => $t['entity_id'],
                        'entity_type' => $t['entity_type'],
                        'standing'    => $standing,
                        'entity_name' => $t['entity_name'],
                        'notes'       => $notes,
                        'set_by'      => $setBy,
                    ]);
                    $out['added']++;
                    continue;
                }

                $changed = (int) $existing->standing !== $standing;

                $existing->standing = $standing;
                // Fill a missing name, refresh a stale one, but never blank a
                // stored name because this particular lookup came back empty.
                if (!empty($t['entity_name'])) {
                    $existing->entity_name = $t['entity_name'];
                }
                if ($notes !== null && $notes !== '') {
                    $existing->notes = $notes;
                }
                $existing->set_by = $setBy;
                $existing->save();

                $changed ? $out['updated']++ : $out['unchanged']++;
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] standings upsert failed: ' . $e->getMessage());
                $out['unresolved'][] = (string) $t['entity_id'];
            }
        }

        return $out;
    }

    /**
     * Set one value across many rows at once, by row id.
     *
     * @param array<int> $rowIds
     */
    public function setValue(array $rowIds, int $standing, ?int $setBy = null): int
    {
        $rowIds = $this->cleanIds($rowIds);
        if (empty($rowIds) || !StandingEntry::isValidStanding($standing)) {
            return 0;
        }

        try {
            return StandingEntry::whereIn('id', $rowIds)
                ->update(['standing' => $standing, 'set_by' => $setBy, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] standings bulk set failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @param array<int> $rowIds
     */
    public function delete(array $rowIds): int
    {
        $rowIds = $this->cleanIds($rowIds);
        if (empty($rowIds)) {
            return 0;
        }

        try {
            return StandingEntry::whereIn('id', $rowIds)->delete();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] standings delete failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Fill in names for rows that have none — entries imported from the old
     * ID-only lists, or added while ESI was unreachable.
     */
    public function backfillNames(int $limit = 300): int
    {
        if (!Schema::hasTable('hr_manager_standings')) {
            return 0;
        }

        $rows = StandingEntry::whereNull('entity_name')->limit($limit)->get(['id', 'entity_id', 'entity_type']);
        if ($rows->isEmpty()) {
            return 0;
        }

        $resolved = $this->names->resolveEntityNames($rows->pluck('entity_id')->all());
        $filled   = 0;

        foreach ($rows as $row) {
            $hit = $resolved[(int) $row->entity_id] ?? null;
            if ($hit === null || $hit['name'] === '') {
                continue;
            }
            try {
                $row->entity_name = $hit['name'];
                $row->save();
                $filled++;
            } catch (\Throwable $e) {
                Log::debug('[HR Manager] standings name backfill failed: ' . $e->getMessage());
            }
        }

        return $filled;
    }

    /**
     * Counts for the tab header, so an operator can tell at a glance whether
     * the mode they picked is actually resolving to anything.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $resolved = $this->reference->resolvedStandings();

        $out = ['total' => count($resolved), 'hostile' => 0, 'friendly' => 0, 'neutral' => 0, 'from_hr' => 0, 'unnamed' => 0];

        foreach ($resolved as $info) {
            if ($info['standing'] < 0) {
                $out['hostile']++;
            } elseif ($info['standing'] > 0) {
                $out['friendly']++;
            } else {
                $out['neutral']++;
            }
            if ($info['from'] === StandingsReferenceService::SOURCE_OWN) {
                $out['from_hr']++;
            }
        }

        if (Schema::hasTable('hr_manager_standings')) {
            $out['unnamed'] = StandingEntry::whereNull('entity_name')->count();
        }

        return $out;
    }

    /**
     * Split a paste into numeric IDs and names. Newline-separated, because
     * EVE names contain spaces and commas would make "Sons of Bane, Inc"
     * impossible to enter.
     *
     * @return array{0: array<int>, 1: array<string>}
     */
    private function splitTokens(string $raw): array
    {
        $ids = [];
        $names = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (ctype_digit($line)) {
                $id = (int) $line;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
                continue;
            }
            $names[mb_strtolower($line)] = $line;
        }

        return [array_values($ids), array_values($names)];
    }

    /**
     * @param array<mixed> $ids
     * @return array<int>
     */
    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
    }
}

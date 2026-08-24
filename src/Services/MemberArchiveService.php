<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\MemberArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps a record of people after they leave.
 *
 * Today a departure erases someone from HR's view almost completely: the roster
 * row is deleted, and the player profile decides access from CURRENT corp
 * membership, so the moment their affiliation changes their whole file becomes
 * unreachable. The notes and history are still on disk; what is lost is any way
 * to reach them.
 *
 * Figures are FROZEN at departure rather than recomputed later, because the
 * source data does not reliably survive. Leavers often revoke their ESI token,
 * syncing stops, and their history can be pruned outright, so a total worked
 * out afterwards would quietly under-report with nothing to show it was
 * partial. Frozen, it is at least complete as of a date we can name.
 *
 * But whether someone IS a former member is derived, never stored. A stored
 * flag would need flipping on every rejoin and would eventually disagree with
 * the roster. Derived, a rejoin costs nothing: they reappear on the roster,
 * stop matching the query, and their archive row stays as the history of the
 * stint that ended.
 */
class MemberArchiveService
{
    /**
     * Freeze one character's completed membership.
     *
     * Called from departure detection, so it runs within half an hour of the
     * actual leave. By then their affiliation has already flipped, which is how
     * we learn where they went; wallet and mining are keyed to the character
     * and still readable.
     */
    public function recordDeparture(int $characterId, int $corporationId, ?int $mainCharacterId = null): bool
    {
        if (!Schema::hasTable('hr_manager_member_archives')) {
            return false;
        }

        try {
            $leftAt   = now();
            $joinedAt = $this->stintStart($characterId, $corporationId);
            $userId   = $this->userIdFor($characterId);

            $destination = $this->destinationCorp($characterId, $corporationId);

            $archive = [
                'character_id'      => $characterId,
                'corporation_id'    => $corporationId,
                'character_name'    => $this->characterName($characterId),
                'user_id'           => $userId,
                'main_character_id' => $mainCharacterId,
                'joined_at'         => $joinedAt,
                'left_at'           => $leftAt,
                'days_in_corp'      => $joinedAt ? max(0, $joinedAt->diffInDays($leftAt)) : null,

                'destination_corporation_id'   => $destination['id'],
                'destination_corporation_name' => $destination['name'],
                'departure_type'               => $this->departureType($userId, $corporationId),

                'token_valid_at_departure' => $this->hasLiveToken($characterId),
                'source'                   => MemberArchive::SOURCE_RECORDED,
            ];

            $archive += $this->judgementsAtDeparture($userId, $corporationId);
            $archive += $this->contributionForStint($characterId, $joinedAt, $leftAt);
            $archive += $this->recordCounts($characterId, $userId);

            // Keyed on the stint: departure detection is overlap-guarded, but a
            // re-run must not file the same leave twice.
            MemberArchive::updateOrCreate(
                [
                    'character_id'   => $characterId,
                    'corporation_id' => $corporationId,
                    'left_at'        => $leftAt,
                ],
                $archive
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] member archive failed for ' . $characterId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Former members of these corps, rolled up per human.
     *
     * Derived: a closed stint whose character is not on the roster now. Anyone
     * who rejoined drops out of this on their own, with nothing to update.
     *
     * @param array<int>|null $allowedCorps null = admin (all corps)
     * @return array<int, array<string, mixed>>
     */
    public function formerMembers(?array $allowedCorps, ?string $search = null, int $limit = 500): array
    {
        if (!Schema::hasTable('hr_manager_member_archives')) {
            return [];
        }

        try {
            $query = MemberArchive::query()->orderByDesc('left_at');

            if ($allowedCorps !== null) {
                $query->whereIn('corporation_id', $allowedCorps ?: [0]);
            }

            if ($search !== null && trim($search) !== '') {
                $needle = '%' . trim($search) . '%';
                $query->where(function ($q) use ($needle) {
                    $q->where('character_name', 'like', $needle)
                      ->orWhere('character_id', 'like', $needle);
                });
            }

            // Pull more than the display limit: rows collapse per human, so one
            // person with several alts would otherwise cost several slots.
            $rows = $query->limit($limit * 4)->get();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] former-member read failed: ' . $e->getMessage());
            return [];
        }

        if ($rows->isEmpty()) {
            return [];
        }

        $onRoster = $this->currentRosterPairs($rows);

        $people = [];
        foreach ($rows as $row) {
            // Back in the corp: not a former member of it, whatever the archive
            // remembers. The row stays; it just does not belong on this list.
            if (isset($onRoster[$row->corporation_id . ':' . $row->character_id])) {
                continue;
            }

            $key = $row->humanKey();

            if (!isset($people[$key])) {
                $people[$key] = [
                    'key'             => $key,
                    'user_id'         => $row->user_id,
                    'display_name'    => $row->character_name ?: ('Character #' . $row->character_id),
                    'lead_character'  => (int) $row->character_id,
                    'characters'      => [],
                    'left_at'         => $row->left_at,
                    'corporation_ids' => [],
                    'days_in_corp'    => 0,
                    'wallet'          => 0.0,
                    'mining'          => 0.0,
                    'reconstructed'   => false,
                    'token_lost'      => false,
                    'was_blacklisted' => false,
                    'departure_type'  => $row->departure_type,
                ];
            }

            $people[$key]['characters'][] = [
                'character_id'   => (int) $row->character_id,
                'name'           => $row->character_name ?: ('Character #' . $row->character_id),
                'corporation_id' => (int) $row->corporation_id,
                'left_at'        => $row->left_at,
                'days_in_corp'   => $row->days_in_corp,
                'destination'    => $row->destination_corporation_name,
                'reconstructed'  => $row->isReconstructed(),
            ];

            $people[$key]['corporation_ids'][(int) $row->corporation_id] = (int) $row->corporation_id;

            // A person's tenure is their longest character's, not the sum: five
            // alts in the corp for a year is a year, not five.
            $people[$key]['days_in_corp'] = max($people[$key]['days_in_corp'], (int) $row->days_in_corp);
            $people[$key]['wallet']      += (float) $row->wallet_contributed;
            $people[$key]['mining']      += (float) $row->mining_contributed;

            // The most recent departure across their characters heads the row.
            if ($row->left_at && (!$people[$key]['left_at'] || $row->left_at->greaterThan($people[$key]['left_at']))) {
                $people[$key]['left_at']        = $row->left_at;
                $people[$key]['display_name']   = $row->character_name ?: $people[$key]['display_name'];
                $people[$key]['lead_character'] = (int) $row->character_id;
                $people[$key]['departure_type'] = $row->departure_type;
            }

            $people[$key]['reconstructed']   = $people[$key]['reconstructed'] || $row->isReconstructed();
            $people[$key]['token_lost']      = $people[$key]['token_lost'] || !$row->token_valid_at_departure;
            $people[$key]['was_blacklisted'] = $people[$key]['was_blacklisted'] || $row->was_blacklisted;
        }

        // A nominated main is a better label than whichever alt left last.
        foreach ($people as $key => $person) {
            if ($person['user_id']) {
                $mainName = $this->mainNameForUser((int) $person['user_id']);
                if ($mainName !== null) {
                    $people[$key]['display_name'] = $mainName;
                }
            }
            $people[$key]['corporation_ids'] = array_values($person['corporation_ids']);
        }

        $people = array_values($people);
        usort($people, function ($a, $b) {
            $at = $a['left_at'] ? $a['left_at']->getTimestamp() : 0;
            $bt = $b['left_at'] ? $b['left_at']->getTimestamp() : 0;

            return $bt <=> $at;
        });

        return array_slice($people, 0, $limit);
    }

    /**
     * Every archived stint for one human, for the detail page.
     *
     * @param array<int>|null $allowedCorps
     */
    public function stintsForHuman(string $humanKey, ?array $allowedCorps)
    {
        if (!Schema::hasTable('hr_manager_member_archives')) {
            return collect();
        }

        [$kind, $value] = array_pad(explode(':', $humanKey, 2), 2, null);
        if (!in_array($kind, ['user', 'main', 'char'], true) || !ctype_digit((string) $value)) {
            return collect();
        }

        try {
            $query = MemberArchive::query();

            if ($kind === 'user') {
                $query->where('user_id', (int) $value);
            } elseif ($kind === 'main') {
                $query->whereNull('user_id')->where('main_character_id', (int) $value);
            } else {
                $query->whereNull('user_id')->whereNull('main_character_id')->where('character_id', (int) $value);
            }

            if ($allowedCorps !== null) {
                $query->whereIn('corporation_id', $allowedCorps ?: [0]);
            }

            return $query->orderByDesc('left_at')->get();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] former-member stint read failed: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Which (corp, character) pairs are on a roster right now.
     *
     * One query for the whole page rather than a check per row.
     *
     * @return array<string, true>
     */
    private function currentRosterPairs($rows): array
    {
        $charIds = $rows->pluck('character_id')->map(fn ($c) => (int) $c)->unique()->values()->all();
        if (empty($charIds)) {
            return [];
        }

        $out = [];
        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            try {
                foreach (array_chunk($charIds, 1000) as $chunk) {
                    foreach (DB::table($table)->whereIn('character_id', $chunk)->get(['corporation_id', 'character_id']) as $r) {
                        $out[(int) $r->corporation_id . ':' . (int) $r->character_id] = true;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Snapshot pieces
    // -----------------------------------------------------------------

    /** Start of the character's most recent stint in this corp. */
    private function stintStart(int $characterId, int $corporationId): ?Carbon
    {
        if (!Schema::hasTable('character_corporation_histories')) {
            return null;
        }

        try {
            $row = DB::table('character_corporation_histories')
                ->where('character_id', $characterId)
                ->where('corporation_id', $corporationId)
                ->orderByDesc('start_date')
                ->first(['start_date']);

            return ($row && $row->start_date) ? Carbon::parse($row->start_date) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Where they went. Read from affiliation, which by the time departure
     * detection runs has already been updated to their new corp.
     *
     * @return array{id:?int, name:?string}
     */
    private function destinationCorp(int $characterId, int $fromCorporationId): array
    {
        try {
            $corpId = DB::table('character_affiliations')
                ->where('character_id', $characterId)
                ->value('corporation_id');

            $corpId = $corpId ? (int) $corpId : null;
            if (!$corpId || $corpId === $fromCorporationId) {
                return ['id' => null, 'name' => null];
            }

            $name = null;
            if (Schema::hasTable('corporation_infos')) {
                $name = DB::table('corporation_infos')->where('corporation_id', $corpId)->value('name');
            }

            return ['id' => $corpId, 'name' => $name ? (string) $name : null];
        } catch (\Throwable $e) {
            return ['id' => null, 'name' => null];
        }
    }

    /**
     * Did they leave, or were they made to?
     *
     * Read from the purge record rather than guessed: a member who was marked
     * for purge and then departed was removed, and a record calling that a
     * resignation would misrepresent both the member and the corp.
     */
    private function departureType(?int $userId, int $corporationId): string
    {
        if ($userId === null || !Schema::hasTable('hr_manager_player_status')) {
            return MemberArchive::DEPARTURE_UNKNOWN;
        }

        try {
            $status = DB::table('hr_manager_player_status')
                ->where('user_id', $userId)
                ->where('corporation_id', $corporationId)
                ->first(['status']);

            // marked_for_purge is the only purge state PlayerStatus holds; a
            // completed purge closes the record rather than setting a
            // 'purged' status, so departing while still marked IS the signal.
            if ($status && $status->status === \HrManager\Models\PlayerStatus::STATUS_MARKED_FOR_PURGE) {
                return MemberArchive::DEPARTURE_PURGED;
            }
        } catch (\Throwable $e) {
            return MemberArchive::DEPARTURE_UNKNOWN;
        }

        return MemberArchive::DEPARTURE_RESIGNED;
    }

    /**
     * Tier and classification as they stood.
     *
     * These are what make the snapshot worth taking: recomputed later,
     * days-inactive keeps rising and everyone historical reads as dead weight
     * however good they actually were.
     *
     * @return array<string, mixed>
     */
    private function judgementsAtDeparture(?int $userId, int $corporationId): array
    {
        $out = [
            'tier_at_departure'           => null,
            'classification_at_departure' => null,
            'days_inactive_at_departure'  => null,
        ];

        if ($userId === null || !Schema::hasTable('hr_manager_player_classifications')) {
            return $out;
        }

        try {
            $row = DB::table('hr_manager_player_classifications')
                ->where('user_id', $userId)
                ->where('corporation_id', $corporationId)
                ->first();

            if ($row) {
                $out['tier_at_departure'] = isset($row->tier_level) ? (string) $row->tier_level : null;
                // The classifier's column is `category` (active / at_risk /
                // inactive / dead_weight).
                $out['classification_at_departure'] = $row->category ?? null;
                $out['days_inactive_at_departure']  = isset($row->days_inactive) ? (int) $row->days_inactive : null;
            }
        } catch (\Throwable $e) {
            // A missing judgement is not worth failing the whole archive over.
            Log::debug('[HR Manager] archive classification read failed: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Wallet and mining over this stint only, so the figure means "contributed
     * while a member" rather than "ever".
     *
     * @return array<string, mixed>
     */
    private function contributionForStint(int $characterId, ?Carbon $from, Carbon $to): array
    {
        $out = ['wallet_contributed' => null, 'mining_contributed' => null, 'tax_compliance_pct' => null];

        try {
            if (Schema::hasTable('character_wallet_journals')) {
                $q = DB::table('character_wallet_journals')
                    ->where('character_id', $characterId)
                    ->where('amount', '<', 0)
                    ->whereIn('ref_type', ['corporation_account_withdrawal', 'player_donation'])
                    ->where('date', '<=', $to);
                if ($from) {
                    $q->where('date', '>=', $from);
                }
                $out['wallet_contributed'] = abs((float) $q->sum('amount'));
            }
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] archive wallet read failed: ' . $e->getMessage());
        }

        try {
            if (Schema::hasTable('mining_ledger')) {
                $q = DB::table('mining_ledger')
                    ->where('character_id', $characterId)
                    ->where('date', '<=', $to);
                if ($from) {
                    $q->where('date', '>=', $from);
                }
                $out['mining_contributed'] = (float) $q->sum('quantity');
            }
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] archive mining read failed: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * How much written record exists about them.
     *
     * Counts only. The notes and intel themselves are keyed to the character or
     * account and survive independently, so copying them here would duplicate
     * data that was never at risk.
     *
     * @return array<string, mixed>
     */
    private function recordCounts(int $characterId, ?int $userId): array
    {
        $out = ['note_count' => 0, 'intel_count' => 0, 'was_blacklisted' => false];

        try {
            if ($userId !== null && Schema::hasTable('hr_manager_notes')) {
                $out['note_count'] = (int) DB::table('hr_manager_notes')
                    ->where('noteable_type', 'player')
                    ->where('noteable_id', $userId)
                    ->whereNull('deleted_at')
                    ->count();
            }

            if (Schema::hasTable('hr_manager_intel_notes')) {
                $out['intel_count'] = (int) DB::table('hr_manager_intel_notes')
                    ->where('character_id', $characterId)->count();
            }

            if (Schema::hasTable('hr_manager_watchlist_entries')) {
                $out['was_blacklisted'] = DB::table('hr_manager_watchlist_entries')
                    ->where('character_id', $characterId)
                    ->where('list_type', 'blacklist')
                    ->exists();
            }
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] archive count read failed: ' . $e->getMessage());
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Small lookups
    // -----------------------------------------------------------------

    private function userIdFor(int $characterId): ?int
    {
        try {
            // Query builder so a revoked (soft-deleted) token still resolves.
            // Somebody who dropped their key on the way out is still the same
            // human, and losing the account link would orphan their record.
            $id = DB::table('refresh_tokens')->where('character_id', $characterId)->value('user_id');

            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function hasLiveToken(int $characterId): bool
    {
        try {
            return DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->whereNull('deleted_at')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function characterName(int $characterId): ?string
    {
        try {
            $name = DB::table('character_infos')->where('character_id', $characterId)->value('name');

            return $name ? (string) $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function mainNameForUser(int $userId): ?string
    {
        try {
            $name = DB::table('users')
                ->join('character_infos as ci', 'ci.character_id', '=', 'users.main_character_id')
                ->where('users.id', $userId)
                ->value('ci.name');

            return $name ? (string) $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

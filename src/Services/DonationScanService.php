<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\DonationFlag;
use HrManager\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Finds direct ISK transfers between corp members and entities the corp rates
 * badly, and records them.
 *
 * Only ref_type = 'player_donation'. That is a wallet-to-wallet transfer with
 * nothing given back, which is why it is worth looking at: a contract or a
 * market trade against a hostile entity is ordinary commerce and would bury the
 * signal in noise. Someone handing ISK to an entity their corp rates terrible is
 * a small, specific thing worth a director's attention.
 *
 * Two tiers, because the same transfer means different things at different
 * times. Anything before the human was in the corp is history: a fact on the
 * record, not something they did to you. Anything after they were already
 * inside is the one that matters.
 *
 * A flag is an observation, not an accusation. People trade with people their
 * corp dislikes for ordinary reasons, and the point is to let a director see a
 * pattern rather than to convict anyone on a single row.
 *
 * The scan is scheduled and incremental. The journal is one of the biggest
 * tables SeAT holds, so nothing here may ever run on a page render, and each
 * pass reads only what is new since the last one.
 */
class DonationScanService
{
    public const SETTING_ENABLED       = 'donation_flags_enabled';
    public const SETTING_FLOOR_NEUTRAL = 'donation_flags_floor_neutral';
    public const SETTING_FLOOR_SUSPECT = 'donation_flags_floor_suspect';
    public const SETTING_MAX_STANDING  = 'donation_flags_max_standing';

    /** Defaults chosen so the feature is quiet rather than noisy on day one. */
    public const DEFAULT_FLOOR_NEUTRAL = 500_000_000;   // history: only large transfers
    public const DEFAULT_FLOOR_SUSPECT = 100_000_000;   // since joining: more sensitive
    public const DEFAULT_MAX_STANDING  = -5;            // bad and terrible both count

    /** Journal rows pulled per character per pass. Bounds a first backfill. */
    private const CHUNK = 5000;

    /**
     * Passes to spend holding position for an unidentifiable counterparty
     * before giving up on those rows and moving on.
     *
     * Holding is right for an ESI outage, which passes. It is wrong for a
     * counterparty that can never be resolved: ESI rejects a whole name
     * lookup if one id in the batch is invalid, so a single long-dead
     * character would otherwise stall a member's scan forever and cost them
     * every future donation too. Three nights is long enough to ride out an
     * outage and short enough that a permanent block does not go unnoticed.
     */
    private const MAX_HALTS = 3;

    /**
     * Resolution memos for the length of one scan run.
     *
     * Counterparties repeat heavily: the same handful of entities turn up
     * across many members, and a backfill walks every character in the corp.
     * Without this each character's batch would re-ask ESI for people it just
     * looked up. Affiliations in particular have no local cache to fall back
     * on for anyone outside SeAT, which is most counterparties.
     *
     * @var array<int, array{corporation_id:?int, alliance_id:?int}>
     */
    private array $affiliationMemo = [];

    /** @var array<int, array{name:string, category:string}> */
    private array $entityMemo = [];

    public function __construct(
        private StandingsReferenceService $standings,
        private NameResolutionService $names,
        private AccountCharacterResolver $accounts
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_ENABLED, false);
    }

    public function floorNeutral(): float
    {
        return (float) Setting::getValue(self::SETTING_FLOOR_NEUTRAL, self::DEFAULT_FLOOR_NEUTRAL);
    }

    public function floorSuspect(): float
    {
        return (float) Setting::getValue(self::SETTING_FLOOR_SUSPECT, self::DEFAULT_FLOOR_SUSPECT);
    }

    /** Counterparties rated at or below this are worth recording. */
    public function maxStanding(): int
    {
        return (int) Setting::getValue(self::SETTING_MAX_STANDING, self::DEFAULT_MAX_STANDING);
    }

    /**
     * Run a pass.
     *
     * @param int|null $characterLimit stop after this many characters (a first
     *                                 backfill on a big corp is the one run that
     *                                 can take real time, so it can be spread
     *                                 over several passes)
     * @return array<string, mixed>
     */
    public function scan(?int $characterLimit = null): array
    {
        $out = [
            'available'  => true,
            'enabled'    => $this->isEnabled(),
            'configured' => false,
            'characters' => 0,
            'rows'       => 0,
            'flagged'    => 0,
            'suspect'    => 0,
            'skipped'    => 0,
        ];

        if (!Schema::hasTable('hr_manager_donation_flags')
            || !Schema::hasTable('hr_manager_donation_scan_state')
            || !Schema::hasTable('character_wallet_journals')) {
            $out['available'] = false;
            return $out;
        }

        if (!$out['enabled']) {
            return $out;
        }

        // No standings, nothing to compare against. Bail before touching the
        // journal rather than reading it all and matching nothing.
        $rated = $this->standings->resolvedStandings();
        if (empty($rated)) {
            return $out;
        }
        $out['configured'] = true;

        $floorMin = min($this->floorNeutral(), $this->floorSuspect());
        $maxStand = $this->maxStanding();

        foreach ($this->trackedCorporations() as $corporationId) {
            $roster = $this->rosterMemberIds($corporationId);
            if (empty($roster)) {
                continue;
            }

            // Join dates come from the WHOLE roster, not just the characters
            // being scanned. An account whose main joined two years ago but
            // never authed a wallet scope would otherwise be dated from
            // whichever alt happens to have a journal, making a long-standing
            // member look like a fresh recruit.
            $joinDates = $this->accountJoinDates($roster, $corporationId);

            foreach ($this->withWalletJournals($roster) as $characterId) {
                if ($characterLimit !== null && $out['characters'] >= $characterLimit) {
                    return $out;
                }

                $result = $this->scanCharacter($characterId, $corporationId, $floorMin, $maxStand, $joinDates);

                $out['characters']++;
                $out['rows']    += $result['rows'];
                $out['flagged'] += $result['flagged'];
                $out['suspect'] += $result['suspect'];
                $out['skipped'] += $result['skipped'];
            }
        }

        return $out;
    }

    /**
     * @param array<int, ?Carbon> $joinDates account-level join date per character
     * @return array{rows:int, flagged:int, suspect:int, skipped:int}
     */
    private function scanCharacter(
        int $characterId,
        int $corporationId,
        float $floorMin,
        int $maxStanding,
        array $joinDates
    ): array {
        $res = ['rows' => 0, 'flagged' => 0, 'suspect' => 0, 'skipped' => 0];

        $state     = $this->scanState($characterId);
        $watermark = (int) ($state->last_journal_id ?? 0);
        $halts     = (int) ($state->halt_count ?? 0);

        // Held position long enough. Push through this pass so the member's
        // scan cannot be blocked indefinitely by rows nothing will ever
        // resolve; the transfers themselves stay in the journal.
        $forceThrough = $halts >= self::MAX_HALTS;

        try {
            $rows = DB::table('character_wallet_journals')
                ->where('character_id', $characterId)
                ->where('ref_type', 'player_donation')
                ->where('id', '>', $watermark)
                ->whereRaw('ABS(amount) >= ?', [$floorMin])
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'first_party_id', 'second_party_id', 'amount', 'date', 'reason', 'description']);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] donation scan: journal read failed for ' . $characterId . ': ' . $e->getMessage());
            return $res;
        }

        // Nothing new. Still stamp the pass so the UI can tell "looked, found
        // nothing" apart from "never looked".
        if ($rows->isEmpty()) {
            $this->markScanned($characterId, $watermark, null, true, 0);
            return $res;
        }

        $res['rows'] = $rows->count();

        // Resolve every counterparty in this batch at once rather than per row.
        $counterpartyIds = [];
        foreach ($rows as $row) {
            $cp = $this->counterparty($characterId, $row);
            if ($cp !== null) {
                $counterpartyIds[$cp] = $cp;
            }
        }

        $affiliations = $this->memoised($this->affiliationMemo, array_values($counterpartyIds),
            fn (array $ids) => $this->names->resolveAffiliations($ids));
        $entities = $this->memoised($this->entityMemo, array_values($counterpartyIds),
            fn (array $ids) => $this->names->resolveEntityNames($ids));

        // A counterparty that is itself a corporation needs its alliance too;
        // resolveAffiliations only speaks for characters.
        $corpCounterparties = [];
        foreach ($counterpartyIds as $id) {
            if (($entities[$id]['category'] ?? null) === 'corporation') {
                $corpCounterparties[] = $id;
            }
        }
        $corpAlliances = $this->names->resolveCorporationAlliances($corpCounterparties);

        $floorNeutral = $this->floorNeutral();
        $floorSuspect = $this->floorSuspect();
        $joinedAt     = $joinDates[$characterId] ?? null;
        $now          = now();

        $lastId   = $watermark;
        $lastDate = null;

        // Once a row cannot be judged, the watermark stops moving. Advancing
        // past it would mean an ESI outage silently buried those donations
        // forever: the scan never looks back, so a row skipped for a reason
        // that was temporary would never get a second chance. Later rows are
        // still processed, and re-processed next run, which is harmless
        // because writes are keyed on the journal row.
        $halted = false;

        foreach ($rows as $row) {
            $cp = $this->counterparty($characterId, $row);

            // A transfer with no other party is malformed, not unresolved.
            // Nothing will ever make it judgeable, so it must not halt.
            if ($cp === null) {
                $res['skipped']++;
                if (!$halted) {
                    $lastId   = max($lastId, (int) $row->id);
                    $lastDate = $row->date;
                }
                continue;
            }

            $type = $entities[$cp]['category'] ?? null;

            if ($type === null) {
                // Could not resolve WHAT this counterparty is. Almost always
                // ESI being unavailable, so hold the line and retry next pass
                // unless we have already held it too many times.
                $res['skipped']++;
                if (!$forceThrough) {
                    $halted = true;
                    continue;
                }
                Log::info('[HR Manager] donation scan: giving up on unresolvable counterparty '
                    . $cp . ' for character ' . $characterId . ' after ' . $halts . ' held passes.');
                if (!$halted) {
                    $lastId   = max($lastId, (int) $row->id);
                    $lastDate = $row->date;
                }
                continue;
            }

            if (!in_array($type, ['character', 'corporation', 'alliance'], true)) {
                // A real entity of a kind no standings list can rate (a
                // faction, a structure). Settled, so it does not halt.
                $res['skipped']++;
                if (!$halted) {
                    $lastId   = max($lastId, (int) $row->id);
                    $lastDate = $row->date;
                }
                continue;
            }

            if (!$halted) {
                $lastId   = max($lastId, (int) $row->id);
                $lastDate = $row->date;
            }

            // Where this entity sits, so an inherited rating can be found.
            $corpId     = null;
            $allianceId = null;
            if ($type === 'character') {
                $corpId     = $affiliations[$cp]['corporation_id'] ?? null;
                $allianceId = $affiliations[$cp]['alliance_id'] ?? null;
            } elseif ($type === 'corporation') {
                $allianceId = $corpAlliances[$cp] ?? null;
            }

            $verdict = $this->standings->standingForEntity($type, $cp, $corpId, $allianceId);
            if ($verdict === null || $verdict['standing'] > $maxStanding) {
                continue; // not rated, or not rated badly enough
            }

            $amount    = (float) $row->amount;
            $direction = $amount < 0 ? DonationFlag::DIRECTION_OUT : DonationFlag::DIRECTION_IN;
            $abs       = abs($amount);

            // A transfer with no known join date stays neutral. Guessing the
            // accusing tier from missing data is the wrong way to be wrong.
            $occurredAt = $row->date ? Carbon::parse($row->date) : null;
            $isSuspect  = $joinedAt !== null && $occurredAt !== null && $occurredAt->greaterThanOrEqualTo($joinedAt);

            if ($abs < ($isSuspect ? $floorSuspect : $floorNeutral)) {
                continue;
            }

            try {
                DonationFlag::updateOrCreate(
                    ['character_id' => $characterId, 'journal_id' => (int) $row->id],
                    [
                        'corporation_id'    => $corporationId,
                        'counterparty_id'   => $cp,
                        'counterparty_type' => $type,
                        'counterparty_name' => $entities[$cp]['name'] ?? null,
                        'amount'            => $abs,
                        'direction'         => $direction,
                        'occurred_at'       => $occurredAt,
                        'standing'          => $verdict['standing'],
                        'standing_from'     => $verdict['from'],
                        'via_type'          => $verdict['via_type'],
                        'via_id'            => $verdict['via_id'],
                        'resolved_at'       => $now,
                        'tier'              => $isSuspect ? DonationFlag::TIER_SUSPECT : DonationFlag::TIER_NEUTRAL,
                        'reason'            => $this->reasonText($row),
                    ]
                );

                $res['flagged']++;
                if ($isSuspect) {
                    $res['suspect']++;
                }
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] donation flag write failed: ' . $e->getMessage());
                $res['skipped']++;
            }
        }

        // A full chunk means there is more behind it, and a halt means part of
        // this one still needs judging: the character is only "backfilled" once
        // a pass comes back short AND resolved everything it read.
        $this->markScanned($characterId, $lastId, $lastDate, !$halted && $rows->count() < self::CHUNK, $halted ? $halts + 1 : 0);

        return $res;
    }

    /**
     * Answer from the memo where possible, resolve the rest, remember them.
     *
     * Only successful lookups are remembered. A failure is usually ESI being
     * down for a moment, and caching that for the rest of the run would turn a
     * blip into a whole scan's worth of unjudged rows.
     *
     * @param array<int, mixed> $memo
     * @param array<int>        $ids
     * @return array<int, mixed>
     */
    private function memoised(array &$memo, array $ids, callable $resolver): array
    {
        $out     = [];
        $missing = [];

        foreach ($ids as $id) {
            if (array_key_exists($id, $memo)) {
                $out[$id] = $memo[$id];
            } else {
                $missing[] = $id;
            }
        }

        if (!empty($missing)) {
            foreach ($resolver($missing) as $id => $value) {
                $memo[(int) $id] = $value;
                $out[(int) $id]  = $value;
            }
        }

        return $out;
    }

    /**
     * The other side of the transfer. Taken as whichever party is not this
     * character rather than trusting first/second to mean sender/receiver, so
     * the direction convention cannot silently invert the result.
     */
    private function counterparty(int $characterId, object $row): ?int
    {
        $first  = (int) ($row->first_party_id ?? 0);
        $second = (int) ($row->second_party_id ?? 0);

        if ($first > 0 && $first !== $characterId) {
            return $first;
        }
        if ($second > 0 && $second !== $characterId) {
            return $second;
        }

        return null;
    }

    private function reasonText(object $row): ?string
    {
        $text = trim((string) ($row->reason ?? ''));
        if ($text === '') {
            $text = trim((string) ($row->description ?? ''));
        }

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    /**
     * When each character's HUMAN joined the corp, keyed by character.
     *
     * Deliberately account-level: someone who joined on their main a year ago
     * and brings in an alt today has been inside for a year, and grading that
     * alt's transfers as though they were a fresh recruit would read the
     * timeline backwards. Matches the profile's own account-level tenure, which
     * takes the longest current stint across the account.
     *
     * @param array<int> $characterIds
     * @return array<int, ?Carbon>
     */
    private function accountJoinDates(array $characterIds, int $corporationId): array
    {
        $starts = $this->currentStintStarts($characterIds, $corporationId);
        $keys   = $this->accounts->accountKeysFor($characterIds);

        // Earliest start on an account is when that human got in.
        $byAccount = [];
        foreach ($characterIds as $cid) {
            $key   = $keys[$cid] ?? ('char:' . $cid);
            $start = $starts[$cid] ?? null;
            if ($start === null) {
                continue;
            }
            if (!isset($byAccount[$key]) || $start->lessThan($byAccount[$key])) {
                $byAccount[$key] = $start;
            }
        }

        $out = [];
        foreach ($characterIds as $cid) {
            $out[$cid] = $byAccount[$keys[$cid] ?? ('char:' . $cid)] ?? null;
        }

        return $out;
    }

    /**
     * Start date of each character's CURRENT stint in the corp, or null when
     * they are not in it now.
     *
     * The last corp-history row is the character's current corporation, so a
     * stint is current when that row names this corp. One query for the whole
     * roster rather than the per-character walk the profile can afford.
     *
     * @param array<int> $characterIds
     * @return array<int, ?Carbon>
     */
    private function currentStintStarts(array $characterIds, int $corporationId): array
    {
        $out = [];
        if (empty($characterIds) || !Schema::hasTable('character_corporation_histories')) {
            return $out;
        }

        try {
            foreach (array_chunk($characterIds, 1000) as $chunk) {
                $rows = DB::table('character_corporation_histories')
                    ->whereIn('character_id', $chunk)
                    ->orderBy('character_id')
                    ->orderBy('start_date')
                    ->get(['character_id', 'corporation_id', 'start_date']);

                $latest = [];
                foreach ($rows as $r) {
                    // Ordered by start_date, so the last one seen wins.
                    $latest[(int) $r->character_id] = $r;
                }

                foreach ($latest as $cid => $r) {
                    $out[$cid] = ((int) $r->corporation_id === $corporationId && $r->start_date)
                        ? Carbon::parse($r->start_date)
                        : null;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] donation scan: corp history read failed: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Corps HR can actually see a roster for. Scanning anything else would read
     * journals with no membership context to grade them against.
     *
     * @return array<int>
     */
    private function trackedCorporations(): array
    {
        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            try {
                $ids = DB::table($table)
                    ->distinct()
                    ->pluck('corporation_id')
                    ->map(fn ($c) => (int) $c)
                    ->filter()
                    ->values()
                    ->all();
                if (!empty($ids)) {
                    return $ids;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return [];
    }

    /**
     * Every character on the corp's roster, registered or not.
     *
     * @return array<int>
     */
    private function rosterMemberIds(int $corporationId): array
    {
        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            try {
                $roster = DB::table($table)
                    ->where('corporation_id', $corporationId)
                    ->pluck('character_id')
                    ->map(fn ($c) => (int) $c)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                continue;
            }
            if (!empty($roster)) {
                return $roster;
            }
        }

        return [];
    }

    /**
     * Narrow a roster to the characters that actually have a wallet journal. A
     * member who never authed with the wallet scope has nothing to read, and
     * including them would mean a scan-state row per unregistered alt for no
     * benefit.
     *
     * @param array<int> $roster
     * @return array<int>
     */
    private function withWalletJournals(array $roster): array
    {
        if (empty($roster)) {
            return [];
        }

        try {
            $out = [];
            foreach (array_chunk($roster, 1000) as $chunk) {
                $out = array_merge($out, DB::table('character_wallet_journals')
                    ->whereIn('character_id', $chunk)
                    ->distinct()
                    ->pluck('character_id')
                    ->map(fn ($c) => (int) $c)
                    ->all());
            }
            return array_values(array_unique($out));
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] donation scan: roster narrowing failed: ' . $e->getMessage());
            return [];
        }
    }

    private function scanState(int $characterId): object
    {
        $row = DB::table('hr_manager_donation_scan_state')->where('character_id', $characterId)->first();

        return $row ?? (object) ['last_journal_id' => 0, 'backfilled' => false, 'halt_count' => 0];
    }

    private function markScanned(int $characterId, int $lastId, $lastDate, bool $backfilled, int $haltCount = 0): void
    {
        try {
            $payload = [
                'last_journal_id' => $lastId,
                'last_journal_at' => $lastDate ? Carbon::parse($lastDate) : null,
                'scanned_at'      => now(),
                'backfilled'      => $backfilled,
                'halt_count'      => min($haltCount, 255),
                'updated_at'      => now(),
            ];

            // updateOrInsert applies the same payload to both branches, so
            // created_at only goes in when the row is genuinely new.
            $exists = DB::table('hr_manager_donation_scan_state')
                ->where('character_id', $characterId)->exists();
            if (!$exists) {
                $payload['created_at'] = now();
            }

            DB::table('hr_manager_donation_scan_state')->updateOrInsert(
                ['character_id' => $characterId],
                $payload
            );
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] donation scan state write failed: ' . $e->getMessage());
        }
    }

    /**
     * Throw away everything and start over on the next pass.
     *
     * The scan is incremental, which means a journal row it decided not to flag
     * is never looked at again: the watermark has moved past it. That is what
     * makes a nightly run cheap, and it is also why the results are only ever
     * as good as the settings that were in force when each row was read. Raise
     * a floor, widen which ratings count, or add an entity to the standings
     * list, and everything already scanned would keep reflecting the old rules
     * while new rows follow the new ones -- one list, two sets of criteria, and
     * no way for a director to tell which row followed which.
     *
     * So changing any of that rebuilds rather than patching. The next scan
     * re-reads every journal from the beginning, which costs what the first one
     * cost. Flags are cleared too: leaving them would mean showing a director
     * findings their current settings would not produce.
     */
    public function rebuild(): bool
    {
        try {
            if (Schema::hasTable('hr_manager_donation_flags')) {
                DB::table('hr_manager_donation_flags')->delete();
            }
            if (Schema::hasTable('hr_manager_donation_scan_state')) {
                DB::table('hr_manager_donation_scan_state')->delete();
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] donation flag rebuild failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Has a pass ever completed? Distinguishes "scanned, found nothing" from
     * "never looked", which read identically as an empty list.
     */
    public function hasScanned(): bool
    {
        if (!Schema::hasTable('hr_manager_donation_scan_state')) {
            return false;
        }

        try {
            return DB::table('hr_manager_donation_scan_state')->limit(1)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Flags for one account, newest first. The player profile's read path.
     *
     * @param array<int>      $characterIds
     * @param array<int>|null $allowedCorps corps the viewer may see; null = all
     *                                      (admin), matching ScopesCorporationAccess
     * @return array<string, mixed>
     */
    public function flagsForCharacters(array $characterIds, ?array $allowedCorps = null, int $limit = 25): array
    {
        $empty = ['available' => false, 'rows' => [], 'suspect' => 0, 'neutral' => 0, 'total' => 0];

        if (empty($characterIds) || !Schema::hasTable('hr_manager_donation_flags')) {
            return $empty;
        }

        // A profile spans a whole human, whose alts may sit in corps this
        // viewer has no access to. Same rule the blacklist banner follows: the
        // page may not show a finding that belongs to a corp they cannot see.
        $scoped = function ($query) use ($characterIds, $allowedCorps) {
            $query = $query->whereIn('character_id', $characterIds);
            if ($allowedCorps !== null) {
                $query = $query->whereIn('corporation_id', $allowedCorps ?: [0]);
            }
            return $query;
        };

        try {
            $counts = $scoped(DonationFlag::query())
                ->selectRaw('tier, COUNT(*) as c')
                ->groupBy('tier')
                ->pluck('c', 'tier')
                ->toArray();

            // Suspect first, then most recent: the ordering a director reads in.
            $rows = $scoped(DonationFlag::query())
                ->orderByRaw("CASE WHEN tier = ? THEN 0 ELSE 1 END", [DonationFlag::TIER_SUSPECT])
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] donation flag read failed: ' . $e->getMessage());
            return $empty;
        }

        $suspect = (int) ($counts[DonationFlag::TIER_SUSPECT] ?? 0);
        $neutral = (int) ($counts[DonationFlag::TIER_NEUTRAL] ?? 0);

        return [
            'available' => true,
            'rows'      => $rows,
            'suspect'   => $suspect,
            'neutral'   => $neutral,
            'total'     => $suspect + $neutral,
        ];
    }
}

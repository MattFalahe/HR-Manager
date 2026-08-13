<?php

namespace HrManager\Services;

use HrManager\Models\Setting;
use HrManager\Models\StandingEntry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the corp's standings reference: a NUMERIC value per entity on EVE's
 * own five-step scale (-10 / -5 / 0 / +5 / +10). Four modes:
 *
 *   - 'off'    : no standings signal at all.
 *   - 'seat'   : SeAT's Standings Builder (Tools -> Standings) alone.
 *   - 'own'    : HR's own standings table alone.
 *   - 'hybrid' : SeAT as the baseline, HR overriding it per entity. Overrides
 *                work downward too, so an entity the alliance marks terrible
 *                can be set neutral locally and stop generating flags.
 *
 * The value used to be discarded: SeAT's rows were read and collapsed to two
 * flat hostile/friendly buckets, so HR could only ever say WHETHER an entity
 * was hostile, never HOW hostile. resolvedStandings() is the source of truth
 * now and reference()'s buckets are derived from it, which keeps every existing
 * caller working while new ones get the number.
 *
 * There are two independent precedences and they are easy to confuse:
 *   - SOURCE precedence   : HR over SeAT, in hybrid mode.
 *   - ENTITY precedence   : a character's corp over their alliance, or the
 *                           reverse, when both are rated ('corp' | 'alliance').
 *
 * Reference entities are bucketed by category ('alliance'|'corporation'|
 * 'character'), which matches BOTH StandingsProfileStanding.category and
 * CharacterContact.contact_type exactly, so matching an applicant's contact is
 * a direct set-membership test.
 *
 * Reference entities are bucketed by category ('alliance'|'corporation'|
 * 'character'), which matches BOTH StandingsProfileStanding.category and
 * CharacterContact.contact_type exactly, so matching an applicant's contact is
 * a direct set-membership test.
 */
class StandingsReferenceService
{
    public const SOURCE_OFF  = 'off';
    public const SOURCE_SEAT = 'seat';
    public const SOURCE_OWN  = 'own';

    /**
     * SeAT's profile as the baseline, HR's own table overriding it per entity.
     * The useful shape for most corps: you are not maintaining a parallel list,
     * only the handful of entities where your corp's view differs from the
     * alliance's — including overriding DOWNWARD, so an entity your alliance
     * marks terrible can be set neutral locally and stop generating flags.
     */
    public const SOURCE_HYBRID = 'hybrid';

    public const PRECEDENCE_CORP     = 'corp';     // most specific entry wins
    public const PRECEDENCE_ALLIANCE = 'alliance'; // alliance-level verdict wins

    public const SETTING_SOURCE             = 'assess_standings_source';
    public const SETTING_SEAT_PROFILE       = 'assess_standings_seat_profile';
    public const SETTING_PRECEDENCE         = 'assess_standings_precedence';
    public const SETTING_HOSTILE_ALLIANCES  = 'assess_hostile_alliances';
    public const SETTING_HOSTILE_CORPS      = 'assess_hostile_corps';
    public const SETTING_FRIENDLY_ALLIANCES = 'assess_friendly_alliances';
    public const SETTING_FRIENDLY_CORPS     = 'assess_friendly_corps';

    /** Per-request memos. The resolution is read many times per page. */
    private ?array $cache = null;
    private ?array $resolved = null;
    private ?array $seatCache = null;
    private ?array $hrCache = null;

    public function source(): string
    {
        $s = (string) Setting::getValue(self::SETTING_SOURCE, self::SOURCE_OFF);

        return in_array($s, [self::SOURCE_SEAT, self::SOURCE_OWN, self::SOURCE_HYBRID], true)
            ? $s
            : self::SOURCE_OFF;
    }

    /**
     * Every entity's NUMERIC standing under the current source mode, keyed
     * "type:id", each carrying where the value came from.
     *
     * This is the source of truth now; the older hostile/friendly arrays in
     * reference() are derived from it, so existing callers keep working while
     * new ones can ask how hostile rather than merely whether.
     *
     * @return array<string, array{standing:int, from:string, seat_standing:?int}>
     */
    public function resolvedStandings(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $source = $this->source();
        $out    = [];

        if ($source === self::SOURCE_OFF) {
            return $this->resolved = $out;
        }

        // SeAT baseline (seat + hybrid).
        if ($source === self::SOURCE_SEAT || $source === self::SOURCE_HYBRID) {
            foreach ($this->seatStandings() as $key => $val) {
                $out[$key] = ['standing' => $val, 'from' => self::SOURCE_SEAT, 'seat_standing' => $val];
            }
        }

        // HR's own table (own + hybrid). In hybrid this OVERRIDES the baseline
        // per entity; the SeAT value is kept alongside so the UI can show what
        // was overridden rather than leaving a director wondering why a number
        // disagrees with their alliance's.
        if ($source === self::SOURCE_OWN || $source === self::SOURCE_HYBRID) {
            foreach ($this->hrStandings() as $key => $val) {
                $seatVal = $out[$key]['seat_standing'] ?? null;
                $out[$key] = ['standing' => $val, 'from' => self::SOURCE_OWN, 'seat_standing' => $seatVal];
            }
        }

        return $this->resolved = $out;
    }

    /**
     * One entity's standing, or null when nothing rates it.
     *
     * @return array{standing:int, from:string, seat_standing:?int}|null
     */
    public function standingFor(string $entityType, int $entityId): ?array
    {
        return $this->resolvedStandings()[$entityType . ':' . $entityId] ?? null;
    }

    /**
     * The numeric counterpart to verdict(): what do we think of this entity,
     * taking into account what it belongs to.
     *
     * verdict() answers hostile / friendly / no-opinion, which is enough to
     * flag an applicant's contact but not enough to record WHY, or to rank one
     * finding against another. This returns the value, plus which rated entity
     * actually supplied it — usually not the entity itself. A character is
     * rarely on a standings list by name; they inherit their corp's rating, or
     * their corp's alliance's. A caller that shows "hostile" without saying it
     * was inherited is making a claim about a person on the strength of who
     * their employer is.
     *
     * The corp / alliance argument order matches verdict()'s precedence rule,
     * so the two can never disagree about the same entity.
     *
     * @return array{standing:int, from:string, via_type:string, via_id:int}|null
     */
    public function standingForEntity(
        string $entityType,
        int $entityId,
        ?int $corporationId = null,
        ?int $allianceId = null
    ): ?array {
        $hit = function (string $type, ?int $id) {
            if (!$id) {
                return null;
            }
            $found = $this->standingFor($type, $id);
            if ($found === null) {
                return null;
            }
            return [
                'standing' => (int) $found['standing'],
                'from'     => (string) $found['from'],
                'via_type' => $type,
                'via_id'   => $id,
            ];
        };

        // Named directly: nothing about their employer can override someone
        // the corp has an opinion about by name.
        $own = $hit($entityType, $entityId);
        if ($own !== null) {
            return $own;
        }

        if ($entityType === 'alliance') {
            return null; // an alliance belongs to nothing further up
        }

        // A corporation's own entry is its "corp level"; a character borrows
        // the corp they are in.
        $corpLevel = $entityType === 'corporation'
            ? null                                   // already checked above as $own
            : $hit('corporation', $corporationId);

        $allianceLevel = $hit('alliance', $allianceId);

        if ($corpLevel !== null && $allianceLevel !== null) {
            return $this->precedence() === self::PRECEDENCE_ALLIANCE ? $allianceLevel : $corpLevel;
        }

        return $corpLevel ?? $allianceLevel;
    }

    /**
     * Hybrid asked for but SeAT cannot supply a baseline (no profile chosen,
     * profile deleted, Standings Builder not in use). It still works — HR's own
     * entries carry it — but the operator should be told rather than left to
     * discover that half their configuration is inert.
     */
    public function hybridBaselineMissing(): bool
    {
        if ($this->source() !== self::SOURCE_HYBRID) {
            return false;
        }

        return empty($this->seatStandings());
    }

    /**
     * SeAT Standings Builder rows as numeric values, keyed "type:id".
     *
     * @return array<string, int>
     */
    private function seatStandings(): array
    {
        if ($this->seatCache !== null) {
            return $this->seatCache;
        }

        $out = [];
        if (!class_exists(\Seat\Web\Models\StandingsProfileStanding::class)) {
            return $this->seatCache = $out;
        }

        $profileId = (int) Setting::getValue(self::SETTING_SEAT_PROFILE, 0);
        if ($profileId <= 0) {
            return $this->seatCache = $out;
        }

        try {
            $rows = \Seat\Web\Models\StandingsProfileStanding::where('standings_profile_id', $profileId)->get();
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] standings profile load failed: ' . $e->getMessage());
            return $this->seatCache = $out;
        }

        foreach ($rows as $r) {
            $cat = (string) $r->category;
            if (!in_array($cat, ['alliance', 'corporation', 'character'], true)) {
                continue; // faction / unknown — nothing to match against
            }
            $out[$cat . ':' . (int) $r->entity_id] = (int) round((float) $r->standing);
        }

        return $this->seatCache = $out;
    }

    /**
     * HR's own standings table, keyed "type:id".
     *
     * @return array<string, int>
     */
    private function hrStandings(): array
    {
        if ($this->hrCache !== null) {
            return $this->hrCache;
        }

        $out = [];
        if (!Schema::hasTable('hr_manager_standings')) {
            return $this->hrCache = $out;
        }

        try {
            foreach (StandingEntry::get(['entity_type', 'entity_id', 'standing']) as $row) {
                $out[$row->entity_type . ':' . (int) $row->entity_id] = (int) $row->standing;
            }
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] HR standings load failed: ' . $e->getMessage());
        }

        return $this->hrCache = $out;
    }

    public function precedence(): string
    {
        $p = (string) Setting::getValue(self::SETTING_PRECEDENCE, self::PRECEDENCE_CORP);
        return $p === self::PRECEDENCE_ALLIANCE ? self::PRECEDENCE_ALLIANCE : self::PRECEDENCE_CORP;
    }

    /** True when a source is selected AND it yields at least one hostile entity. */
    public function configured(): bool
    {
        $ref = $this->reference();
        return $ref['source'] !== self::SOURCE_OFF && (
            !empty($ref['hostile']['alliance'])
            || !empty($ref['hostile']['corporation'])
            || !empty($ref['hostile']['character'])
        );
    }

    /** True when SeAT's Standings Builder is installed (so the settings UI can offer it). */
    public function seatStandingsAvailable(): bool
    {
        return class_exists(\Seat\Web\Models\StandingsProfile::class);
    }

    /**
     * @return array<int,object> SeAT standings profiles [{id, name}], for the picker. Empty if unavailable.
     */
    public function seatProfiles(): array
    {
        if (!$this->seatStandingsAvailable()) {
            return [];
        }
        try {
            return \Seat\Web\Models\StandingsProfile::orderBy('name')->get(['id', 'name'])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array{source:string, precedence:string,
     *   hostile:array<string,array<int>>, friendly:array<string,array<int>>}
     */
    public function reference(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $source   = $this->source();
        $hostile  = ['alliance' => [], 'corporation' => [], 'character' => []];
        $friendly = ['alliance' => [], 'corporation' => [], 'character' => []];

        // Derived from the numeric map rather than loaded separately, so the
        // binary view can never disagree with the values it is summarising.
        // Everything below zero is hostile, everything above is friendly, and
        // an explicit 0 is neither — which is what makes a local override to
        // neutral able to cancel an inherited hostile verdict.
        foreach ($this->resolvedStandings() as $key => $info) {
            [$type, $id] = explode(':', $key, 2);
            if (!isset($hostile[$type])) {
                continue;
            }
            if ($info['standing'] < 0) {
                $hostile[$type][] = (int) $id;
            } elseif ($info['standing'] > 0) {
                $friendly[$type][] = (int) $id;
            }
        }

        return $this->cache = [
            'source'     => $source,
            'precedence' => $this->precedence(),
            'hostile'    => $hostile,
            'friendly'   => $friendly,
        ];
    }

    /**
     * Verdict for one contact entity, applying the precedence toggle.
     * Pass the corp's alliance_id when the contact is a corporation (so a corp
     * can inherit its alliance's hostility); null otherwise.
     *
     * @return string|null 'hostile' | 'friendly' | null (no opinion)
     */
    public function verdict(string $contactType, int $contactId, ?int $corpAllianceId, ?array $ref = null): ?string
    {
        $ref = $ref ?? $this->reference();
        $h = $ref['hostile'];
        $f = $ref['friendly'];

        if ($contactType === 'alliance') {
            if (in_array($contactId, $h['alliance'], true)) {
                return 'hostile';
            }
            if (in_array($contactId, $f['alliance'], true)) {
                return 'friendly';
            }
            return null;
        }

        if ($contactType === 'character') {
            if (in_array($contactId, $h['character'], true)) {
                return 'hostile';
            }
            if (in_array($contactId, $f['character'], true)) {
                return 'friendly';
            }
            return null;
        }

        if ($contactType === 'corporation') {
            $corpVerdict = in_array($contactId, $h['corporation'], true) ? 'hostile'
                : (in_array($contactId, $f['corporation'], true) ? 'friendly' : null);

            $allianceVerdict = null;
            if ($corpAllianceId) {
                $allianceVerdict = in_array($corpAllianceId, $h['alliance'], true) ? 'hostile'
                    : (in_array($corpAllianceId, $f['alliance'], true) ? 'friendly' : null);
            }

            // Friction: corp-level and alliance-level disagree -> precedence decides.
            if ($corpVerdict !== null && $allianceVerdict !== null && $corpVerdict !== $allianceVerdict) {
                return $ref['precedence'] === self::PRECEDENCE_ALLIANCE ? $allianceVerdict : $corpVerdict;
            }

            return $corpVerdict ?? $allianceVerdict;
        }

        return null;
    }


}

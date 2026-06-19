<?php

namespace HrManager\Services;

use HrManager\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the corp's "who is hostile / who is friendly" reference used by the
 * applicant assessment's standings signal. Two interchangeable sources (an
 * operator setting picks which):
 *
 *   - 'seat' : SeAT's own Standings Builder (Tools -> Standings). A profile of
 *              {entity, standing}; standing < 0 = hostile, > 0 = friendly.
 *   - 'own'  : HR-local lists of hostile / friendly alliance + corp IDs.
 *
 * Plus a precedence toggle for the natural friction where an alliance is
 * hostile but a corp inside it is friendly (or the reverse): 'corp' = the most
 * specific entry wins, 'alliance' = the alliance-level verdict wins.
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

    public const PRECEDENCE_CORP     = 'corp';     // most specific entry wins
    public const PRECEDENCE_ALLIANCE = 'alliance'; // alliance-level verdict wins

    public const SETTING_SOURCE             = 'assess_standings_source';
    public const SETTING_SEAT_PROFILE       = 'assess_standings_seat_profile';
    public const SETTING_PRECEDENCE         = 'assess_standings_precedence';
    public const SETTING_HOSTILE_ALLIANCES  = 'assess_hostile_alliances';
    public const SETTING_HOSTILE_CORPS      = 'assess_hostile_corps';
    public const SETTING_FRIENDLY_ALLIANCES = 'assess_friendly_alliances';
    public const SETTING_FRIENDLY_CORPS     = 'assess_friendly_corps';

    /** Per-request memo of the resolved reference. */
    private ?array $cache = null;

    public function source(): string
    {
        $s = (string) Setting::getValue(self::SETTING_SOURCE, self::SOURCE_OFF);
        return in_array($s, [self::SOURCE_SEAT, self::SOURCE_OWN], true) ? $s : self::SOURCE_OFF;
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

        if ($source === self::SOURCE_SEAT) {
            [$hostile, $friendly] = $this->loadFromSeatProfile();
        } elseif ($source === self::SOURCE_OWN) {
            $hostile['alliance']     = $this->ids(self::SETTING_HOSTILE_ALLIANCES);
            $hostile['corporation']  = $this->ids(self::SETTING_HOSTILE_CORPS);
            $friendly['alliance']    = $this->ids(self::SETTING_FRIENDLY_ALLIANCES);
            $friendly['corporation'] = $this->ids(self::SETTING_FRIENDLY_CORPS);
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

    /**
     * @return array{0:array<string,array<int>>,1:array<string,array<int>>} [hostile, friendly]
     */
    private function loadFromSeatProfile(): array
    {
        $hostile  = ['alliance' => [], 'corporation' => [], 'character' => []];
        $friendly = ['alliance' => [], 'corporation' => [], 'character' => []];

        if (!class_exists(\Seat\Web\Models\StandingsProfileStanding::class)) {
            return [$hostile, $friendly];
        }
        $profileId = (int) Setting::getValue(self::SETTING_SEAT_PROFILE, 0);
        if ($profileId <= 0) {
            return [$hostile, $friendly];
        }

        try {
            $rows = \Seat\Web\Models\StandingsProfileStanding::where('standings_profile_id', $profileId)->get();
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] standings profile load failed: ' . $e->getMessage());
            return [$hostile, $friendly];
        }

        foreach ($rows as $r) {
            $cat = (string) $r->category;
            if (!isset($hostile[$cat])) {
                continue; // faction / unknown — ignored for hostility matching
            }
            $val = (float) $r->standing;
            if ($val < 0) {
                $hostile[$cat][] = (int) $r->entity_id;
            } elseif ($val > 0) {
                $friendly[$cat][] = (int) $r->entity_id;
            }
        }

        return [$hostile, $friendly];
    }

    /** Read a stored ID-list setting (JSON array) into a clean int array. */
    private function ids(string $settingKey): array
    {
        $raw = Setting::getValue($settingKey, []);
        $arr = is_array($raw) ? $raw : [];
        return array_values(array_unique(array_filter(
            array_map('intval', $arr),
            fn ($v) => $v > 0
        )));
    }
}

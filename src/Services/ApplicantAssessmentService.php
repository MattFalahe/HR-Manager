<?php

namespace HrManager\Services;

use HrManager\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Character\CharacterInfo;

/**
 * Automated, recruiter-facing assessment of an APPLICANT character.
 *
 * Composes signals HR already has into a single risk/quality readout, so a
 * recruiter sees the vetting picture at a glance instead of clicking through
 * five deep-link views. It is deliberately PROGRESSIVE: the high-value signals
 * (corp history, age, security status, watchlist, zKillboard) come from PUBLIC
 * or internal data and need NO ESI scopes, so they always render; richer
 * signals (skill points, and later implants / corp roles) appear only when the
 * applicant granted the scope and SeAT has synced the data. Nothing here is a
 * hard gate (that's EligibilityService's job) — it's intel for a human.
 *
 * Two signals Matt specifically called out:
 *   - CORP-HOPPING: many corps in a short window / low average tenure. Reads
 *     as instability or, with NPC parking, a possible intel alt.
 *   - NPC-CORP PARKING: sitting in an NPC corp for a long time. Reads as
 *     inactive, or a character parked between deployments / for intel.
 *
 * Every threshold is operator-configurable in settings (DB-backed) with the
 * sensible defaults below, so a corp can tune "how many corps is too many" to
 * its own culture.
 */
class ApplicantAssessmentService
{
    /**
     * Setting key => default. Operators override these in HR settings; the
     * service always has a working default so the assessment runs out of the
     * box on a fresh install.
     */
    public const CRITERIA_DEFAULTS = [
        'assess_min_age_days'        => 30,        // younger than this -> "very young character"
        'assess_hopper_corps_12mo'   => 5,         // more corps than this in 12 months -> hopper
        'assess_min_avg_tenure_days' => 45,        // average corp tenure below this -> churn
        'assess_npc_park_days'       => 90,        // currently in an NPC corp longer than this -> parked
        'assess_min_sp'              => 5_000_000, // below this (when skills granted) -> low SP note
        'assess_sec_floor'           => -2.0,      // security status below this -> flagged for context
    ];

    /**
     * EVE NPC corporation IDs live below 2,000,000 (starter corps, faction
     * navies, pirate factions, etc.). Player corps are 2,000,000+ (legacy) and
     * 98,000,000+ (modern). A heuristic, but reliable enough for an advisory
     * signal — and the very first corp in everyone's history is their NPC
     * starter corp, which we account for by looking at CURRENT parking + the
     * recent window, not raw lifetime NPC time.
     */
    private const NPC_CORP_CEILING = 2_000_000;

    /**
     * Corp roles that carry elevated trust/access (the ones worth a recruiter's
     * attention on an applicant). Values match CCP's ESI role strings exactly,
     * as SeAT stores them in character_roles.role. Director is the headline
     * (full access — assets, wallet, members), so it's flagged louder.
     */
    private const ELEVATED_ROLES = [
        'Director', 'Accountant', 'Junior_Accountant', 'Personnel_Manager',
        'Security_Officer', 'Station_Manager', 'Diplomat', 'Contract_Manager',
        'Factory_Manager', 'Auditor',
    ];

    public function __construct(
        private readonly ZkillService $zkill,
        private readonly WatchlistService $watchlist,
        private readonly StandingsReferenceService $standings,
    ) {
    }

    /**
     * Build the assessment for one applicant character.
     *
     * @return array{
     *   available: bool,
     *   verdict: string,            // 'green' | 'amber' | 'red'
     *   flags: array<int,array{severity:string,label:string,detail:string}>,
     *   signals: array<string,mixed>,
     *   reason?: string,
     * }
     */
    public function assess(int $characterId, ?array $allowedCorpIds = null): array
    {
        $char = CharacterInfo::find($characterId);
        if (!$char) {
            return [
                'available' => false,
                'verdict'   => 'amber',
                'flags'     => [],
                'signals'   => [],
                'reason'    => 'Character not synced in SeAT yet — apply data hydration first.',
            ];
        }

        $flags   = [];
        $signals = [];

        // Scopes the applicant actually granted (drives the progressive
        // signals below — we distinguish "scope not granted" from "granted
        // but the data says X").
        $scopes = $this->grantedScopes($characterId);

        // --- Always-on signals (public / internal, no ESI scope needed) ---
        $signals['age']          = $this->ageSignal($char, $flags);
        $signals['security']     = $this->securitySignal($char, $flags);
        $signals['corp_history'] = $this->corpHistorySignal($char, $flags);
        $signals['watchlist']    = $this->watchlistSignal($characterId, $allowedCorpIds, $char, $flags);
        $signals['pvp']          = $this->pvpSignal($characterId);
        $signals['characters']   = $this->applicantCharactersSignal($characterId);

        // --- Progressive signals: present only when the applicant granted the
        //     scope AND SeAT has synced the data. Absent renders as "scope not
        //     granted" rather than a false negative. ---
        $signals['skill_points'] = $this->skillPointsSignal($char, $scopes, $flags);
        $signals['implants']     = $this->implantsSignal($characterId, $scopes);
        $signals['corp_roles']   = $this->corpRolesSignal($characterId, $scopes, $flags);
        $signals['standings']    = $this->standingsSignal($characterId, $scopes, $flags);

        return [
            'available' => true,
            'verdict'   => $this->verdictFrom($flags),
            'flags'     => $flags,
            'signals'   => $signals,
        ];
    }

    /**
     * Queue an ESI re-sync of the data this assessment reads, so a recruiter can
     * pull fresh numbers when a signal still shows "not synced yet" (the apply
     * hydration had not finished, or scopes were granted after). Public jobs go
     * by character id; auth jobs need the RefreshToken and are only queued when
     * the matching scope is present (no point queueing a guaranteed ESI 403).
     * Jobs land on SeAT's normal queue; the next page load reads the result.
     */
    public function refresh(int $characterId): void
    {
        try {
            \Seat\Eveapi\Jobs\Character\Info::dispatch($characterId);
            \Seat\Eveapi\Jobs\Character\CorporationHistory::dispatch($characterId);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] assessment refresh (public jobs) failed: ' . $e->getMessage());
        }

        try {
            $token = \Seat\Eveapi\Models\RefreshToken::find($characterId);
            if (!$token) {
                return;
            }
            $scopes = array_map('strval', (array) $token->scopes);

            \Seat\Eveapi\Jobs\Skills\Character\Skills::dispatch($token);

            if (in_array('esi-clones.read_implants.v1', $scopes, true)) {
                \Seat\Eveapi\Jobs\Clones\Implants::dispatch($token);
            }
            if (in_array('esi-characters.read_corporation_roles.v1', $scopes, true)) {
                \Seat\Eveapi\Jobs\Character\Roles::dispatch($token);
            }
            if (in_array('esi-characters.read_contacts.v1', $scopes, true)) {
                \Seat\Eveapi\Jobs\Contacts\Character\Contacts::dispatch($token);
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] assessment refresh (auth jobs) failed: ' . $e->getMessage());
        }
    }

    // =================================================================
    // Signals
    // =================================================================

    private function ageSignal(CharacterInfo $char, array &$flags): array
    {
        $birthday = $char->birthday ? Carbon::parse($char->birthday) : null;
        $ageDays  = $birthday ? $birthday->diffInDays(now()) : null;
        $minAge   = (int) $this->criterion('assess_min_age_days');

        if ($ageDays !== null && $ageDays < $minAge) {
            $flags[] = [
                'severity' => 'warn',
                'label'    => 'Very young character',
                'detail'   => 'Created ' . $ageDays . ' days ago (under the ' . $minAge . '-day guideline). Possible throwaway alt or fresh account.',
            ];
        }

        return [
            'available'   => $birthday !== null,
            'birthday'    => $birthday?->toDateString(),
            'age_days'    => $ageDays,
            'age_human'   => $birthday?->diffForHumans(now(), true),
            'below_guide' => $ageDays !== null && $ageDays < $minAge,
        ];
    }

    private function securitySignal(CharacterInfo $char, array &$flags): array
    {
        $sec   = $char->security_status;
        $floor = (float) $this->criterion('assess_sec_floor');

        if ($sec !== null && (float) $sec < $floor) {
            $flags[] = [
                'severity' => 'info',
                'label'    => 'Low security status',
                'detail'   => 'Security status ' . number_format((float) $sec, 2) . ' (below ' . number_format($floor, 1) . '). Normal for PvP/lowsec pilots — context, not a red flag.',
            ];
        }

        return [
            'available' => $sec !== null,
            'value'     => $sec !== null ? round((float) $sec, 2) : null,
        ];
    }

    /**
     * Corp-history intelligence: hopper detection, churn (low average tenure)
     * and NPC-corp parking. All from publicData (corporation_history is public).
     */
    private function corpHistorySignal(CharacterInfo $char, array &$flags): array
    {
        $records = collect();
        try {
            $records = $char->corporation_history()->orderBy('start_date')->get();
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] ApplicantAssessment corp_history failed: ' . $e->getMessage());
        }

        $count = $records->count();
        if ($count === 0) {
            return ['available' => false];
        }

        $now      = now();
        $tenures  = [];
        $npcDays  = 0;
        $values   = $records->values();
        foreach ($values as $i => $r) {
            $start = Carbon::parse($r->start_date);
            $end   = isset($values[$i + 1]) ? Carbon::parse($values[$i + 1]->start_date) : $now;
            $days  = max(0, $start->diffInDays($end));
            $tenures[] = $days;
            if ($this->isNpcCorp((int) $r->corporation_id)) {
                $npcDays += $days;
            }
        }

        $avgTenure   = $count > 0 ? (int) round(array_sum($tenures) / $count) : 0;
        $corpsLast12 = $values->filter(fn ($r) => Carbon::parse($r->start_date)->gte($now->copy()->subMonths(12)))->count();
        $current     = $values->last();
        // Freshest current corp: character_affiliations is synced far more often
        // than the corp-history endpoint (long ESI cache), so a recent move into a
        // player corp is not still shown as a stale NPC corp + a false "parked"
        // flag. Falls back to character_infos, then the history last record.
        $liveCorpId     = $this->liveCorporationId($char, $current);
        $currentIsNpc   = $liveCorpId > 0 ? $this->isNpcCorp($liveCorpId) : false;
        $currentTenure  = $current ? max(0, Carbon::parse($current->start_date)->diffInDays($now)) : 0;
        // Tenure only counts toward "parked" when the live corp still matches the
        // history's last record; if it has moved on more recently than the history
        // reflects, we have no reliable tenure for the live corp, so do not flag.
        if ($current && $liveCorpId > 0 && $liveCorpId !== (int) $current->corporation_id) {
            $currentTenure = 0;
        }

        $hopperLimit = (int) $this->criterion('assess_hopper_corps_12mo');
        $tenureFloor = (int) $this->criterion('assess_min_avg_tenure_days');
        $npcParkDays = (int) $this->criterion('assess_npc_park_days');

        $isHopper = $corpsLast12 > $hopperLimit;
        $isChurn  = $count >= 3 && $avgTenure < $tenureFloor;
        $isParked = $currentIsNpc && $currentTenure > $npcParkDays;

        if ($isHopper) {
            $flags[] = [
                'severity' => 'warn',
                'label'    => 'Frequent corp switching',
                'detail'   => $corpsLast12 . ' corporations in the last 12 months (over the ' . $hopperLimit . ' guideline). Instability, or a possible intel alt.',
            ];
        }
        if ($isChurn) {
            $flags[] = [
                'severity' => 'warn',
                'label'    => 'Low average tenure',
                'detail'   => 'Averages ' . $avgTenure . ' days per corp across ' . $count . ' corps (under the ' . $tenureFloor . '-day guideline).',
            ];
        }
        if ($isParked) {
            $flags[] = [
                'severity' => 'warn',
                'label'    => 'Parked in an NPC corp',
                'detail'   => 'Currently in an NPC corp for ~' . $currentTenure . ' days (over the ' . $npcParkDays . '-day guideline). Reads as inactive, or parked for intel.',
            ];
        }

        // Current corp = the freshest live corp resolved above, with a resolved
        // name + the NPC flag, so the recruiter sees where the applicant sits
        // right now rather than inferring it from the (laggier) history rows.
        $currentCorpId = $liveCorpId;
        $currentCorpName = $currentCorpId > 0 ? $this->corporationName($currentCorpId) : null;

        return [
            'available'           => true,
            'corp_count'          => $count,
            'avg_tenure_days'     => $avgTenure,
            'corps_last_12mo'     => $corpsLast12,
            'npc_days_total'      => $npcDays,
            'current_corp_id'     => $currentCorpId,
            'current_corp_name'   => $currentCorpName,
            'current_is_npc'      => $currentIsNpc,
            'current_tenure_days' => $currentTenure,
            'is_hopper'           => $isHopper,
            'is_churn'            => $isChurn,
            'is_npc_parked'       => $isParked,
        ];
    }

    /**
     * Cross-check the applicant against HR's own watchlist / blacklist. A hit
     * is the single strongest red flag we have.
     */
    private function watchlistSignal(int $characterId, ?array $allowedCorpIds, CharacterInfo $char, array &$flags): array
    {
        try {
            $match = $this->watchlist->findMatch($characterId, $allowedCorpIds, (int) ($char->corporation_id ?? 0));
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] ApplicantAssessment watchlist check failed: ' . $e->getMessage());
            return ['available' => false];
        }

        if ($match) {
            $flags[] = [
                'severity' => 'danger',
                'label'    => 'On the watchlist',
                'detail'   => 'This character matches a watchlist entry' . (!empty($match->reason) ? ': ' . $match->reason : '.'),
            ];
        }

        return [
            'available' => true,
            'hit'       => (bool) $match,
            'reason'    => $match->reason ?? null,
        ];
    }

    /** zKillboard PvP summary (external, cached). Informational, never a flag by itself. */
    private function pvpSignal(int $characterId): array
    {
        try {
            return $this->zkill->getCharacterStats($characterId);
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'fetch_failed'];
        }
    }

    /**
     * The applicant as a PERSON: every character on the same SeAT account with
     * its current corp + an NPC/player flag. Makes a multi-character applicant
     * legible — e.g. a main in a player corp with an alt parked in an NPC corp
     * reads clearly, instead of the single applying-character corp being mistaken
     * for the whole picture. Only surfaces when the account has more than one
     * character (a single character is already covered by the Current corp field).
     */
    private function applicantCharactersSignal(int $characterId): array
    {
        try {
            $userId = \Illuminate\Support\Facades\DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->whereNull('deleted_at')
                ->value('user_id');
            if ($userId === null) {
                return ['available' => false];
            }

            $charIds = \Illuminate\Support\Facades\DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->pluck('character_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();

            if (count($charIds) <= 1) {
                return ['available' => false]; // single character — Current corp already says it
            }

            $infos = CharacterInfo::whereIn('character_id', $charIds)
                ->get(['character_id', 'name', 'corporation_id'])
                ->keyBy(fn ($r) => (int) $r->character_id);

            // Prefer character_affiliations (fresher) over character_infos for each
            // character's current corp, same as the headline current-corp signal.
            $affCorps = [];
            try {
                $affCorps = \Seat\Eveapi\Models\Character\CharacterAffiliation::whereIn('character_id', $charIds)
                    ->pluck('corporation_id', 'character_id')->map(fn ($c) => (int) $c)->toArray();
            } catch (\Throwable $e) {
                // affiliations unavailable -> character_infos only
            }

            $corpOf = [];
            foreach ($charIds as $cid) {
                $corpOf[$cid] = (int) ($affCorps[$cid] ?? (optional($infos->get($cid))->corporation_id ?? 0));
            }

            $corpIds   = array_values(array_unique(array_filter($corpOf)));
            $corpNames = empty($corpIds) ? [] : \Seat\Eveapi\Models\Corporation\CorporationInfo::whereIn('corporation_id', $corpIds)
                ->pluck('name', 'corporation_id')->toArray();

            $npc = 0;
            $player = 0;
            $characters = [];
            foreach ($charIds as $cid) {
                $corpId = $corpOf[$cid];
                $isNpc  = $this->isNpcCorp($corpId);
                if ($isNpc) {
                    $npc++;
                } else {
                    $player++;
                }

                $characters[] = [
                    'character_id' => $cid,
                    'name'         => (optional($infos->get($cid))->name ?: ('Character #' . $cid)),
                    'corp_id'      => $corpId,
                    'corp_name'    => $corpNames[$corpId] ?? ($corpId > 0 ? ('Corp #' . $corpId) : 'Unknown'),
                    'is_npc'       => $isNpc,
                    'is_applicant' => $cid === $characterId,
                ];
            }
            usort($characters, fn ($a, $b) => ($b['is_applicant'] <=> $a['is_applicant']));

            return [
                'available'    => true,
                'characters'   => $characters,
                'npc_count'    => $npc,
                'player_count' => $player,
            ];
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] applicantCharactersSignal failed: ' . $e->getMessage());
            return ['available' => false];
        }
    }

    /**
     * Skill points (progressive: needs esi-skills.read_skills.v1). SeAT stores
     * total SP on character_info_skills, reached via the skillpoints relation —
     * NOT a column on character_infos. We distinguish three states: scope not
     * granted ('scope'), scope granted but SeAT has not synced the skill sheet
     * yet ('pending', refreshable), or available with a value.
     */
    private function skillPointsSignal(CharacterInfo $char, array $scopes, array &$flags): array
    {
        if (!in_array('esi-skills.read_skills.v1', $scopes, true)) {
            return ['available' => false, 'reason' => 'scope'];
        }

        $sp = $char->skillpoints?->total_sp;
        if ($sp === null || (int) $sp <= 0) {
            // Scope is granted but the skills sheet is not synced yet.
            return ['available' => false, 'reason' => 'pending'];
        }

        $minSp = (int) $this->criterion('assess_min_sp');
        $below = (int) $sp < $minSp;
        if ($below) {
            $flags[] = [
                'severity' => 'info',
                'label'    => 'Below SP guideline',
                'detail'   => number_format((int) $sp) . ' SP (under the ' . number_format($minSp) . ' guideline).',
            ];
        }

        return [
            'available'   => true,
            'total_sp'    => (int) $sp,
            'below_guide' => $below,
        ];
    }

    /**
     * Implants fitted (progressive: needs esi-clones.read_implants.v1). The
     * count is an investment signal — an established main tends to fly with
     * implants; a throwaway alt usually has a clean clone. Informational, not
     * a flag either way (clean clones are also normal in dangerous space).
     */
    private function implantsSignal(int $characterId, array $scopes): array
    {
        if (!in_array('esi-clones.read_implants.v1', $scopes, true)) {
            return ['available' => false, 'reason' => 'scope'];
        }
        try {
            $count = \Seat\Eveapi\Models\Clones\CharacterImplant::where('character_id', $characterId)->count();
        } catch (\Throwable $e) {
            return ['available' => false];
        }
        return ['available' => true, 'count' => $count];
    }

    /**
     * Current-corp roles (progressive: needs esi-characters.read_corporation_roles.v1).
     * Director is flagged as a warn (full access to assets/wallet/members, an
     * awox/intel consideration and a seniority signal); other elevated roles
     * surface as info. Non-elevated roles (hangar/query/etc.) are counted but
     * not flagged.
     */
    private function corpRolesSignal(int $characterId, array $scopes, array &$flags): array
    {
        if (!in_array('esi-characters.read_corporation_roles.v1', $scopes, true)) {
            return ['available' => false, 'reason' => 'scope'];
        }
        try {
            $roles = \Seat\Eveapi\Models\Character\CharacterRole::where('character_id', $characterId)
                ->pluck('role')->map(fn ($r) => (string) $r)->unique()->values()->all();
        } catch (\Throwable $e) {
            return ['available' => false];
        }

        $elevated   = array_values(array_intersect($roles, self::ELEVATED_ROLES));
        $isDirector = in_array('Director', $roles, true);

        if ($isDirector) {
            $flags[] = [
                'severity' => 'warn',
                'label'    => 'Holds Director',
                'detail'   => 'Director in their current corp: full access (assets, wallet, members). Worth a look for awox / intel risk, and a seniority signal.',
            ];
        } elseif (!empty($elevated)) {
            $flags[] = [
                'severity' => 'info',
                'label'    => 'Elevated corp roles',
                'detail'   => 'Holds ' . implode(', ', array_map([$this, 'humanRole'], $elevated)) . ' in their current corp.',
            ];
        }

        return [
            'available'   => true,
            'roles'       => $roles,
            'elevated'    => array_map([$this, 'humanRole'], $elevated),
            'is_director' => $isDirector,
            'count'       => count($roles),
        ];
    }

    /**
     * Standings cross-check (progressive: needs esi-characters.read_contacts.v1).
     * Flags when the applicant holds POSITIVE personal standing toward an entity
     * the corp's standings reference marks hostile (a spy / opsec signal, or
     * stale diplomacy). Inert until an operator configures a standings source.
     */
    private function standingsSignal(int $characterId, array $scopes, array &$flags): array
    {
        if (!in_array('esi-characters.read_contacts.v1', $scopes, true)) {
            return ['available' => false, 'reason' => 'scope'];
        }
        if (!$this->standings->configured()) {
            return ['available' => false, 'reason' => 'not_configured'];
        }

        try {
            $contacts = \Seat\Eveapi\Models\Contacts\CharacterContact::where('character_id', $characterId)
                ->where('standing', '>', 0)
                ->get(['contact_id', 'contact_type', 'standing']);
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'scope'];
        }

        $ref = $this->standings->reference();

        // Batch-resolve the alliance of every corp contact so a corp can inherit
        // its alliance's hostility (and the precedence toggle can apply).
        $corpIds = $contacts->where('contact_type', 'corporation')
            ->pluck('contact_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        $corpAlliances = [];
        if (!empty($corpIds)) {
            try {
                $corpAlliances = \Seat\Eveapi\Models\Corporation\CorporationInfo::whereIn('corporation_id', $corpIds)
                    ->pluck('alliance_id', 'corporation_id')
                    ->map(fn ($a) => (int) $a)->toArray();
            } catch (\Throwable $e) {
                // No alliance resolution -> corp contacts match on corp-level only.
            }
        }

        $matches = [];
        foreach ($contacts as $c) {
            $type = (string) $c->contact_type;
            $id   = (int) $c->contact_id;
            $allianceId = $type === 'corporation' ? ($corpAlliances[$id] ?? null) : null;
            if ($this->standings->verdict($type, $id, $allianceId, $ref) === 'hostile') {
                $matches[] = ['id' => $id, 'type' => $type, 'standing' => (float) $c->standing];
            }
        }

        if (!empty($matches)) {
            $names = $this->resolveEntityNames(array_column($matches, 'id'));
            $list  = implode(', ', array_map(
                fn ($m) => ($names[$m['id']] ?? ('#' . $m['id'])),
                array_slice($matches, 0, 6)
            ));
            $flags[] = [
                'severity' => 'warn',
                'label'    => 'Blue to a hostile entity',
                'detail'   => 'Positive personal standing toward ' . count($matches)
                    . ' entity(ies) your standings reference marks hostile: ' . $list
                    . '. Possible spy / opsec risk, or stale diplomacy worth a question.',
            ];
        }

        return [
            'available'     => true,
            'configured'    => true,
            'hostile_count' => count($matches),
        ];
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** Resolve alliance / corp / character ids to names via SeAT's universe_names. */
    private function resolveEntityNames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        try {
            return \Illuminate\Support\Facades\DB::table('universe_names')
                ->whereIn('entity_id', $ids)
                ->pluck('name', 'entity_id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Read the scopes the applicant granted, from their SeAT refresh token. */
    private function grantedScopes(int $characterId): array
    {
        try {
            $token = \Seat\Eveapi\Models\RefreshToken::where('character_id', $characterId)->first();
            return $token ? array_map('strval', (array) $token->scopes) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** "Personnel_Manager" -> "Personnel Manager". */
    private function humanRole(string $role): string
    {
        return str_replace('_', ' ', $role);
    }

    private function isNpcCorp(int $corporationId): bool
    {
        return $corporationId > 0 && $corporationId < self::NPC_CORP_CEILING;
    }

    /**
     * Freshest current corporation for a character: character_affiliations
     * (synced often via the cheap bulk endpoint) over character_infos, over the
     * corp-history last record. Keeps the "current corp" + NPC flag from lagging
     * behind a recent corp change (e.g. an applicant who just joined a corp).
     */
    private function liveCorporationId(CharacterInfo $char, $historyCurrent): int
    {
        try {
            $affId = \Seat\Eveapi\Models\Character\CharacterAffiliation::where('character_id', $char->character_id)
                ->value('corporation_id');
            if ($affId) {
                return (int) $affId;
            }
        } catch (\Throwable $e) {
            // fall through to character_infos / history
        }

        return (int) ($char->corporation_id ?: ($historyCurrent->corporation_id ?? 0));
    }

    /** Resolve a corporation id to its name (corporation_infos -> universe_names), or null. */
    private function corporationName(int $corporationId): ?string
    {
        if ($corporationId <= 0) {
            return null;
        }
        try {
            return \Seat\Eveapi\Models\Corporation\CorporationInfo::where('corporation_id', $corporationId)->value('name')
                ?? \Illuminate\Support\Facades\DB::table('universe_names')
                    ->where('entity_id', $corporationId)->where('category', 'corporation')->value('name');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Resolve a configurable criterion, falling back to the shipped default. */
    private function criterion(string $key)
    {
        $default = self::CRITERIA_DEFAULTS[$key] ?? null;
        try {
            $value = Setting::getValue($key, $default);
            return ($value === null || $value === '') ? $default : $value;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** Worst severity present wins: danger -> red, warn -> amber, else green. */
    private function verdictFrom(array $flags): string
    {
        $severities = array_column($flags, 'severity');
        if (in_array('danger', $severities, true)) {
            return 'red';
        }
        if (in_array('warn', $severities, true)) {
            return 'amber';
        }
        return 'green';
    }
}

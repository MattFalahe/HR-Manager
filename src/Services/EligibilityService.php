<?php

namespace HrManager\Services;

use HrManager\Models\RecruitmentLanding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;

/**
 * Evaluates eligibility rules from a RecruitmentLanding against a
 * candidate character. Each rule produces a pass/fail + human-readable
 * reason; the candidate sees which rules failed (and can still submit
 * via the "request review anyway" escape hatch).
 *
 * Rule shape (stored on landing.eligibility_rules_json):
 *   {
 *     "min_security_status": -2.0,
 *     "min_total_sp": 5000000,
 *     "min_character_age_days": 30,
 *     "blacklist_corps": [98000123],
 *     "whitelist_alliances": [99000456],
 *     "require_seat_connector": true
 *   }
 */
class EligibilityService
{
    /**
     * Evaluate every rule on the landing against the candidate character.
     *
     * @return array{passed: bool, failures: array<int, array{rule: string, reason: string}>}
     */
    public function evaluate(RecruitmentLanding $landing, int $characterId, int $userId): array
    {
        $rules = $landing->eligibility_rules_json ?: [];
        if (empty($rules)) {
            return ['passed' => true, 'failures' => []];
        }

        $character = CharacterInfo::find($characterId);
        $affiliation = CharacterAffiliation::find($characterId);

        $failures = [];

        if (isset($rules['min_security_status'])) {
            $sec = $character->security_status ?? null;
            if ($sec === null || $sec < (float) $rules['min_security_status']) {
                $failures[] = [
                    'rule'         => 'min_security_status',
                    'reason'       => $sec === null
                        ? sprintf('Security status could not be loaded in time. Minimum required: %.2f.', (float) $rules['min_security_status'])
                        : sprintf('Minimum security status %.2f required (you have %s).',
                            (float) $rules['min_security_status'],
                            number_format($sec, 2)),
                    'data_missing' => $sec === null,
                ];
            }
        }

        if (isset($rules['min_total_sp'])) {
            // total_sp lives on character_info_skills (CharacterInfoSkill
            // model), reached via CharacterInfo->skillpoints. The Skills
            // job populates it; CharacterInfo itself has no total_sp
            // column.
            $sp = $character?->skillpoints?->total_sp ?? null;
            if ($sp === null || $sp < (int) $rules['min_total_sp']) {
                $failures[] = [
                    'rule'         => 'min_total_sp',
                    'reason'       => $sp === null
                        ? sprintf('Skill points could not be loaded in time. Minimum required: %s SP.', number_format((int) $rules['min_total_sp']))
                        : sprintf('Minimum %s SP required (you have %s).',
                            number_format((int) $rules['min_total_sp']),
                            number_format($sp)),
                    'data_missing' => $sp === null,
                ];
            }
        }

        if (isset($rules['min_character_age_days'])) {
            $birthday = $character->birthday ?? null;
            $age = $birthday ? \Carbon\Carbon::parse($birthday)->diffInDays(now()) : null;
            if ($age === null || $age < (int) $rules['min_character_age_days']) {
                $failures[] = [
                    'rule'         => 'min_character_age_days',
                    'reason'       => $age === null
                        ? sprintf('Character age could not be loaded in time. Minimum required: %d days.', (int) $rules['min_character_age_days'])
                        : sprintf('Character must be at least %d days old (yours is %s).',
                            (int) $rules['min_character_age_days'],
                            $age . ' days'),
                    'data_missing' => $age === null,
                ];
            }
        }

        if (!empty($rules['blacklist_corps'])) {
            $blacklist = array_map('intval', $rules['blacklist_corps']);
            $pastCorps = $this->pastCorpIds($characterId);
            $historyKnown = !empty($pastCorps);
            $overlap = array_intersect($blacklist, $pastCorps);
            if (!empty($overlap)) {
                $failures[] = [
                    'rule'         => 'blacklist_corps',
                    'reason'       => 'Your character has a history with one or more corporations we do not accept applicants from.',
                    'data_missing' => false,
                ];
            } elseif (!$historyKnown) {
                // Can't tell either way — flag as data-missing so the
                // manual-review path treats it as a soft failure.
                $failures[] = [
                    'rule'         => 'blacklist_corps',
                    'reason'       => 'Corp history could not be loaded in time. Rule cannot be evaluated.',
                    'data_missing' => true,
                ];
            }
        }

        if (!empty($rules['whitelist_alliances'])) {
            $whitelist = array_map('intval', $rules['whitelist_alliances']);
            $allianceId = $affiliation->alliance_id ?? null;
            if (!$allianceId || !in_array((int) $allianceId, $whitelist, true)) {
                $failures[] = [
                    'rule'         => 'whitelist_alliances',
                    'reason'       => $allianceId === null
                        ? 'Alliance affiliation could not be loaded in time. Cannot confirm alliance match.'
                        : 'Your character must currently be in an allied alliance to apply.',
                    'data_missing' => $allianceId === null,
                ];
            }
        }

        if (!empty($rules['require_seat_connector'])) {
            $connectorAvailable = app(SeatConnectorService::class)->isAvailable();
            if (!$connectorAvailable) {
                // The rule was set on a landing that previously had
                // warlof/seat-connector installed; the operator has
                // since uninstalled it. Don't outright reject the
                // applicant — they have no UI to satisfy a rule the
                // host system can't even evaluate. Route to manual
                // review instead.
                $failures[] = [
                    'rule'         => 'require_seat_connector',
                    'reason'       => 'SeAT Connector is required by this landing but is not installed on this SeAT instance. A recruiter will review manually.',
                    'data_missing' => true,
                ];
            } elseif (!$this->seatConnectorLinked($userId)) {
                $failures[] = [
                    'rule'         => 'require_seat_connector',
                    'reason'       => 'You must link a Discord identity via the SeAT Connector before applying.',
                    'data_missing' => false,
                ];
            }
        }

        return [
            'passed'   => empty($failures),
            'failures' => $failures,
        ];
    }

    /**
     * Check whether SeAT has loaded all the character data the rules
     * on this landing actually need. Just-after-SSO applicants hit a
     * race where character_infos rows exist (created on first login)
     * but the security_status / birthday columns on character_infos
     * (and total_sp on the related character_info_skills row) are
     * still NULL because the Info/Skills jobs haven't run yet. The same
     * applies to character_corporation_histories (CorporationHistory
     * job) and character_affiliations (login observer, usually
     * immediate but not guaranteed).
     *
     * Returns the set of MISSING signals so the apply controller can
     * decide whether to show the form or the hydrating screen, and
     * the screen itself can show which step is still pending.
     *
     * @return array{ready: bool, missing: array<int,string>}
     */
    public function dataReady(RecruitmentLanding $landing, int $characterId): array
    {
        $rules = $landing->eligibility_rules_json ?: [];
        if (empty($rules)) {
            return ['ready' => true, 'missing' => []];
        }

        $character = CharacterInfo::find($characterId);
        $missing = [];

        if (isset($rules['min_security_status'])) {
            if (!$character || $character->security_status === null) {
                $missing[] = 'security_status';
            }
        }

        if (isset($rules['min_total_sp'])) {
            // total_sp lives on the related CharacterInfoSkill row
            // (character_info_skills table), not on character_infos
            // itself. Skills job populates it.
            if (!$character || $character->skillpoints?->total_sp === null) {
                $missing[] = 'skill_points';
            }
        }

        if (isset($rules['min_character_age_days'])) {
            if (!$character || $character->birthday === null) {
                $missing[] = 'character_age';
            }
        }

        if (!empty($rules['blacklist_corps'])) {
            try {
                $historyCount = DB::table('character_corporation_histories')
                    ->where('character_id', $characterId)
                    ->count();
                if ($historyCount === 0) {
                    $missing[] = 'corporation_history';
                }
            } catch (\Throwable $e) {
                $missing[] = 'corporation_history';
            }
        }

        if (!empty($rules['whitelist_alliances'])) {
            $aff = CharacterAffiliation::find($characterId);
            if (!$aff) {
                $missing[] = 'affiliation';
            }
        }

        return [
            'ready'   => empty($missing),
            'missing' => array_values(array_unique($missing)),
        ];
    }

    /**
     * Force-dispatch SeAT's character data fetch jobs to hydrate the
     * tables eligibility relies on. Pushed to the same 'characters'
     * queue SeAT's own scheduler uses, so the worker picks them up
     * with normal priority — no need to special-case queue names.
     *
     * Idempotent in the sense that running it twice just queues two
     * jobs; SeAT's CallsEsi middleware dedups overlapping ESI calls.
     * Caller should still gate via session flag so we don't enqueue
     * on every poll tick.
     */
    public function triggerHydration(int $characterId): void
    {
        try {
            // Character info (security_status, birthday, etc.)
            \Seat\Eveapi\Jobs\Character\Info::dispatch($characterId);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] hydration: Info dispatch failed: ' . $e->getMessage());
        }

        try {
            // Corp history for blacklist_corps rule
            \Seat\Eveapi\Jobs\Character\CorporationHistory::dispatch($characterId);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] hydration: CorporationHistory dispatch failed: ' . $e->getMessage());
        }

        try {
            // Skills (writes character_infos.total_sp). Needs the
            // RefreshToken — silently skip if not present (means SSO
            // hasn't fully landed yet; the Info dispatch above will
            // create the row and a later poll tick will rehydrate).
            $token = RefreshToken::find($characterId);
            if ($token) {
                \Seat\Eveapi\Jobs\Skills\Character\Skills::dispatch($token);
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] hydration: Skills dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Available rule keys + human-readable labels for the admin rules editor.
     *
     * `require_seat_connector` is conditionally included: if the
     * warlof/seat-connector framework isn't installed, the rule is
     * hidden from the landing form and rejected from validation. No
     * point letting an operator gate applications on a perm that has
     * no UI for the applicant to satisfy.
     */
    public static function availableRules(): array
    {
        $rules = [
            'min_security_status'    => ['label' => 'Minimum security status', 'type' => 'float'],
            'min_total_sp'           => ['label' => 'Minimum total SP',         'type' => 'int'],
            'min_character_age_days' => ['label' => 'Minimum character age (days)', 'type' => 'int'],
            'blacklist_corps'        => ['label' => 'Blacklisted past corps (comma-separated IDs)', 'type' => 'int_list'],
            'whitelist_alliances'    => ['label' => 'Whitelisted current alliance IDs',            'type' => 'int_list'],
        ];

        if (app(SeatConnectorService::class)->isAvailable()) {
            $rules['require_seat_connector'] = [
                'label' => 'Require linked SeAT Connector identity',
                'type'  => 'bool',
            ];
        }

        return $rules;
    }

    private function pastCorpIds(int $characterId): array
    {
        try {
            return DB::table('character_corporation_histories')
                ->where('character_id', $characterId)
                ->pluck('corporation_id')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function seatConnectorLinked(int $userId): bool
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('seat_connector_users')) {
                return false;
            }
            return DB::table('seat_connector_users')
                ->where('user_id', $userId)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

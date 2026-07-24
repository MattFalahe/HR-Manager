<?php

namespace HrManager\Services;

use HrManager\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Recruitment SSO scope-profile selection + sufficiency analysis.
 *
 * SeAT stores SSO scope profiles in the global `sso_scopes` setting as a
 * collection of objects:  { id, name, scopes[], default }.  When a brand
 * new applicant logs in through the recruitment funnel, the scopes they
 * grant are decided by whichever profile drives that login. This service
 * lets the operator pick which profile the funnel uses, and reports
 * whether the chosen (or default) profile carries the scopes HR needs to
 * actually assess an applicant.
 *
 * Two important SeAT facts shape what this can and can't do:
 *   - The FIRST login of a new applicant is what matters — that's when
 *     the character's tokens + scopes are minted. We can steer that login
 *     through `/eve/profile/{name}` (seatcore::auth.eve.profile).
 *   - For an ALREADY-authenticated user adding a character (the link-alt
 *     flow), SeAT reuses the main character's existing scopes and ignores
 *     the profile (SsoController::redirectToProvider). So profile choice
 *     only changes scopes on the initial application login.
 *
 * Nothing here calls ESI or mutates SeAT data; it reads the global
 * setting and HR's own stored choice.
 */
class RecruitmentSsoService
{
    /** HR setting key — the chosen profile NAME (empty = SeAT default). */
    public const SETTING_PROFILE = 'recruitment_sso_profile';

    /**
     * Scopes HR cares about, grouped by tier.
     *   - 'required'    : without it the funnel can't function at all.
     *   - 'recommended' : each unlocks a core assessment feature; missing
     *                     ones degrade the picture but don't break apply.
     *   - 'optional'    : bonus intel signals. Absence is NEVER a problem
     *                     (doesn't count against a "full" profile); when the
     *                     scope IS granted, the applicant assessment shows the
     *                     extra signal. This is the progressive-enhancement
     *                     tier: minimum scopes always assess + display, richer
     *                     scopes deepen the picture if present.
     */
    public const SCOPE_CATALOG = [
        'publicData' => [
            'tier'    => 'required',
            'feature' => 'Character public info, corporation history, character age',
        ],
        'esi-skills.read_skills.v1' => [
            'tier'    => 'recommended',
            'feature' => 'Skill-point eligibility rules + the recruiter Skills view',
        ],
        'esi-wallet.read_character_wallet.v1' => [
            'tier'    => 'recommended',
            'feature' => 'Wallet journal / transactions review + wallet activity signals',
        ],
        'esi-assets.read_assets.v1' => [
            'tier'    => 'recommended',
            'feature' => 'Assets review',
        ],
        'esi-mail.read_mail.v1' => [
            'tier'    => 'recommended',
            'feature' => 'Mail review',
        ],
        // Optional intel scopes — progressive enhancers for the applicant
        // assessment. None are needed to apply or to run the core review.
        'esi-clones.read_implants.v1' => [
            'tier'    => 'optional',
            'feature' => 'Active implants (investment signal: an established main vs a throwaway alt)',
        ],
        'esi-clones.read_clones.v1' => [
            'tier'    => 'optional',
            'feature' => 'Jump clones and clone network (where the applicant is based)',
        ],
        'esi-characters.read_corporation_roles.v1' => [
            'tier'    => 'optional',
            'feature' => 'Corp roles (past Director / Accountant access: thief / awox risk + seniority)',
        ],
        'esi-killmails.read_killmails.v1' => [
            'tier'    => 'optional',
            'feature' => 'Killmail history (private PvP cross-check beyond public zKillboard)',
        ],
        'esi-characters.read_standings.v1' => [
            'tier'    => 'optional',
            'feature' => 'Personal standings (blue to a hostile entity? spy / opsec signal)',
        ],
        'esi-characters.read_contacts.v1' => [
            'tier'    => 'optional',
            'feature' => 'Contacts (awox / spy network analysis)',
        ],
    ];

    /**
     * All SSO profiles SeAT knows about. Each is a plain object with
     * id / name / scopes[] / default. Returns [] when the setting is
     * absent or malformed (very fresh install).
     *
     * @return array<int, object>
     */
    public function availableProfiles(): array
    {
        try {
            $raw = setting('sso_scopes', true);
            if (is_null($raw)) {
                return [];
            }
            return collect($raw)->filter(fn ($p) => isset($p->name))->values()->all();
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] RecruitmentSsoService::availableProfiles failed: ' . $e->getMessage());
            return [];
        }
    }

    /** The SeAT profile flagged default, or null. */
    public function defaultProfile(): ?object
    {
        foreach ($this->availableProfiles() as $p) {
            if (!empty($p->default)) {
                return $p;
            }
        }
        return null;
    }

    /** The operator's chosen profile NAME (empty string = none chosen). */
    public function selectedProfileName(): string
    {
        return trim((string) Setting::getValue(self::SETTING_PROFILE, ''));
    }

    /**
     * Resolve the profile the funnel actually uses: the chosen one when
     * it's set and still exists, otherwise SeAT's default. Returns null
     * only when no profiles exist at all.
     */
    public function resolveEffectiveProfile(): ?object
    {
        $chosen = $this->selectedProfileName();
        if ($chosen !== '') {
            foreach ($this->availableProfiles() as $p) {
                if ((string) $p->name === $chosen) {
                    return $p;
                }
            }
        }
        return $this->defaultProfile();
    }

    /**
     * True when the operator picked a profile that no longer exists
     * (renamed/deleted in SeAT). The funnel safely falls back to default,
     * but we surface this so they can re-pick.
     */
    public function selectionIsStale(): bool
    {
        $chosen = $this->selectedProfileName();
        if ($chosen === '') {
            return false;
        }
        foreach ($this->availableProfiles() as $p) {
            if ((string) $p->name === $chosen) {
                return false;
            }
        }
        return true;
    }

    /**
     * The profile NAME to route a first-login through, or null to use
     * SeAT's plain `/eve` (default profile). Only returns a name when a
     * deliberately-chosen, still-valid profile is set — a stale or empty
     * choice falls back to null so we never abort(400) on a bad name.
     */
    public function routingProfileName(): ?string
    {
        $chosen = $this->selectedProfileName();
        if ($chosen === '' || $this->selectionIsStale()) {
            return null;
        }
        return $chosen;
    }

    /**
     * Scopes the SeAT DEFAULT profile carries that the effective recruitment
     * profile does NOT. These are the scopes an existing character would
     * LOSE if they log in fresh through the recruitment funnel: SeAT
     * OVERWRITES a token's scopes on every login (it does not merge), so a
     * narrower recruitment profile downgrades the character and limits its
     * future ESI updates. Empty in the safe cases: the funnel uses the
     * default profile, or the recruitment profile is a superset of it.
     *
     * @return array<int,string>
     */
    public function scopesLostVsDefault(): array
    {
        // Only a deliberately-routed, valid profile downgrades anyone. With
        // no/stale choice the funnel uses SeAT's normal login (default
        // profile), so nothing changes vs SeAT's own behaviour.
        if ($this->routingProfileName() === null) {
            return [];
        }

        $effective = $this->resolveEffectiveProfile();
        $default   = $this->defaultProfile();
        if (!$effective || !$default || ($effective->name ?? null) === ($default->name ?? null)) {
            return [];
        }

        $effScopes = array_map('strval', (array) ($effective->scopes ?? []));
        $defScopes = array_map('strval', (array) ($default->scopes ?? []));

        // publicData is always implied; don't flag it as "lost".
        return array_values(array_filter(
            array_diff($defScopes, $effScopes),
            fn ($s) => $s !== 'publicData'
        ));
    }

    /**
     * Analyse the effective profile against the scope catalog.
     *
     * @return array{
     *   profile_name: ?string,
     *   is_default: bool,
     *   stale: bool,
     *   scopes: array<int,string>,
     *   rows: array<int, array{scope:string, tier:string, feature:string, present:bool}>,
     *   minimal_ok: bool,
     *   full_ok: bool,
     *   missing_required: array<int,string>,
     *   missing_recommended: array<int,string>
     * }
     */
    public function analyze(): array
    {
        $profile = $this->resolveEffectiveProfile();
        $scopes  = [];
        if ($profile && isset($profile->scopes) && is_array($profile->scopes)) {
            $scopes = array_values(array_map('strval', $profile->scopes));
        }

        $rows = [];
        $missingRequired = [];
        $missingRecommended = [];
        $missingOptional = [];
        foreach (self::SCOPE_CATALOG as $scope => $meta) {
            $present = in_array($scope, $scopes, true);
            $rows[] = [
                'scope'   => $scope,
                'tier'    => $meta['tier'],
                'feature' => $meta['feature'],
                'present' => $present,
            ];
            if (!$present && $meta['tier'] === 'required') {
                $missingRequired[] = $scope;
            }
            if (!$present && $meta['tier'] === 'recommended') {
                $missingRecommended[] = $scope;
            }
            if (!$present && $meta['tier'] === 'optional') {
                $missingOptional[] = $scope;
            }
        }

        return [
            'profile_name'        => $profile->name ?? null,
            'is_default'          => $profile && !empty($profile->default),
            'stale'               => $this->selectionIsStale(),
            'scopes'              => $scopes,
            'rows'                => $rows,
            'minimal_ok'          => empty($missingRequired),
            // "Full" is required + recommended only. Optional scopes are bonus
            // signals, so a profile is still "full" without them.
            'full_ok'             => empty($missingRequired) && empty($missingRecommended),
            'missing_required'    => $missingRequired,
            'missing_recommended' => $missingRecommended,
            'missing_optional'    => $missingOptional,
        ];
    }
}

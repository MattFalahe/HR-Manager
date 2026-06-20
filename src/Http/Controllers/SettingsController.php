<?php

namespace HrManager\Http\Controllers;

use HrManager\Models\RoleTierMapping;
use HrManager\Models\Setting;
use HrManager\Models\WebhookConfiguration;
use HrManager\Services\DiscordRoleResolver;
use HrManager\Services\TierService;
use HrManager\Services\WebhookUrlValidator;
use HrManager\Support\TierLevel;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SettingsController extends Controller
{
    /**
     * JSON endpoint that returns the merged Discord role list from all
     * detected providers (SeAT Broadcast + SeAT Connector + legacy
     * warlof). Consumed by the AJAX-lazy-load role picker shared by
     * every Discord role input in the settings UI — pre-rendering all
     * roles inline in every form was wasteful (4 pickers × 100+ roles
     * = 400 inline items on every settings page load).
     *
     * Same shape MM + SM expose so the picker JS can be ported across
     * plugins with zero divergence.
     */
    public function roles()
    {
        return response()->json([
            'label' => \HrManager\Services\DiscordRoleResolver::providerLabel(),
            'roles' => \HrManager\Services\DiscordRoleResolver::listRoles(),
        ]);
    }

    public function index()
    {
        $settings = [
            'stale_days'           => Setting::getValue('stale_days', config('hr-manager.applications.stale_days', 7)),
            'max_pending'          => Setting::getValue('max_pending', config('hr-manager.applications.max_pending_per_character', 1)),
            'allow_withdrawal'     => Setting::getValue('allow_withdrawal', config('hr-manager.applications.allow_withdrawal', true)),
            'cache_duration'       => Setting::getValue('cache_duration', config('hr-manager.assessment.cache_duration', 60)),
            'enable_mining_data'   => Setting::getValue('enable_mining_data', config('hr-manager.features.enable_mining_data', true)),
            'enable_ratting_data'  => Setting::getValue('enable_ratting_data', config('hr-manager.features.enable_ratting_data', true)),
            'enable_webhooks'      => Setting::getValue('enable_webhooks', config('hr-manager.features.enable_webhooks', true)),
            'enable_private_notes' => Setting::getValue('enable_private_notes', config('hr-manager.features.enable_private_notes', true)),
            'seat_connector_base_url' => Setting::getValue('seat_connector_base_url', config('hr-manager.recruitment.seat_connector_base_url', '')),
            // Security policy
            'security_token_loss_enabled'       => (bool) Setting::getValue('security_token_loss_enabled', false),
            'security_token_loss_purge_hours'   => (int) Setting::getValue('security_token_loss_purge_hours', 72),
            'intel_recruiter_view_enabled'      => (bool) Setting::getValue(\HrManager\Services\IntelService::SETTING_RECRUITER_VIEW, false),
        ];

        $webhooks = WebhookConfiguration::orderBy('name')->get();
        $corporations = \Seat\Eveapi\Models\Corporation\CorporationInfo::orderBy('name')
            ->get(['corporation_id', 'name']);

        $discordRoles         = DiscordRoleResolver::listRoles();
        $discordRolesProvider = DiscordRoleResolver::providerLabel();
        $discordRoleMap       = DiscordRoleResolver::roleLookupMap();

        $tierService    = app(TierService::class);
        $tierMappings   = RoleTierMapping::orderBy('tier_level', 'desc')->orderBy('corporation_id')->get();
        $tierAuto       = $tierService->autoResolutionAvailable();
        $tierLevels     = TierLevel::ALL;
        $tierDefaults   = [];
        foreach (TierLevel::ALL as $level) {
            $tierDefaults[$level] = $tierService->defaultThresholdDays($level);
        }

        // Notification Routing Map data — for each category, which
        // webhooks fire and what Discord role each pings. Read-only
        // surface that resolves the per-webhook notify_* booleans
        // + discord_role_id into a single "who hears about this
        // event" view. Mirrors Structure Manager's Routing Map
        // contract (each plugin builds its own data; the partial
        // pattern is shared). Categories grouped so the table reads
        // top-down by lifecycle stage.
        $routingCategories = [
            'recruitment' => [
                'label' => trans('hr-manager::settings.routing_group_recruitment'),
                'items' => [
                    ['key' => 'notify_application_submitted', 'label' => trans('hr-manager::settings.notify_application_submitted')],
                    ['key' => 'notify_application_accepted',  'label' => trans('hr-manager::settings.notify_application_accepted')],
                    ['key' => 'notify_application_rejected',  'label' => trans('hr-manager::settings.notify_application_rejected')],
                    ['key' => 'notify_status_change',         'label' => trans('hr-manager::settings.notify_status_change')],
                ],
            ],
            'retention' => [
                'label' => trans('hr-manager::settings.routing_group_retention'),
                'items' => [
                    ['key' => 'notify_inactive_director',     'label' => trans('hr-manager::settings.notify_inactive_director')],
                    ['key' => 'notify_dead_weight',           'label' => trans('hr-manager::settings.notify_dead_weight')],
                    ['key' => 'notify_purge_reminder',        'label' => trans('hr-manager::settings.notify_purge_reminder')],
                    ['key' => 'notify_player_status',         'label' => trans('hr-manager::settings.notify_player_status')],
                ],
            ],
            'wallet' => [
                'label' => trans('hr-manager::settings.routing_group_wallet'),
                'items' => [
                    ['key' => 'notify_wallet_stalled',            'label' => trans('hr-manager::settings.notify_wallet_stalled')],
                    ['key' => 'notify_wallet_compliance_dropped', 'label' => trans('hr-manager::settings.notify_wallet_compliance_dropped')],
                    ['key' => 'notify_wallet_milestone',          'label' => trans('hr-manager::settings.notify_wallet_milestone')],
                ],
            ],
        ];

        $corpNameLookup = $corporations->pluck('name', 'corporation_id')->toArray();

        // Recruiter access feature settings
        $accessSettings = [
            'enabled'         => (bool) \HrManager\Models\Setting::getValue(\HrManager\Services\ApplicantAccessService::SETTING_ENABLED, false),
            'permissions'     => app(\HrManager\Services\ApplicantAccessService::class)->resolvePermissions(),
            'max_duration'    => app(\HrManager\Services\ApplicantAccessService::class)->resolveMaxDurationDays(),
            'include_alts'    => (bool) \HrManager\Models\Setting::getValue(\HrManager\Services\ApplicantAccessService::SETTING_INCLUDE_ALTS, true),
            'available_perms' => \HrManager\Services\ApplicantAccessService::AVAILABLE_PERMISSIONS,
        ];

        // Applicant Connector-link access feature settings. Temporary
        // `seat-connector.view` grant so applicants can reach the Connector
        // identity page and link Discord while their application is open.
        $connectorAccessSvc      = app(\HrManager\Services\ApplicantConnectorAccessService::class);
        $connectorAccessSettings = [
            'enabled'             => $connectorAccessSvc->isFeatureEnabled(),
            'permission'          => $connectorAccessSvc->resolvePermission(),
            'max_duration'        => $connectorAccessSvc->resolveMaxDurationDays(),
            'connector_available' => $connectorAccessSvc->connectorAvailable(),
            'default_permission'  => \HrManager\Services\ApplicantConnectorAccessService::DEFAULT_PERMISSION,
        ];

        // SSO & Scopes tab — available SeAT profiles + the chosen one +
        // the sufficiency analysis for the effective profile.
        // Alliance-tax exempt character IDs (textarea content for the Features
        // tab) + their resolved names for an at-a-glance confirmation line.
        $allianceTaxExemptIds  = (array) (Setting::getValue('alliance_tax_exempt_chars', []) ?: []);
        $allianceTaxExemptText = implode("\n", array_map('intval', $allianceTaxExemptIds));
        $allianceTaxExemptNames = empty($allianceTaxExemptIds)
            ? []
            : app(\HrManager\Services\NameResolutionService::class)->getCharacterNamesWithFallback(array_map('intval', $allianceTaxExemptIds));

        // Applicant assessment criteria — the operator-tunable thresholds the
        // ApplicantAssessmentService scores against. Seeded from the service's
        // shipped defaults so the form always has working values.
        $assessmentCriteria = [];
        foreach (\HrManager\Services\ApplicantAssessmentService::CRITERIA_DEFAULTS as $ackey => $acdefault) {
            $assessmentCriteria[$ackey] = Setting::getValue($ackey, $acdefault);
        }
        $assessmentDefaults = \HrManager\Services\ApplicantAssessmentService::CRITERIA_DEFAULTS;

        // Standings reference (the "who is hostile / friendly" source the
        // assessment's standings signal compares an applicant's contacts to).
        $standingsSvc = app(\HrManager\Services\StandingsReferenceService::class);
        $standingsSettings = [
            'source'             => $standingsSvc->source(),
            'precedence'         => $standingsSvc->precedence(),
            'seat_available'     => $standingsSvc->seatStandingsAvailable(),
            'seat_profiles'      => $standingsSvc->seatProfiles(),
            'seat_profile'       => (int) Setting::getValue(\HrManager\Services\StandingsReferenceService::SETTING_SEAT_PROFILE, 0),
            'hostile_alliances'  => $this->idListText(\HrManager\Services\StandingsReferenceService::SETTING_HOSTILE_ALLIANCES),
            'hostile_corps'      => $this->idListText(\HrManager\Services\StandingsReferenceService::SETTING_HOSTILE_CORPS),
            'friendly_alliances' => $this->idListText(\HrManager\Services\StandingsReferenceService::SETTING_FRIENDLY_ALLIANCES),
            'friendly_corps'     => $this->idListText(\HrManager\Services\StandingsReferenceService::SETTING_FRIENDLY_CORPS),
        ];

        $ssoService         = app(\HrManager\Services\RecruitmentSsoService::class);
        $ssoProfiles        = $ssoService->availableProfiles();
        $ssoSelectedProfile = $ssoService->selectedProfileName();
        $ssoAnalysis        = $ssoService->analyze();
        // Scopes existing characters would lose on a fresh recruitment login
        // (SeAT overwrites token scopes, never merges). Non-empty = downgrade
        // risk to flag.
        $ssoScopesLost      = $ssoService->scopesLostVsDefault();

        // Purge squad cleanup: opt-in toggle + timing + the never-touch
        // exclusions list, plus every squad on the install for the picker.
        $squadSvc    = app(\HrManager\Services\SeatSquadService::class);
        $purgeSquads = [
            'enabled'    => (bool) Setting::getValue('purge_auto_squad_removal', false),
            'hours'      => (int) Setting::getValue('purge_auto_squad_removal_hours', 24),
            'excluded'   => $squadSvc->excludedSquadIds(),
            'all_squads' => $squadSvc->allSquads(),
        ];

        return view('hr-manager::settings.index', compact(
            'settings', 'webhooks', 'corporations',
            'discordRoles', 'discordRolesProvider', 'discordRoleMap',
            'tierMappings', 'tierAuto', 'tierLevels', 'tierDefaults',
            'routingCategories', 'corpNameLookup',
            'accessSettings', 'connectorAccessSettings',
            'ssoProfiles', 'ssoSelectedProfile', 'ssoAnalysis', 'ssoScopesLost',
            'allianceTaxExemptText', 'allianceTaxExemptNames',
            'assessmentCriteria', 'assessmentDefaults', 'standingsSettings', 'purgeSquads'
        ));
    }

    public function update(Request $request)
    {
        $request->validate([
            'stale_days'              => 'nullable|integer|min:1|max:365',
            'max_pending'             => 'nullable|integer|min:1|max:10',
            'cache_duration'          => 'nullable|integer|min:5|max:1440',
            'allow_withdrawal'        => 'nullable|boolean',
            'enable_mining_data'      => 'nullable|boolean',
            'enable_ratting_data'     => 'nullable|boolean',
            'enable_webhooks'         => 'nullable|boolean',
            'enable_private_notes'    => 'nullable|boolean',
            'seat_connector_base_url' => 'nullable|url|max:255',
            // Security policy toggles for the token-loss workflow
            'security_token_loss_enabled'       => 'nullable|boolean',
            'security_token_loss_purge_hours'   => 'nullable|integer|min:0|max:720',
            // Intel database recruiter-share toggle
            'intel_recruiter_view_enabled'      => 'nullable|boolean',
            // Recruitment SSO scope profile (free string; self-heals to
            // default if it names a profile that no longer exists)
            'recruitment_sso_profile'           => 'nullable|string|max:255',
            // Applicant assessment criteria (scoring thresholds)
            'assess_min_age_days'        => 'nullable|integer|min:0|max:3650',
            'assess_hopper_corps_12mo'   => 'nullable|integer|min:1|max:50',
            'assess_min_avg_tenure_days' => 'nullable|integer|min:1|max:3650',
            'assess_npc_park_days'       => 'nullable|integer|min:1|max:3650',
            'assess_min_sp'              => 'nullable|integer|min:0|max:1000000000',
            'assess_sec_floor'           => 'nullable|numeric|min:-10|max:5',
            // Standings reference
            'assess_standings_source'        => 'nullable|in:off,seat,own',
            'assess_standings_precedence'    => 'nullable|in:corp,alliance',
            'assess_standings_seat_profile'  => 'nullable|integer|min:0',
            'assess_hostile_alliances'       => 'nullable|string|max:20000',
            'assess_hostile_corps'           => 'nullable|string|max:20000',
            'assess_friendly_alliances'      => 'nullable|string|max:20000',
            'assess_friendly_corps'          => 'nullable|string|max:20000',
            // Purge squad cleanup
            'purge_auto_squad_removal'       => 'nullable|boolean',
            'purge_auto_squad_removal_hours' => 'nullable|integer|in:12,24',
            'purge_squad_exclusions'         => 'nullable|array',
            'purge_squad_exclusions.*'       => 'integer',
        ]);

        // Per-tab save guards. Each settings tab is its OWN <form> posting
        // here, so a save from one tab must NOT reset fields it never
        // rendered (absent booleans get forced to '0'). We key each block
        // on a hidden marker the tab's form carries (general_form /
        // features_form / access_settings_form / sso_settings_form).
        // Before these guards, saving the General tab silently wiped
        // every Features toggle and vice versa.

        // General tab (id=general)
        if ($request->has('general_form')) {
            $generalFields = [
                'stale_days'       => 'integer',
                'max_pending'      => 'integer',
                'cache_duration'   => 'integer',
                'allow_withdrawal' => 'boolean',
            ];
            foreach ($generalFields as $key => $type) {
                if ($request->has($key)) {
                    Setting::setValue($key, $request->input($key), $type);
                } elseif ($type === 'boolean') {
                    Setting::setValue($key, '0', $type);
                }
            }
        }

        // Features tab (id=features) — feature toggles + security policy +
        // intel recruiter-share (a dotted setting key).
        if ($request->has('features_form')) {
            $featureFields = [
                'enable_mining_data'                => 'boolean',
                'enable_ratting_data'               => 'boolean',
                'enable_webhooks'                   => 'boolean',
                'enable_private_notes'              => 'boolean',
                'security_token_loss_enabled'       => 'boolean',
                'security_token_loss_purge_hours'   => 'integer',
            ];
            foreach ($featureFields as $key => $type) {
                if ($request->has($key)) {
                    Setting::setValue($key, $request->input($key), $type);
                } elseif ($type === 'boolean') {
                    Setting::setValue($key, '0', $type);
                }
            }
            Setting::setValue(
                \HrManager\Services\IntelService::SETTING_RECRUITER_VIEW,
                $request->boolean('intel_recruiter_view_enabled') ? '1' : '0',
                'boolean'
            );

            // Alliance-tax exempt character IDs (textarea, one per line / comma
            // separated). We keep only the numeric tokens. Suppresses the
            // corp-tax compliance anomaly flags for members who pay the
            // alliance instead of the corp.
            $exemptRaw = (string) $request->input('alliance_tax_exempt_chars', '');
            $exemptIds = [];
            foreach (preg_split('/[\s,]+/', $exemptRaw) as $tok) {
                $tok = trim($tok);
                if ($tok !== '' && ctype_digit($tok)) {
                    $exemptIds[] = (int) $tok;
                }
            }
            Setting::setValue('alliance_tax_exempt_chars', array_values(array_unique($exemptIds)), 'json');
        }

        // SSO & Scopes tab (id=sso) — which SeAT SSO scope profile the
        // recruitment funnel sends applicants through. Empty = SeAT default.
        if ($request->has('sso_settings_form')) {
            $profile = trim((string) $request->input('recruitment_sso_profile', ''));
            Setting::setValue(
                \HrManager\Services\RecruitmentSsoService::SETTING_PROFILE,
                $profile,
                'string'
            );
        }

        // Applicant assessment criteria (id=assessment) — the scoring
        // thresholds the ApplicantAssessmentService reads. Integers stored as
        // integers; the security-status floor keeps its decimal as a string so
        // a value like -2.5 survives (the service casts it to float on read).
        if ($request->has('assessment_criteria_form')) {
            foreach ([
                'assess_min_age_days', 'assess_hopper_corps_12mo', 'assess_min_avg_tenure_days',
                'assess_npc_park_days', 'assess_min_sp',
            ] as $ackey) {
                if ($request->filled($ackey)) {
                    Setting::setValue($ackey, (int) $request->input($ackey), 'integer');
                }
            }
            if ($request->filled('assess_sec_floor')) {
                Setting::setValue('assess_sec_floor', (string) (float) $request->input('assess_sec_floor'), 'string');
            }
        }

        // Standings reference (id=assessment, second form). Source + precedence
        // + the SeAT profile pick + the four own-list ID textareas.
        if ($request->has('assessment_standings_form')) {
            $svc = \HrManager\Services\StandingsReferenceService::class;

            $src = (string) $request->input('assess_standings_source', $svc::SOURCE_OFF);
            if (!in_array($src, [$svc::SOURCE_SEAT, $svc::SOURCE_OWN], true)) {
                $src = $svc::SOURCE_OFF;
            }
            Setting::setValue($svc::SETTING_SOURCE, $src, 'string');

            $prec = (string) $request->input('assess_standings_precedence', $svc::PRECEDENCE_CORP);
            Setting::setValue($svc::SETTING_PRECEDENCE, $prec === $svc::PRECEDENCE_ALLIANCE ? $svc::PRECEDENCE_ALLIANCE : $svc::PRECEDENCE_CORP, 'string');

            Setting::setValue($svc::SETTING_SEAT_PROFILE, (int) $request->input('assess_standings_seat_profile', 0), 'integer');

            $lists = [
                'assess_hostile_alliances'  => $svc::SETTING_HOSTILE_ALLIANCES,
                'assess_hostile_corps'      => $svc::SETTING_HOSTILE_CORPS,
                'assess_friendly_alliances' => $svc::SETTING_FRIENDLY_ALLIANCES,
                'assess_friendly_corps'     => $svc::SETTING_FRIENDLY_CORPS,
            ];
            foreach ($lists as $field => $key) {
                Setting::setValue($key, $this->parseIdList((string) $request->input($field, '')), 'json');
            }
        }

        // Purge squad cleanup (id=purge-squads). Opt-in auto-removal toggle +
        // timing + the never-touch exclusions list (Former Member / Alliance).
        if ($request->has('purge_squads_form')) {
            Setting::setValue('purge_auto_squad_removal', $request->boolean('purge_auto_squad_removal') ? '1' : '0', 'boolean');

            $hours = (int) $request->input('purge_auto_squad_removal_hours', 24);
            Setting::setValue('purge_auto_squad_removal_hours', in_array($hours, [12, 24], true) ? $hours : 24, 'integer');

            $exclusions = array_values(array_unique(array_map('intval',
                array_filter((array) $request->input('purge_squad_exclusions', []), fn ($v) => is_numeric($v))
            )));
            Setting::setValue('purge_squad_exclusions', $exclusions, 'json');
        }

        // Recruiter access feature — feature toggle + permission set +
        // max duration + include-alts. Permissions posted as an array
        // of strings; filtered against the canonical list so an
        // operator can't sneak in a wildcard permission.
        if ($request->has('access_settings_form')) {
            // Detect an off→on transition so we can retroactively grant
            // access to handlers who joined while the feature was disabled.
            $accessWasEnabled = (bool) Setting::getValue(
                \HrManager\Services\ApplicantAccessService::SETTING_ENABLED, false
            );
            Setting::setValue(
                \HrManager\Services\ApplicantAccessService::SETTING_ENABLED,
                $request->boolean('recruiter_access_enabled'),
                'boolean'
            );
            Setting::setValue(
                \HrManager\Services\ApplicantAccessService::SETTING_INCLUDE_ALTS,
                $request->boolean('recruiter_access_include_alts'),
                'boolean'
            );
            $duration = (int) $request->input('recruiter_access_max_duration', 7);
            $duration = max(1, min(30, $duration));
            Setting::setValue(
                \HrManager\Services\ApplicantAccessService::SETTING_MAX_DURATION,
                $duration,
                'integer'
            );
            $perms = $request->input('recruiter_access_permissions', []);
            if (!is_array($perms)) $perms = [];
            $valid = array_values(array_intersect(
                $perms,
                \HrManager\Services\ApplicantAccessService::AVAILABLE_PERMISSIONS
            ));
            Setting::setValue(
                \HrManager\Services\ApplicantAccessService::SETTING_PERMISSIONS,
                $valid,
                'json'
            );

            // Off→on: retroactively grant every current handler so the
            // operator doesn't have to make each recruiter re-join.
            if (!$accessWasEnabled && $request->boolean('recruiter_access_enabled')) {
                try {
                    $granted = app(\HrManager\Services\ApplicantAccessService::class)->grantAllCurrentHandlers();
                    if ($granted > 0) {
                        session()->flash('info', trans('hr-manager::settings.access_retro_granted', ['n' => $granted]));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[HR Manager] retroactive grant-all failed: ' . $e->getMessage());
                }
            }
        }

        // Applicant Connector-link access — feature toggle + grant duration
        // + an advanced permission override. Separate form/marker from the
        // recruiter block above so saving one doesn't reset the other.
        if ($request->has('connector_access_form')) {
            $connectorSvc = app(\HrManager\Services\ApplicantConnectorAccessService::class);
            $connectorWasEnabled = $connectorSvc->isFeatureEnabled();

            Setting::setValue(
                \HrManager\Services\ApplicantConnectorAccessService::SETTING_ENABLED,
                $request->boolean('applicant_connector_access_enabled'),
                'boolean'
            );

            $connDuration = (int) $request->input(
                'applicant_connector_access_max_duration',
                \HrManager\Services\ApplicantConnectorAccessService::DEFAULT_MAX_DURATION_DAYS
            );
            $connDuration = max(1, min(180, $connDuration));
            Setting::setValue(
                \HrManager\Services\ApplicantConnectorAccessService::SETTING_MAX_DURATION,
                $connDuration,
                'integer'
            );

            // Advanced permission override. Blank or malformed → store the
            // default; only accept a bare {scope}.{ability} token so an
            // operator can't inject something odd into the gate string.
            $connPerm = trim((string) $request->input('applicant_connector_permission', ''));
            if ($connPerm === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $connPerm)) {
                $connPerm = \HrManager\Services\ApplicantConnectorAccessService::DEFAULT_PERMISSION;
            }
            Setting::setValue(
                \HrManager\Services\ApplicantConnectorAccessService::SETTING_PERMISSION,
                $connPerm,
                'string'
            );

            // Off→on: retroactively grant every in-flight applicant so the
            // ones who applied before the feature was enabled can still link.
            if (!$connectorWasEnabled && $request->boolean('applicant_connector_access_enabled')) {
                try {
                    $grantedConn = $connectorSvc->grantAllOpenApplicants();
                    if ($grantedConn > 0) {
                        session()->flash('info', trans('hr-manager::settings.connector_access_retro_granted', ['n' => $grantedConn]));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[HR Manager] retroactive connector grant-all failed: ' . $e->getMessage());
                }
            }
        }

        // Keep the operator on the tab they saved from (the hash drives the
        // tab-restore JS).
        $tabHash = null;
        if ($request->has('features_form'))            $tabHash = '#features';
        elseif ($request->has('access_settings_form')) $tabHash = '#recruiter-access';
        elseif ($request->has('connector_access_form')) $tabHash = '#recruiter-access';
        elseif ($request->has('sso_settings_form'))    $tabHash = '#sso';
        elseif ($request->has('assessment_criteria_form')) $tabHash = '#assessment';
        if ($tabHash) {
            return redirect()
                ->to(route('hr-manager.settings.index') . $tabHash)
                ->with('success', trans('hr-manager::settings.settings_saved'));
        }

        return redirect()->route('hr-manager.settings.index')
            ->with('success', trans('hr-manager::settings.settings_saved'));
    }

    public function storeWebhook(Request $request)
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'type'             => 'required|in:discord,slack',
            'webhook_url'      => 'required|url|max:2048',
            'corporation_id'   => 'nullable|integer',
            'discord_role_id'  => 'nullable|regex:/^\d{1,20}$/',
            'notify_application_submitted' => 'nullable|boolean',
            'notify_application_accepted'  => 'nullable|boolean',
            'notify_application_rejected'  => 'nullable|boolean',
            'notify_status_change'         => 'nullable|boolean',
            'notify_inactive_director'     => 'nullable|boolean',
            'notify_dead_weight'           => 'nullable|boolean',
            'notify_purge_reminder'        => 'nullable|boolean',
            'notify_player_status'         => 'nullable|boolean',
            'notify_wallet_stalled'        => 'nullable|boolean',
            'notify_wallet_compliance_dropped' => 'nullable|boolean',
            'notify_wallet_milestone'      => 'nullable|boolean',
        ]);

        $error = app(WebhookUrlValidator::class)->validate($request->type, $request->webhook_url);
        if ($error) {
            return redirect()->back()->with('error', $error)->withInput();
        }

        WebhookConfiguration::create([
            'name'             => $request->name,
            'type'             => $request->type,
            'webhook_url'      => $request->webhook_url,
            'is_enabled'       => true,
            'corporation_id'   => $request->filled('corporation_id') ? (int) $request->corporation_id : null,
            'discord_role_id'  => $request->discord_role_id,
            'notify_application_submitted' => (bool) $request->input('notify_application_submitted', false),
            'notify_application_accepted'  => (bool) $request->input('notify_application_accepted', false),
            'notify_application_rejected'  => (bool) $request->input('notify_application_rejected', false),
            'notify_status_change'         => (bool) $request->input('notify_status_change', false),
            'notify_inactive_director'     => (bool) $request->input('notify_inactive_director', false),
            'notify_dead_weight'           => (bool) $request->input('notify_dead_weight', false),
            'notify_purge_reminder'        => (bool) $request->input('notify_purge_reminder', false),
            'notify_player_status'         => (bool) $request->input('notify_player_status', false),
            'notify_wallet_stalled'        => (bool) $request->input('notify_wallet_stalled', false),
            'notify_wallet_compliance_dropped' => (bool) $request->input('notify_wallet_compliance_dropped', false),
            'notify_wallet_milestone'      => (bool) $request->input('notify_wallet_milestone', false),
        ]);

        return redirect()->route('hr-manager.settings.index')
            ->with('success', trans('hr-manager::settings.webhook_created'));
    }

    public function updateWebhook(Request $request, int $id)
    {
        $webhook = WebhookConfiguration::findOrFail($id);

        $request->validate([
            'name'             => 'required|string|max:255',
            'type'             => 'sometimes|in:discord,slack',
            'webhook_url'      => 'required|url|max:2048',
            'corporation_id'   => 'nullable|integer',
            'discord_role_id'  => 'nullable|regex:/^\d{1,20}$/',
            'is_enabled'       => 'nullable|boolean',
            'notify_application_submitted' => 'nullable|boolean',
            'notify_application_accepted'  => 'nullable|boolean',
            'notify_application_rejected'  => 'nullable|boolean',
            'notify_status_change'         => 'nullable|boolean',
            'notify_inactive_director'     => 'nullable|boolean',
            'notify_dead_weight'           => 'nullable|boolean',
            'notify_purge_reminder'        => 'nullable|boolean',
            'notify_player_status'         => 'nullable|boolean',
            'notify_wallet_stalled'        => 'nullable|boolean',
            'notify_wallet_compliance_dropped' => 'nullable|boolean',
            'notify_wallet_milestone'      => 'nullable|boolean',
        ]);

        $type = $request->input('type', $webhook->type);
        $error = app(WebhookUrlValidator::class)->validate($type, $request->webhook_url);
        if ($error) {
            return redirect()->back()->with('error', $error)->withInput();
        }

        $webhook->update([
            'name'             => $request->name,
            'type'             => $type,
            'webhook_url'      => $request->webhook_url,
            // The edit form always renders the is_enabled checkbox, so an
            // absent value means the operator unticked it (mute). Read the
            // actual checkbox state rather than defaulting to the old value.
            'is_enabled'       => $request->boolean('is_enabled'),
            'corporation_id'   => $request->filled('corporation_id') ? (int) $request->corporation_id : null,
            'discord_role_id'  => $request->discord_role_id,
            'notify_application_submitted' => (bool) $request->input('notify_application_submitted', false),
            'notify_application_accepted'  => (bool) $request->input('notify_application_accepted', false),
            'notify_application_rejected'  => (bool) $request->input('notify_application_rejected', false),
            'notify_status_change'         => (bool) $request->input('notify_status_change', false),
            'notify_inactive_director'     => (bool) $request->input('notify_inactive_director', false),
            'notify_dead_weight'           => (bool) $request->input('notify_dead_weight', false),
            'notify_purge_reminder'        => (bool) $request->input('notify_purge_reminder', false),
            'notify_player_status'         => (bool) $request->input('notify_player_status', false),
            'notify_wallet_stalled'        => (bool) $request->input('notify_wallet_stalled', false),
            'notify_wallet_compliance_dropped' => (bool) $request->input('notify_wallet_compliance_dropped', false),
            'notify_wallet_milestone'      => (bool) $request->input('notify_wallet_milestone', false),
        ]);

        return redirect()->route('hr-manager.settings.index')
            ->with('success', trans('hr-manager::settings.webhook_updated'));
    }

    public function deleteWebhook(int $id)
    {
        WebhookConfiguration::findOrFail($id)->delete();
        return redirect()->route('hr-manager.settings.index')
            ->with('success', trans('hr-manager::settings.webhook_deleted'));
    }

    public function testWebhook(int $id)
    {
        $webhook = WebhookConfiguration::findOrFail($id);

        try {
            $service = app(\HrManager\Services\WebhookService::class);
            $success = $service->testWebhook($webhook);
            return redirect()->route('hr-manager.settings.index')
                ->with($success ? 'success' : 'error', trans('hr-manager::settings.webhook_test_' . ($success ? 'sent' : 'failed')));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[HR Manager] Webhook test failed', [
                'webhook_id' => $id, 'error' => $e->getMessage(),
            ]);
            return redirect()->route('hr-manager.settings.index')
                ->with('error', trans('hr-manager::settings.webhook_test_failed'));
        }
    }

    // -----------------------------------------------------------------
    // Activity Tier mapping CRUD
    // -----------------------------------------------------------------

    public function storeTierMapping(Request $request)
    {
        $request->validate([
            'corporation_id'   => 'nullable|integer',
            'discord_role_id'  => 'required|regex:/^\d{1,20}$/',
            'tier_level'       => 'required|integer|in:' . implode(',', TierLevel::ALL),
            'threshold_days'   => 'nullable|integer|min:1|max:3650',
            'notes'            => 'nullable|string|max:500',
        ]);

        $corpId = $request->filled('corporation_id') ? (int) $request->corporation_id : null;

        $exists = RoleTierMapping::where('discord_role_id', $request->discord_role_id)
            ->where(function ($q) use ($corpId) {
                if ($corpId === null) {
                    $q->whereNull('corporation_id');
                } else {
                    $q->where('corporation_id', $corpId);
                }
            })
            ->exists();
        if ($exists) {
            return redirect()->back()
                ->with('error', trans('hr-manager::settings.tier_mapping_duplicate'))
                ->withInput();
        }

        RoleTierMapping::create([
            'corporation_id'  => $corpId,
            'discord_role_id' => $request->discord_role_id,
            'tier_level'      => (int) $request->tier_level,
            'threshold_days'  => $request->filled('threshold_days') ? (int) $request->threshold_days : null,
            'notes'           => $request->notes,
            'created_by'      => auth()->user()->id,
        ]);

        return redirect()->route('hr-manager.settings.index')
            ->with('success', trans('hr-manager::settings.tier_mapping_created'));
    }

    public function updateTierMapping(Request $request, int $id)
    {
        $mapping = RoleTierMapping::findOrFail($id);

        $request->validate([
            'tier_level'     => 'required|integer|in:' . implode(',', TierLevel::ALL),
            'threshold_days' => 'nullable|integer|min:1|max:3650',
            'notes'          => 'nullable|string|max:500',
        ]);

        $mapping->update([
            'tier_level'     => (int) $request->tier_level,
            'threshold_days' => $request->filled('threshold_days') ? (int) $request->threshold_days : null,
            'notes'          => $request->notes,
        ]);

        return redirect()->route('hr-manager.settings.index')
            ->with('success', trans('hr-manager::settings.tier_mapping_updated'));
    }

    public function deleteTierMapping(int $id)
    {
        RoleTierMapping::findOrFail($id)->delete();
        return redirect()->route('hr-manager.settings.index')
            ->with('success', trans('hr-manager::settings.tier_mapping_deleted'));
    }

    public function updateTierDefaults(Request $request)
    {
        $rules = [];
        foreach (TierLevel::ALL as $level) {
            $rules[TierLevel::thresholdSettingKey($level)] = 'nullable|integer|min:1|max:3650';
        }
        $request->validate($rules);

        foreach (TierLevel::ALL as $level) {
            $key = TierLevel::thresholdSettingKey($level);
            $value = $request->input($key);
            if ($value === null || $value === '') {
                Setting::where('key', $key)->delete();
                continue;
            }
            Setting::setValue($key, (int) $value, 'integer');
        }

        return redirect()->route('hr-manager.settings.index')
            ->with('success', trans('hr-manager::settings.tier_defaults_saved'));
    }

    /**
     * Render a stored JSON id-list setting as newline-separated text for a
     * <textarea> (one id per line).
     */
    private function idListText(string $settingKey): string
    {
        $ids = (array) (Setting::getValue($settingKey, []) ?: []);
        return implode("\n", array_map('intval', array_filter($ids, fn ($v) => (int) $v > 0)));
    }

    /**
     * Parse a free-text id list (newlines / commas / spaces) into a clean,
     * unique, positive-int array for storage.
     *
     * @return array<int>
     */
    private function parseIdList(string $raw): array
    {
        $ids = [];
        foreach (preg_split('/[\s,]+/', $raw) as $tok) {
            $tok = trim($tok);
            if ($tok !== '' && ctype_digit($tok)) {
                $ids[] = (int) $tok;
            }
        }
        return array_values(array_unique($ids));
    }
}

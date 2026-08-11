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
            'withdrawal_reason_enabled'  => (bool) Setting::getValue('withdrawal_reason_enabled', config('hr-manager.applications.withdrawal_reason_enabled', false)),
            'withdrawal_reason_required' => (bool) Setting::getValue('withdrawal_reason_required', config('hr-manager.applications.withdrawal_reason_required', false)),
            'blacklist_accept_guard_enabled' => (bool) Setting::getValue('blacklist_accept_guard_enabled', config('hr-manager.applications.blacklist_accept_guard_enabled', true)),
            'cache_duration'       => Setting::getValue('cache_duration', config('hr-manager.assessment.cache_duration', 60)),
            'enable_mining_data'   => Setting::getValue('enable_mining_data', config('hr-manager.features.enable_mining_data', true)),
            'enable_ratting_data'  => Setting::getValue('enable_ratting_data', config('hr-manager.features.enable_ratting_data', true)),
            'enable_webhooks'      => Setting::getValue('enable_webhooks', config('hr-manager.features.enable_webhooks', true)),
            'enable_private_notes' => Setting::getValue('enable_private_notes', config('hr-manager.features.enable_private_notes', true)),
            'enable_role_alignment' => (bool) Setting::getValue('enable_role_alignment', config('hr-manager.features.enable_role_alignment', false)),
            'enable_audit_log'      => (bool) Setting::getValue('enable_audit_log', config('hr-manager.features.enable_audit_log', false)),
            'enable_evewho_roster'  => (bool) Setting::getValue('enable_evewho_roster', false),
            'enable_profile_prewarm' => (bool) Setting::getValue('enable_profile_prewarm', false),
            // Discord role IDs the operator has marked sensitive / leadership,
            // for the Discord access-depth view. Stored as a JSON array of ids.
            'sensitive_discord_roles' => array_values(array_filter((array) json_decode((string) Setting::getValue('sensitive_discord_roles', '[]'), true))),
            'seat_connector_base_url' => Setting::getValue('seat_connector_base_url', config('hr-manager.recruitment.seat_connector_base_url', '')),
            // Security policy
            'security_token_loss_enabled'       => (bool) Setting::getValue('security_token_loss_enabled', false),
            'security_token_loss_purge_hours'   => (int) Setting::getValue('security_token_loss_purge_hours', 72),
            'security_token_loss_trigger_mode'  => app(\HrManager\Services\TokenLossService::class)->triggerMode(),
            'security_token_loss_coverage_pct'  => app(\HrManager\Services\TokenLossService::class)->coveragePct(),
            'security_token_loss_squad_drop_enabled'            => (bool) Setting::getValue('security_token_loss_squad_drop_enabled', false),
            'security_token_loss_squad_drop_hours'              => (int) Setting::getValue('security_token_loss_squad_drop_hours', 24),
            'security_token_loss_squad_drop_director_immediate' => (bool) Setting::getValue('security_token_loss_squad_drop_director_immediate', false),
            'intel_recruiter_view_enabled'      => (bool) Setting::getValue(\HrManager\Services\IntelService::SETTING_RECRUITER_VIEW, false),
            'applicant_watchlist_autoflag'      => (bool) Setting::getValue(\HrManager\Services\ApplicantScreeningService::SETTING_AUTOFLAG, false),
            // Member onboarding welcome (opt-in)
            'onboarding_welcome_enabled'        => (bool) Setting::getValue(\HrManager\Services\OnboardingService::SETTING_ENABLED, false),
            'onboarding_welcome_delay_minutes'  => (int) Setting::getValue(\HrManager\Services\OnboardingService::SETTING_DELAY_MINUTES, 30),
        ];

        $webhooks = WebhookConfiguration::orderBy('name')->get();
        $corporations = \Seat\Eveapi\Models\Corporation\CorporationInfo::orderBy('name')
            ->get(['corporation_id', 'name']);

        $discordRoles         = DiscordRoleResolver::listRoles();
        $discordRolesProvider = DiscordRoleResolver::providerLabel();
        $discordRoleMap       = DiscordRoleResolver::roleLookupMap();

        // Gates the "mention the player" webhook toggle — needs seat-connector
        // to resolve a SeAT user to a Discord snowflake. Configurable ahead of
        // time either way (mirrors the Connector-access tab's pattern); it
        // just won't ping anyone until the framework is installed.
        $connectorAvailable = app(\HrManager\Services\SeatConnectorService::class)->isAvailable();

        // Onboarding welcome: per-corp template rows (keyed by corp id so the
        // editor pre-fills each corp's block) plus the shared casual default the
        // blank state falls back to. Empty collection before the table exists.
        $onboardingTemplates = \Illuminate\Support\Facades\Schema::hasTable('hr_manager_onboarding_templates')
            ? \HrManager\Models\OnboardingTemplate::get()->keyBy('corporation_id')
            : collect();
        $onboardingDefaultBody = (string) trans('hr-manager::onboarding.default_body');

        // Corps HR can actually onboard for — those it has member visibility
        // into (a director token). $corporations is EVERY corporation SeAT has
        // ever resolved, which on a real install is thousands, and the tab
        // renders a Discord role grid per corp. Rendering that for corps HR
        // cannot even see a join in blew the view's memory limit; a corp with
        // no roster can never produce an onboarding welcome anyway.
        $onboardingCorps = $corporations;
        foreach (['corporation_members', 'corporation_member_trackings'] as $rosterTable) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($rosterTable)) {
                continue;
            }
            $trackedIds = \Illuminate\Support\Facades\DB::table($rosterTable)
                ->distinct()
                ->pluck('corporation_id')
                ->map(fn ($c) => (int) $c)
                ->filter()
                ->all();

            if (!empty($trackedIds)) {
                $onboardingCorps = $corporations->whereIn('corporation_id', $trackedIds)->values();
                break;
            }
        }

        // Which corps a webhook can actually deliver an onboarding welcome for.
        // The per-corp switch and the webhook's corp scope are two different
        // things and easy to confuse, so the tab states the delivery side
        // outright rather than leaving "why did nothing arrive?" to guesswork.
        $onboardingCoveredCorps = [];
        if (\Illuminate\Support\Facades\Schema::hasColumn('hr_manager_webhook_configurations', 'notify_onboarding_welcome')) {
            $hooks = WebhookConfiguration::where('is_enabled', true)
                ->where('notify_onboarding_welcome', true)
                ->get(['corporation_id']);

            // A global webhook (null corp) covers every corp.
            if ($hooks->contains(fn ($h) => $h->corporation_id === null)) {
                $onboardingCoveredCorps = $onboardingCorps->pluck('corporation_id')->map(fn ($c) => (int) $c)->all();
            } else {
                $onboardingCoveredCorps = $hooks->pluck('corporation_id')->filter()->map(fn ($c) => (int) $c)->unique()->values()->all();
            }
        }

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
                    ['key' => 'notify_flagged_applicant',     'label' => trans('hr-manager::settings.notify_flagged_applicant')],
                    ['key' => 'notify_handler_note',          'label' => trans('hr-manager::settings.notify_handler_note')],
                ],
            ],
            'retention' => [
                'label' => trans('hr-manager::settings.routing_group_retention'),
                'items' => [
                    ['key' => 'notify_inactive_director',     'label' => trans('hr-manager::settings.notify_inactive_director')],
                    ['key' => 'notify_silent_wallet_director', 'label' => trans('hr-manager::settings.notify_silent_wallet_director')],
                    ['key' => 'notify_dead_weight',           'label' => trans('hr-manager::settings.notify_dead_weight')],
                    ['key' => 'notify_purge_reminder',        'label' => trans('hr-manager::settings.notify_purge_reminder')],
                    ['key' => 'notify_loa_marked',             'label' => trans('hr-manager::settings.notify_loa_marked')],
                    ['key' => 'notify_marked_for_purge',       'label' => trans('hr-manager::settings.notify_marked_for_purge')],
                    ['key' => 'notify_purge_personal',         'label' => trans('hr-manager::settings.notify_purge_personal')],
                    ['key' => 'notify_status_cleared',         'label' => trans('hr-manager::settings.notify_status_cleared')],
                    ['key' => 'notify_token_revoked',         'label' => trans('hr-manager::settings.notify_token_revoked')],
                    ['key' => 'notify_token_coverage',        'label' => trans('hr-manager::settings.notify_token_coverage')],
                ],
            ],
            'membership' => [
                'label' => trans('hr-manager::settings.routing_group_membership'),
                'items' => [
                    ['key' => 'notify_member_joined',       'label' => trans('hr-manager::settings.notify_member_joined')],
                    ['key' => 'notify_member_left',         'label' => trans('hr-manager::settings.notify_member_left')],
                    ['key' => 'notify_join_no_application', 'label' => trans('hr-manager::settings.notify_join_no_application')],
                    ['key' => 'notify_member_unregistered', 'label' => trans('hr-manager::settings.notify_member_unregistered')],
                    ['key' => 'notify_onboarding_welcome',  'label' => trans('hr-manager::settings.notify_onboarding_welcome')],
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

        // Member token requirement: which SSO profile a member token must carry
        // (drives the Members token badge + Corp Health token-coverage card).
        $tokenHealthSvc       = app(\HrManager\Services\TokenHealthService::class);
        $tokenRequiredProfile = $tokenHealthSvc->requiredProfileName();
        $tokenReqStale        = $tokenHealthSvc->requirementStale();
        $tokenRequiredScopes  = $tokenHealthSvc->requiredScopes();

        // Purge squad cleanup: opt-in toggle + timing + the never-touch
        // exclusions list, plus every squad on the install for the picker.
        $squadSvc    = app(\HrManager\Services\SeatSquadService::class);
        $purgeSquads = [
            'enabled'    => (bool) Setting::getValue('purge_auto_squad_removal', false),
            'hours'      => (int) Setting::getValue('purge_auto_squad_removal_hours', 24),
            'excluded'   => $squadSvc->excludedSquadIds(),
            'all_squads' => $squadSvc->allSquads(),
        ];

        // Buyback contribution — every buyback-running corp HR has seen, with
        // its observed BB target model + the per-corp valuation policy (saved
        // row or smart default). Empty when BB / MC are absent, which hides the
        // tab. The $corporations list above feeds the "attributes to" picker.
        $buybackSvc        = app(\HrManager\Services\BuybackContributionService::class);
        $buybackProgrammes = $buybackSvc->isAvailable() ? $buybackSvc->detectedProgrammes() : [];
        $buybackTiers      = \HrManager\Models\BuybackPolicy::TIERS;

        // Wallet-alert cadence: 0 = once per episode, else re-remind every N hours.
        $walletAlertRepeatHours = (int) Setting::getValue('wallet_alert_repeat_hours', 0);

        // Inactive-director alert cadence: 0 = once per episode, else re-remind
        // every N days while the director stays past their threshold.
        $inactiveDirectorRepeatDays = (int) Setting::getValue(
            \HrManager\Services\ClassifierService::SETTING_DIRECTOR_REPEAT_DAYS, 0
        );

        // Consolidated Notifications console: per-type master-enable states.
        $notifStates = [];
        foreach (\HrManager\Services\NotificationCatalog::keys() as $notifKey) {
            $notifStates[$notifKey] = \HrManager\Services\NotificationCatalog::isEnabled($notifKey);
        }

        return view('hr-manager::settings.index', compact(
            'settings', 'webhooks', 'corporations',
            'discordRoles', 'discordRolesProvider', 'discordRoleMap',
            'tierMappings', 'tierAuto', 'tierLevels', 'tierDefaults',
            'routingCategories', 'corpNameLookup',
            'accessSettings', 'connectorAccessSettings',
            'ssoProfiles', 'ssoSelectedProfile', 'ssoAnalysis', 'ssoScopesLost',
            'allianceTaxExemptText', 'allianceTaxExemptNames',
            'assessmentCriteria', 'assessmentDefaults', 'standingsSettings', 'purgeSquads',
            'tokenRequiredProfile', 'tokenReqStale', 'tokenRequiredScopes',
            'buybackProgrammes', 'buybackTiers', 'walletAlertRepeatHours', 'inactiveDirectorRepeatDays',
            'notifStates', 'connectorAvailable',
            'onboardingTemplates', 'onboardingDefaultBody', 'onboardingCoveredCorps', 'onboardingCorps'
        ));
    }

    /**
     * Save a per-corp buyback contribution policy (Buyback Contribution tab).
     * Keyed on the buyback-running corporation_id; an empty attributes-to means
     * "self" (the running corp).
     */
    public function updateBuybackPolicy(Request $request)
    {
        $request->validate([
            'corporation_id'            => 'required|integer',
            'counted'                   => 'nullable|boolean',
            'tier'                      => 'required|in:' . implode(',', \HrManager\Models\BuybackPolicy::TIERS),
            'weight'                    => 'required|numeric|min:0|max:100',
            'attributed_corporation_id' => 'nullable|integer',
            'apply_to_history'          => 'nullable|boolean',
        ]);

        $corpId   = (int) $request->corporation_id;
        $applyAll = $request->boolean('apply_to_history');
        $svc      = app(\HrManager\Services\BuybackContributionService::class);

        // Era model: changing the policy freezes the ENDING era at the OLD policy
        // so past contributions keep their weights, while the new policy applies
        // going forward. Opting in re-values EVERY row at the new policy instead
        // (initial setup / deliberate correction).
        if (!$applyAll) {
            // Freeze the currently-unfrozen rows at the OLD policy BEFORE saving.
            $svc->applyPolicySnapshot($corpId, true);
        }

        \HrManager\Models\BuybackPolicy::updateOrCreate(
            ['corporation_id' => $corpId],
            [
                'counted'                   => $request->boolean('counted'),
                'tier'                      => $request->tier,
                'weight'                    => (float) $request->weight,
                'attributed_corporation_id' => $request->filled('attributed_corporation_id')
                    ? (int) $request->attributed_corporation_id
                    : null,
            ]
        );

        if ($applyAll) {
            // Re-value EVERY row (past + present) at the just-saved policy.
            $svc->applyPolicySnapshot($corpId, false);
        }

        return redirect()
            ->to(route('hr-manager.settings.index') . '#buyback')
            ->with('success', trans($applyAll
                ? 'hr-manager::settings.buyback_policy_saved_revalued'
                : 'hr-manager::settings.buyback_policy_saved'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'stale_days'              => 'nullable|integer|min:1|max:365',
            'max_pending'             => 'nullable|integer|min:1|max:10',
            'cache_duration'          => 'nullable|integer|min:5|max:1440',
            'allow_withdrawal'        => 'nullable|boolean',
            'withdrawal_reason_enabled'  => 'nullable|boolean',
            'withdrawal_reason_required' => 'nullable|boolean',
            'blacklist_accept_guard_enabled' => 'nullable|boolean',
            'enable_mining_data'      => 'nullable|boolean',
            'enable_ratting_data'     => 'nullable|boolean',
            'enable_webhooks'         => 'nullable|boolean',
            'enable_private_notes'    => 'nullable|boolean',
            'enable_role_alignment'   => 'nullable|boolean',
            'enable_audit_log'        => 'nullable|boolean',
            'enable_evewho_roster'    => 'nullable|boolean',
            'enable_profile_prewarm'  => 'nullable|boolean',
            'seat_connector_base_url' => 'nullable|url|max:255',
            // Security policy toggles for the token-loss workflow
            'security_token_loss_enabled'       => 'nullable|boolean',
            'security_token_loss_purge_hours'   => 'nullable|integer|min:0|max:720',
            'security_token_loss_trigger_mode'  => 'nullable|in:main,any,coverage',
            'security_token_loss_coverage_pct'  => 'nullable|in:25,50,75,100',
            'security_token_loss_squad_drop_enabled'            => 'nullable|boolean',
            'security_token_loss_squad_drop_hours'              => 'nullable|integer|min:0|max:336',
            'security_token_loss_squad_drop_director_immediate' => 'nullable|boolean',
            // Intel database recruiter-share toggle
            'intel_recruiter_view_enabled'      => 'nullable|boolean',
            'applicant_watchlist_autoflag'      => 'nullable|boolean',
            // Member onboarding welcome
            'onboarding_welcome_enabled'        => 'nullable|boolean',
            'onboarding_welcome_delay_minutes'  => 'nullable|integer|min:0|max:1440',
            'onboarding_body'                   => 'nullable|array',
            'onboarding_roles'                  => 'nullable|array',
            'onboarding_corp_enabled'           => 'nullable|array',
            // Recruitment SSO scope profile (free string; self-heals to
            // default if it names a profile that no longer exists)
            'recruitment_sso_profile'           => 'nullable|string|max:255',
            'token_required_profile'            => 'nullable|string|max:255',
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
            // Wallet-alert repeat cadence (0 = once per episode)
            'wallet_alert_repeat_hours'      => 'nullable|integer|in:0,12,24,72,168',
            // Inactive-director repeat cadence (0 = once per episode)
            'inactive_director_repeat_days'  => 'nullable|integer|in:0,1,3,7,14,30',
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
                'stale_days'                 => 'integer',
                'max_pending'                => 'integer',
                'cache_duration'             => 'integer',
                'allow_withdrawal'           => 'boolean',
                'withdrawal_reason_enabled'  => 'boolean',
                'withdrawal_reason_required' => 'boolean',
                'blacklist_accept_guard_enabled' => 'boolean',
            ];
            foreach ($generalFields as $key => $type) {
                if ($request->has($key)) {
                    Setting::setValue($key, $request->input($key), $type);
                } elseif ($type === 'boolean') {
                    Setting::setValue($key, '0', $type);
                }
            }

            // Advanced: optional SeAT Connector base-URL override (reverse-proxy
            // setups). Empty clears it back to the env/config default.
            Setting::setValue(
                'seat_connector_base_url',
                trim((string) $request->input('seat_connector_base_url', '')),
                'string'
            );
        }

        // Features tab (id=features) — feature toggles + security policy +
        // intel recruiter-share (a dotted setting key).
        if ($request->has('features_form')) {
            $featureFields = [
                'enable_mining_data'                => 'boolean',
                'enable_ratting_data'               => 'boolean',
                'enable_webhooks'                   => 'boolean',
                'enable_private_notes'              => 'boolean',
                'enable_role_alignment'             => 'boolean',
                'enable_audit_log'                  => 'boolean',
                'enable_evewho_roster'              => 'boolean',
                'enable_profile_prewarm'            => 'boolean',
                'security_token_loss_enabled'       => 'boolean',
                'security_token_loss_purge_hours'   => 'integer',
                'security_token_loss_coverage_pct'  => 'integer',
                'security_token_loss_squad_drop_enabled'            => 'boolean',
                'security_token_loss_squad_drop_hours'              => 'integer',
                'security_token_loss_squad_drop_director_immediate' => 'boolean',
            ];
            foreach ($featureFields as $key => $type) {
                if ($request->has($key)) {
                    Setting::setValue($key, $request->input($key), $type);
                } elseif ($type === 'boolean') {
                    Setting::setValue($key, '0', $type);
                }
            }
            // Token-loss trigger mode (string enum). Only persist a recognised
            // value so a tampered form can't wedge an unknown mode.
            $triggerMode = (string) $request->input('security_token_loss_trigger_mode', \HrManager\Services\TokenLossService::TRIGGER_ANY);
            if (in_array($triggerMode, [
                \HrManager\Services\TokenLossService::TRIGGER_MAIN,
                \HrManager\Services\TokenLossService::TRIGGER_ANY,
                \HrManager\Services\TokenLossService::TRIGGER_COVERAGE,
            ], true)) {
                Setting::setValue(\HrManager\Services\TokenLossService::SETTING_TRIGGER_MODE, $triggerMode, 'string');
            }
            // Sensitive Discord roles (operator-curated). Persist the ticked
            // role ids as a JSON array; an empty submit clears the list.
            $sensitiveRoles = array_values(array_filter(array_map(
                'strval',
                (array) $request->input('sensitive_discord_roles', [])
            )));
            Setting::setValue('sensitive_discord_roles', json_encode($sensitiveRoles), 'string');
            Setting::setValue(
                \HrManager\Services\IntelService::SETTING_RECRUITER_VIEW,
                $request->boolean('intel_recruiter_view_enabled') ? '1' : '0',
                'boolean'
            );
            Setting::setValue(
                \HrManager\Services\ApplicantScreeningService::SETTING_AUTOFLAG,
                $request->boolean('applicant_watchlist_autoflag') ? '1' : '0',
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

        // Member token requirement profile (id=sso, second form). The SSO scope
        // profile a member's token must satisfy for the token-health check.
        if ($request->has('token_req_form')) {
            Setting::setValue(
                \HrManager\Services\TokenHealthService::SETTING_REQUIRED_PROFILE,
                trim((string) $request->input('token_required_profile', '')),
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

        // Wallet-alert cadence (id=webhooks). How often HR re-pings for an
        // ONGOING recurring wallet condition: 0 = once per episode, else repeat
        // every N hours while it persists.
        if ($request->has('wallet_alerts_form')) {
            $repeat = (int) $request->input('wallet_alert_repeat_hours', 0);
            Setting::setValue('wallet_alert_repeat_hours', in_array($repeat, [0, 12, 24, 72, 168], true) ? $repeat : 0, 'integer');

            // Inactive-director repeat cadence rides the same card.
            $dirRepeat = (int) $request->input('inactive_director_repeat_days', 0);
            Setting::setValue(
                \HrManager\Services\ClassifierService::SETTING_DIRECTOR_REPEAT_DAYS,
                in_array($dirRepeat, [0, 1, 3, 7, 14, 30], true) ? $dirRepeat : 0,
                'integer'
            );
        }

        // Consolidated Notifications console (id=notifications). Per-type master
        // switch — an absent checkbox means the type is disabled. Also owns the
        // shared wallet-alert cadence so the "how often" knob lives beside its
        // types.
        if ($request->has('notifications_form')) {
            foreach (\HrManager\Services\NotificationCatalog::keys() as $notifKey) {
                Setting::setValue(
                    \HrManager\Services\NotificationCatalog::ENABLED_PREFIX . $notifKey,
                    $request->has('notif_' . $notifKey) ? '1' : '0',
                    'boolean'
                );
            }
            $repeat = (int) $request->input('wallet_alert_repeat_hours', 0);
            Setting::setValue('wallet_alert_repeat_hours', in_array($repeat, [0, 12, 24, 72, 168], true) ? $repeat : 0, 'integer');
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

        // Onboarding welcome (id=onboarding). Enable + delay are global; the
        // Markdown welcome body and care-team @-mention roles are per corp. A
        // corp whose body is blank (or left at the shared default) AND has no
        // care roles drops its row, so it cleanly falls back to the default.
        if ($request->has('onboarding_form')) {
            Setting::setValue(
                \HrManager\Services\OnboardingService::SETTING_ENABLED,
                $request->boolean('onboarding_welcome_enabled') ? '1' : '0',
                'boolean'
            );
            $delay = (int) $request->input('onboarding_welcome_delay_minutes', 30);
            Setting::setValue(
                \HrManager\Services\OnboardingService::SETTING_DELAY_MINUTES,
                max(0, min($delay, 1440)),
                'integer'
            );

            if (\Illuminate\Support\Facades\Schema::hasTable('hr_manager_onboarding_templates')) {
                $defaultBody = trim((string) trans('hr-manager::onboarding.default_body'));
                $bodies      = (array) $request->input('onboarding_body', []);
                $roles       = (array) $request->input('onboarding_roles', []);
                $corpEnabled = (array) $request->input('onboarding_corp_enabled', []);
                foreach ($bodies as $corpId => $body) {
                    $corpId = (int) $corpId;
                    if ($corpId <= 0) {
                        continue;
                    }
                    $body    = trim((string) $body);
                    $roleIds = array_values(array_filter(array_map(
                        fn ($r) => preg_replace('/\D/', '', (string) $r),
                        (array) ($roles[$corpId] ?? [])
                    )));
                    // Unchecked boxes aren't posted, so absence means OFF.
                    $corpOn = (bool) ($corpEnabled[$corpId] ?? false);

                    // Nothing worth storing: the corp is off (the default state
                    // anyway), the body is blank or the shared default, and
                    // there are no care roles. Drop any row so the corp reads
                    // as a clean never-configured. A corp that is ON keeps its
                    // row even with a default template — that row is the only
                    // record of the opt-in.
                    if (!$corpOn && empty($roleIds) && ($body === '' || $body === $defaultBody)) {
                        \HrManager\Models\OnboardingTemplate::where('corporation_id', $corpId)->delete();
                        continue;
                    }
                    \HrManager\Models\OnboardingTemplate::updateOrCreate(
                        ['corporation_id' => $corpId],
                        [
                            'body'             => $body,
                            'mention_role_ids' => $roleIds,
                            'is_enabled'       => $corpOn,
                        ]
                    );
                }
            }
        }

        // Keep the operator on the tab they saved from (the hash drives the
        // tab-restore JS). Every form maps to its pane, so saving never dumps
        // you back on General and makes you navigate to where you were.
        $tab = match (true) {
            $request->has('general_form')              => 'general',
            $request->has('features_form')             => 'features',
            $request->has('purge_squads_form')         => 'purge-squads',
            $request->has('access_settings_form'),
            $request->has('connector_access_form')     => 'recruiter-access',
            $request->has('sso_settings_form'),
            $request->has('token_req_form')            => 'sso',
            $request->has('assessment_criteria_form'),
            $request->has('assessment_standings_form') => 'assessment',
            $request->has('wallet_alerts_form')        => 'webhooks',
            $request->has('notifications_form')        => 'notifications',
            $request->has('onboarding_form')           => 'onboarding',
            default                                    => null,
        };

        return $this->backToSettingsTab($tab)
            ->with('success', trans('hr-manager::settings.settings_saved'));
    }

    /**
     * Redirect back to the settings page on the tab the operator was working
     * in. The #hash drives the tab-restore JS, so saving a webhook or a tier
     * mapping returns you to that section instead of General.
     */
    private function backToSettingsTab(?string $tab = null): \Illuminate\Http\RedirectResponse
    {
        return redirect()->to(
            route('hr-manager.settings.index') . ($tab ? '#' . $tab : '')
        );
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
            'notify_silent_wallet_director' => 'nullable|boolean',
            'notify_handler_note'          => 'nullable|boolean',
            'notify_dead_weight'           => 'nullable|boolean',
            'notify_purge_reminder'        => 'nullable|boolean',
            'notify_loa_marked'            => 'nullable|boolean',
            'notify_marked_for_purge'      => 'nullable|boolean',
            'notify_status_cleared'        => 'nullable|boolean',
            'notify_purge_personal'        => 'nullable|boolean',
            'notify_token_revoked'         => 'nullable|boolean',
            'notify_token_coverage'        => 'nullable|boolean',
            'notify_wallet_stalled'        => 'nullable|boolean',
            'notify_wallet_compliance_dropped' => 'nullable|boolean',
            'notify_wallet_milestone'      => 'nullable|boolean',
            'notify_member_joined'         => 'nullable|boolean',
            'notify_member_left'           => 'nullable|boolean',
            'notify_join_no_application'   => 'nullable|boolean',
            'notify_member_unregistered'   => 'nullable|boolean',
            'notify_onboarding_welcome'    => 'nullable|boolean',
            'notify_flagged_applicant'     => 'nullable|boolean',
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
            'notify_silent_wallet_director' => (bool) $request->input('notify_silent_wallet_director', false),
            'notify_handler_note'          => (bool) $request->input('notify_handler_note', false),
            'notify_dead_weight'           => (bool) $request->input('notify_dead_weight', false),
            'notify_purge_reminder'        => (bool) $request->input('notify_purge_reminder', false),
            'notify_loa_marked'            => (bool) $request->input('notify_loa_marked', false),
            'notify_marked_for_purge'      => (bool) $request->input('notify_marked_for_purge', false),
            'notify_status_cleared'        => (bool) $request->input('notify_status_cleared', false),
            'notify_purge_personal'        => (bool) $request->input('notify_purge_personal', false),
            'notify_token_revoked'         => (bool) $request->input('notify_token_revoked', false),
            'notify_token_coverage'        => (bool) $request->input('notify_token_coverage', false),
            'notify_wallet_stalled'        => (bool) $request->input('notify_wallet_stalled', false),
            'notify_wallet_compliance_dropped' => (bool) $request->input('notify_wallet_compliance_dropped', false),
            'notify_wallet_milestone'      => (bool) $request->input('notify_wallet_milestone', false),
            'notify_member_joined'         => (bool) $request->input('notify_member_joined', false),
            'notify_member_left'           => (bool) $request->input('notify_member_left', false),
            'notify_join_no_application'   => (bool) $request->input('notify_join_no_application', false),
            'notify_member_unregistered'   => (bool) $request->input('notify_member_unregistered', false),
            'notify_onboarding_welcome'    => (bool) $request->input('notify_onboarding_welcome', false),
            'notify_flagged_applicant'     => (bool) $request->input('notify_flagged_applicant', false),
        ]);

        return $this->backToSettingsTab('webhooks')
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
            'notify_silent_wallet_director' => 'nullable|boolean',
            'notify_handler_note'          => 'nullable|boolean',
            'notify_dead_weight'           => 'nullable|boolean',
            'notify_purge_reminder'        => 'nullable|boolean',
            'notify_loa_marked'            => 'nullable|boolean',
            'notify_marked_for_purge'      => 'nullable|boolean',
            'notify_status_cleared'        => 'nullable|boolean',
            'notify_purge_personal'        => 'nullable|boolean',
            'notify_token_revoked'         => 'nullable|boolean',
            'notify_token_coverage'        => 'nullable|boolean',
            'notify_wallet_stalled'        => 'nullable|boolean',
            'notify_wallet_compliance_dropped' => 'nullable|boolean',
            'notify_wallet_milestone'      => 'nullable|boolean',
            'notify_member_joined'         => 'nullable|boolean',
            'notify_member_left'           => 'nullable|boolean',
            'notify_join_no_application'   => 'nullable|boolean',
            'notify_member_unregistered'   => 'nullable|boolean',
            'notify_onboarding_welcome'    => 'nullable|boolean',
            'notify_flagged_applicant'     => 'nullable|boolean',
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
            'notify_silent_wallet_director' => (bool) $request->input('notify_silent_wallet_director', false),
            'notify_handler_note'          => (bool) $request->input('notify_handler_note', false),
            'notify_dead_weight'           => (bool) $request->input('notify_dead_weight', false),
            'notify_purge_reminder'        => (bool) $request->input('notify_purge_reminder', false),
            'notify_loa_marked'            => (bool) $request->input('notify_loa_marked', false),
            'notify_marked_for_purge'      => (bool) $request->input('notify_marked_for_purge', false),
            'notify_status_cleared'        => (bool) $request->input('notify_status_cleared', false),
            'notify_purge_personal'        => (bool) $request->input('notify_purge_personal', false),
            'notify_token_revoked'         => (bool) $request->input('notify_token_revoked', false),
            'notify_token_coverage'        => (bool) $request->input('notify_token_coverage', false),
            'notify_wallet_stalled'        => (bool) $request->input('notify_wallet_stalled', false),
            'notify_wallet_compliance_dropped' => (bool) $request->input('notify_wallet_compliance_dropped', false),
            'notify_wallet_milestone'      => (bool) $request->input('notify_wallet_milestone', false),
            'notify_member_joined'         => (bool) $request->input('notify_member_joined', false),
            'notify_member_left'           => (bool) $request->input('notify_member_left', false),
            'notify_join_no_application'   => (bool) $request->input('notify_join_no_application', false),
            'notify_member_unregistered'   => (bool) $request->input('notify_member_unregistered', false),
            'notify_onboarding_welcome'    => (bool) $request->input('notify_onboarding_welcome', false),
            'notify_flagged_applicant'     => (bool) $request->input('notify_flagged_applicant', false),
        ]);

        return $this->backToSettingsTab('webhooks')
            ->with('success', trans('hr-manager::settings.webhook_updated'));
    }

    public function deleteWebhook(int $id)
    {
        WebhookConfiguration::findOrFail($id)->delete();
        return $this->backToSettingsTab('webhooks')
            ->with('success', trans('hr-manager::settings.webhook_deleted'));
    }

    public function testWebhook(int $id)
    {
        $webhook = WebhookConfiguration::findOrFail($id);

        try {
            $service = app(\HrManager\Services\WebhookService::class);
            $success = $service->testWebhook($webhook);
            return $this->backToSettingsTab('webhooks')
                ->with($success ? 'success' : 'error', trans('hr-manager::settings.webhook_test_' . ($success ? 'sent' : 'failed')));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[HR Manager] Webhook test failed', [
                'webhook_id' => $id, 'error' => $e->getMessage(),
            ]);
            return $this->backToSettingsTab('webhooks')
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

        return $this->backToSettingsTab('tiers')
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

        return $this->backToSettingsTab('tiers')
            ->with('success', trans('hr-manager::settings.tier_mapping_updated'));
    }

    public function deleteTierMapping(int $id)
    {
        RoleTierMapping::findOrFail($id)->delete();
        return $this->backToSettingsTab('tiers')
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

        return $this->backToSettingsTab('tiers')
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

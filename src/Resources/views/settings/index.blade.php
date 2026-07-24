@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::settings.settings'))
@section('page_header', trans('hr-manager::settings.settings'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.9">
<style>
    /* Settings layout — sidebar + content split. Mirrors the pattern
       used by Structure Manager and Mining Manager so operators see
       the same nav convention across Matt's plugin suite. */
    .hr-settings-wrapper {
        display: flex;
        gap: 20px;
    }
    .hr-settings-sidebar { flex: 0 0 250px; }
    .hr-settings-content { flex: 1; min-width: 0; }

    /* Legible scope chips for the SSO banners. A plain <code> inherits the
       alert's dark-red text colour and vanishes against the red background;
       these render a dark pill with light text so a long scope list stays
       readable. Specificity (.hr-settings-content .sso-scope-chip) beats the
       framework's .alert-danger code rule. */
    .hr-settings-content .sso-scope-chip {
        display: inline-block; margin: 2px; padding: 2px 8px;
        background: rgba(0,0,0,0.45); color: #ffe3e3;
        border: 1px solid rgba(255,255,255,0.18); border-radius: 4px;
        font-family: monospace; font-size: 0.78rem; line-height: 1.6;
        word-break: break-all;
    }

    .hr-settings-sidebar .nav-pills .nav-link {
        color: #e2e8f0;
        border-radius: 5px;
        margin-bottom: 5px;
        padding: 8px 14px;
        font-size: 0.875rem;
        line-height: 1.4;
        transition: all 0.3s;
    }
    .hr-settings-sidebar .nav-pills .nav-link:hover {
        background: rgba(102, 126, 234, 0.2);
    }
    .hr-settings-sidebar .nav-pills .nav-link.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
    }
    .hr-settings-sidebar .nav-pills .nav-link i {
        width: 20px;
        text-align: center;
        margin-right: 10px;
    }
    .hr-settings-sidebar .nav-header {
        padding: 0.5rem 1rem;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--hr-text-muted, #8b95a5);
        letter-spacing: 0.5px;
    }

    /* Toggle switches — render the Bootstrap checkboxes on this page as iOS-style
       toggles. Kept INLINE (not in the published hr-manager.css) so it applies on
       a view:clear without re-publishing assets. Scoped to .hr-settings-wrapper;
       pure CSS on the native <input>, so name/value/checked are unchanged. The
       knob is a background-image (not ::before) because Chrome doesn't render
       pseudo-elements on <input> — this way it shows in every browser. */
    .hr-settings-wrapper .form-check {
        padding-left: 0;
        min-height: 22px;
    }
    .hr-settings-wrapper .form-check-input[type="checkbox"] {
        -webkit-appearance: none;
        appearance: none;
        position: relative;
        display: inline-block;
        box-sizing: border-box;
        flex-shrink: 0;
        width: 42px;
        height: 22px;
        margin: 0 8px 0 0;
        padding: 0;
        border-radius: 22px;
        background-color: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.28);
        background-image: radial-gradient(circle 8px at center, #fff 96%, transparent 100%);
        background-repeat: no-repeat;
        background-size: 22px 22px;
        background-position: 0 center;
        vertical-align: middle;
        cursor: pointer;
        transition: background-color 0.2s ease, background-position 0.2s ease, border-color 0.2s ease;
        transform: none !important;
        box-shadow: none !important;
    }
    .hr-settings-wrapper .form-check-input[type="checkbox"]:checked {
        background-color: var(--hr-primary, #667eea);
        border-color: var(--hr-primary, #667eea);
        background-position: 20px center;
    }
    .hr-settings-wrapper .form-check-input[type="checkbox"]:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.35) !important;
    }
    .hr-settings-wrapper .form-check-input[type="checkbox"]:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    .hr-settings-wrapper .form-check-label {
        cursor: pointer;
        vertical-align: middle;
    }

    @media (max-width: 768px) {
        .hr-settings-wrapper { flex-direction: column; }
        .hr-settings-sidebar { flex: 0 0 auto; }
    }
</style>
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    {{-- Sidebar nav + content split. Matches the layout convention
         used by Structure Manager / Mining Manager for operator
         familiarity across the plugin suite. Tabs become nav-pills
         in a vertical sidebar; tab-pane content stays untouched so
         Bootstrap's data-toggle="tab" + href="#id" still drives the
         show/hide. --}}
    <div class="hr-settings-wrapper">
        <div class="hr-settings-sidebar">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-cog"></i> {{ trans('hr-manager::settings.settings_menu') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <ul class="nav nav-pills flex-column">
                        <li class="nav-header"><i class="fas fa-globe"></i> {{ trans('hr-manager::settings.nav_group_global') }}</li>
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="tab" href="#general">
                                <i class="fas fa-cog"></i> {{ trans('hr-manager::settings.general') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#features">
                                <i class="fas fa-toggle-on"></i> {{ trans('hr-manager::settings.features') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#tiers">
                                <i class="fas fa-layer-group"></i> {{ trans('hr-manager::settings.tiers_tab') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#purge-squads">
                                <i class="fas fa-user-minus"></i> {{ trans('hr-manager::settings.purge_squads_tab') }}
                            </a>
                        </li>

                        <li class="nav-header mt-2"><i class="fas fa-bullhorn"></i> {{ trans('hr-manager::settings.nav_group_recruitment') }}</li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#recruiter-access">
                                <i class="fas fa-key"></i> {{ trans('hr-manager::settings.recruiter_access_tab') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#sso">
                                <i class="fas fa-id-badge"></i> {{ trans('hr-manager::settings.sso_tab') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#assessment">
                                <i class="fas fa-user-check"></i> {{ trans('hr-manager::settings.assess_tab') }}
                            </a>
                        </li>

                        <li class="nav-header mt-2"><i class="fas fa-bell"></i> {{ trans('hr-manager::settings.nav_group_integrations') }}</li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#notifications">
                                <i class="fas fa-sliders-h"></i> {{ trans('hr-manager::settings.notifications_tab') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#webhooks">
                                <i class="fas fa-bell"></i> {{ trans('hr-manager::settings.webhooks') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#routing-map">
                                <i class="fas fa-project-diagram"></i> {{ trans('hr-manager::settings.routing_map_tab') }}
                            </a>
                        </li>
                        @if(!empty($buybackProgrammes))
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#buyback">
                                <i class="fas fa-balance-scale"></i> {{ trans('hr-manager::settings.buyback_tab') }}
                            </a>
                        </li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>

        <div class="hr-settings-content">
            <div class="card card-dark">
                <div class="card-body">
                    <div class="tab-content">

                {{-- General Tab --}}
                <div class="tab-pane active" id="general">
                    {{-- Getting-started overview: orients a new operator across the
                         tab groups and the recommended setup order. Informational. --}}
                    <div class="card card-dark mb-3" style="border-left: 4px solid var(--hr-info, #17a2b8);">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-compass"></i> {{ trans('hr-manager::settings.setup_overview_heading') }}</h3>
                        </div>
                        <div class="card-body">
                            <p style="color: var(--hr-text-light); margin-bottom: 1rem;">{{ trans('hr-manager::settings.setup_overview_intro') }}</p>
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <div style="color: var(--hr-text-white, #fff); font-weight: 600;"><i class="fas fa-globe"></i> 1 &middot; {{ trans('hr-manager::settings.nav_group_global') }}</div>
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.setup_overview_global') }}</small>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <div style="color: var(--hr-text-white, #fff); font-weight: 600;"><i class="fas fa-bullhorn"></i> 2 &middot; {{ trans('hr-manager::settings.nav_group_recruitment') }}</div>
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.setup_overview_recruitment') }}</small>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <div style="color: var(--hr-text-white, #fff); font-weight: 600;"><i class="fas fa-bell"></i> 3 &middot; {{ trans('hr-manager::settings.nav_group_integrations') }}</div>
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.setup_overview_integrations') }}</small>
                                </div>
                            </div>
                            <a href="{{ route('hr-manager.help') }}" class="btn btn-sm btn-hr-secondary btn-icon mt-2">
                                <i class="fas fa-book"></i> {{ trans('hr-manager::settings.setup_overview_help_link') }}
                            </a>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                        @csrf
                        {{-- Per-tab save marker: scopes this submit to the General
                             fields only, so it can't reset Features toggles (and
                             vice versa). --}}
                        <input type="hidden" name="general_form" value="1">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.stale_days') }}</label>
                                    <input type="number" name="stale_days" class="form-control" value="{{ $settings['stale_days'] }}" min="1" max="90">
                                    <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.stale_days_help') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.max_pending') }}</label>
                                    <input type="number" name="max_pending" class="form-control" value="{{ $settings['max_pending'] }}" min="1" max="10">
                                    <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.max_pending_help') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.cache_duration') }}</label>
                                    <input type="number" name="cache_duration" class="form-control" value="{{ $settings['cache_duration'] }}" min="5" max="1440">
                                    <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.cache_duration_help') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mt-4">
                                    <input type="checkbox" name="allow_withdrawal" value="1" class="form-check-input"
                                           id="allowWithdrawal" {{ $settings['allow_withdrawal'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="allowWithdrawal">
                                        {{ trans('hr-manager::settings.allow_withdrawal') }}
                                    </label>
                                    <br><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.allow_withdrawal_help') }}</small>
                                </div>
                                <div class="form-check mt-3">
                                    <input type="checkbox" name="withdrawal_reason_enabled" value="1" class="form-check-input"
                                           id="withdrawalReasonEnabled" {{ $settings['withdrawal_reason_enabled'] ? 'checked' : '' }}
                                           onchange="document.getElementById('withdrawalReasonRequired').disabled = !this.checked;">
                                    <label class="form-check-label" for="withdrawalReasonEnabled">
                                        {{ trans('hr-manager::settings.withdrawal_reason_enabled') }}
                                    </label>
                                    <br><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.withdrawal_reason_enabled_help') }}</small>
                                </div>
                                <div class="form-check mt-2" style="margin-left: 1.25rem;">
                                    <input type="checkbox" name="withdrawal_reason_required" value="1" class="form-check-input"
                                           id="withdrawalReasonRequired" {{ $settings['withdrawal_reason_required'] ? 'checked' : '' }}
                                           {{ $settings['withdrawal_reason_enabled'] ? '' : 'disabled' }}>
                                    <label class="form-check-label" for="withdrawalReasonRequired">
                                        {{ trans('hr-manager::settings.withdrawal_reason_required') }}
                                    </label>
                                    <br><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.withdrawal_reason_required_help') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="blacklist_accept_guard_enabled" value="1" class="form-check-input"
                                           id="blacklistAcceptGuard" {{ $settings['blacklist_accept_guard_enabled'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="blacklistAcceptGuard">
                                        {{ trans('hr-manager::settings.blacklist_accept_guard') }}
                                    </label>
                                    <br><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.blacklist_accept_guard_help') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.seat_connector_base_url') }}</label>
                                    <input type="url" name="seat_connector_base_url" class="form-control" value="{{ $settings['seat_connector_base_url'] }}" placeholder="https://seat.example.com" maxlength="255">
                                    <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.seat_connector_base_url_help') }}</small>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-hr-primary btn-icon">
                            <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                        </button>
                    </form>
                </div>

                {{-- Features Tab --}}
                <div class="tab-pane" id="features">
                    <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                        @csrf
                        {{-- Per-tab save marker (see General tab). --}}
                        <input type="hidden" name="features_form" value="1">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="enable_mining_data" value="1" class="form-check-input"
                                           id="enableMining" {{ $settings['enable_mining_data'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="enableMining">{{ trans('hr-manager::settings.enable_mining_data') }}</label>
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="enable_ratting_data" value="1" class="form-check-input"
                                           id="enableRatting" {{ $settings['enable_ratting_data'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="enableRatting">{{ trans('hr-manager::settings.enable_ratting_data') }}</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="enable_webhooks" value="1" class="form-check-input"
                                           id="enableWebhooks" {{ $settings['enable_webhooks'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="enableWebhooks">{{ trans('hr-manager::settings.enable_webhooks') }}</label>
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="enable_private_notes" value="1" class="form-check-input"
                                           id="enablePrivateNotes" {{ $settings['enable_private_notes'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="enablePrivateNotes">{{ trans('hr-manager::settings.enable_private_notes') }}</label>
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="enable_role_alignment" value="1" class="form-check-input"
                                           id="enableRoleAlignment" {{ $settings['enable_role_alignment'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="enableRoleAlignment">{{ trans('hr-manager::settings.enable_role_alignment') }}</label>
                                    <br><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.enable_role_alignment_help') }}</small>
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="enable_audit_log" value="1" class="form-check-input"
                                           id="enableAuditLog" {{ $settings['enable_audit_log'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="enableAuditLog">{{ trans('hr-manager::settings.enable_audit_log') }}</label>
                                    <br><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.enable_audit_log_help') }}</small>
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="enable_evewho_roster" value="1" class="form-check-input"
                                           id="enableEveWhoRoster" {{ $settings['enable_evewho_roster'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="enableEveWhoRoster">{{ trans('hr-manager::settings.enable_evewho_roster') }}</label>
                                    <br><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.enable_evewho_roster_help') }}</small>
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="enable_profile_prewarm" value="1" class="form-check-input"
                                           id="enableProfilePrewarm" {{ $settings['enable_profile_prewarm'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="enableProfilePrewarm">{{ trans('hr-manager::settings.enable_profile_prewarm') }}</label>
                                    <br><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.enable_profile_prewarm_help') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Security policy — token-loss workflow. Master
                             toggle + configurable purge horizon. When
                             disabled, the cron still detects token
                             revocations and records history events, but
                             doesn't trigger any automated response. --}}
                        <hr style="border-color: rgba(255,255,255,0.08); margin: 20px 0;">
                        <h4 style="color: var(--hr-text-white);">
                            <i class="fas fa-shield-alt" style="color: var(--hr-danger);"></i>
                            {{ trans('hr-manager::settings.security_policy_heading') }}
                        </h4>
                        <p style="color: var(--hr-text-muted);">
                            {{ trans('hr-manager::settings.security_policy_intro') }}
                        </p>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="security_token_loss_enabled" value="1" class="form-check-input"
                                           id="secTokenLossEnabled" {{ $settings['security_token_loss_enabled'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="secTokenLossEnabled">
                                        <strong>{{ trans('hr-manager::settings.security_token_loss_enabled') }}</strong>
                                    </label>
                                    <small class="d-block" style="color: var(--hr-text-muted);">
                                        {{ trans('hr-manager::settings.security_token_loss_enabled_help') }}
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label>{{ trans('hr-manager::settings.security_token_loss_purge_hours') }}</label>
                                    <input type="number" name="security_token_loss_purge_hours" class="form-control"
                                           value="{{ $settings['security_token_loss_purge_hours'] }}" min="0" max="720">
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.security_token_loss_purge_hours_help') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label>{{ trans('hr-manager::settings.security_token_loss_trigger_mode') }}</label>
                                    <select name="security_token_loss_trigger_mode" class="form-control">
                                        <option value="main" {{ $settings['security_token_loss_trigger_mode'] === 'main' ? 'selected' : '' }}>{{ trans('hr-manager::settings.security_token_loss_trigger_main') }}</option>
                                        <option value="any" {{ $settings['security_token_loss_trigger_mode'] === 'any' ? 'selected' : '' }}>{{ trans('hr-manager::settings.security_token_loss_trigger_any') }}</option>
                                        <option value="coverage" {{ $settings['security_token_loss_trigger_mode'] === 'coverage' ? 'selected' : '' }}>{{ trans('hr-manager::settings.security_token_loss_trigger_coverage') }}</option>
                                    </select>
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.security_token_loss_trigger_mode_help') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label>{{ trans('hr-manager::settings.security_token_loss_coverage_pct') }}</label>
                                    <select name="security_token_loss_coverage_pct" class="form-control">
                                        @foreach([25, 50, 75, 100] as $pct)
                                            <option value="{{ $pct }}" {{ (int) $settings['security_token_loss_coverage_pct'] === $pct ? 'selected' : '' }}>{{ $pct }}%</option>
                                        @endforeach
                                    </select>
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.security_token_loss_coverage_pct_help') }}</small>
                                </div>
                            </div>
                        </div>

                        {{-- Squad-drop on token loss (opt-in). HR only REMOVES
                             manual/hidden squads (Connector Discord roles cascade
                             off); they are never re-added, so a grace window gates
                             the drop and a director re-adds by hand once cleared. --}}
                        <div class="form-check mb-2 mt-2">
                            <input type="checkbox" name="security_token_loss_squad_drop_enabled" value="1" class="form-check-input"
                                   id="secSquadDropEnabled" {{ $settings['security_token_loss_squad_drop_enabled'] ? 'checked' : '' }}>
                            <label class="form-check-label" for="secSquadDropEnabled">
                                <strong>{{ trans('hr-manager::settings.security_token_loss_squad_drop_enabled') }}</strong>
                            </label>
                            <small class="d-block" style="color: var(--hr-text-muted);">
                                {{ trans('hr-manager::settings.security_token_loss_squad_drop_enabled_help') }}
                            </small>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label>{{ trans('hr-manager::settings.security_token_loss_squad_drop_hours') }}</label>
                                    <input type="number" name="security_token_loss_squad_drop_hours" class="form-control"
                                           value="{{ $settings['security_token_loss_squad_drop_hours'] }}" min="0" max="336">
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.security_token_loss_squad_drop_hours_help') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mt-4">
                                    <input type="checkbox" name="security_token_loss_squad_drop_director_immediate" value="1" class="form-check-input"
                                           id="secSquadDropDirImmediate" {{ $settings['security_token_loss_squad_drop_director_immediate'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="secSquadDropDirImmediate">
                                        {{ trans('hr-manager::settings.security_token_loss_squad_drop_director_immediate') }}
                                    </label>
                                    <small class="d-block" style="color: var(--hr-text-muted);">
                                        {{ trans('hr-manager::settings.security_token_loss_squad_drop_director_immediate_help') }}
                                    </small>
                                </div>
                            </div>
                        </div>

                        {{-- Intel database recruiter-share toggle. Lets corps
                             opt-in to letting recruiters view intel notes
                             flagged "recruiter_visible". Without this on,
                             intel is director-only regardless of per-note
                             flags. --}}
                        <hr style="border-color: rgba(255,255,255,0.08); margin: 20px 0;">
                        <h4 style="color: var(--hr-text-white);">
                            <i class="fas fa-user-secret" style="color: var(--hr-primary-start);"></i>
                            {{ trans('hr-manager::settings.intel_heading') }}
                        </h4>
                        <p style="color: var(--hr-text-muted);">
                            {{ trans('hr-manager::settings.intel_intro') }}
                        </p>
                        <div class="form-check mb-3">
                            <input type="checkbox" name="intel_recruiter_view_enabled" value="1" class="form-check-input"
                                   id="intelRecruiterView" {{ $settings['intel_recruiter_view_enabled'] ? 'checked' : '' }}>
                            <label class="form-check-label" for="intelRecruiterView">
                                <strong>{{ trans('hr-manager::settings.intel_recruiter_view_enabled') }}</strong>
                            </label>
                            <small class="d-block" style="color: var(--hr-text-muted);">
                                {{ trans('hr-manager::settings.intel_recruiter_view_enabled_help') }}
                            </small>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" name="applicant_watchlist_autoflag" value="1" class="form-check-input"
                                   id="applicantAutoflag" {{ $settings['applicant_watchlist_autoflag'] ? 'checked' : '' }}>
                            <label class="form-check-label" for="applicantAutoflag">
                                <strong>{{ trans('hr-manager::settings.applicant_autoflag') }}</strong>
                            </label>
                            <small class="d-block" style="color: var(--hr-text-muted);">
                                {{ trans('hr-manager::settings.applicant_autoflag_help') }}
                            </small>
                        </div>

                        {{-- Alliance-tax exempt members. Their corp-tax compliance
                             reads low because the corp never sees their payment;
                             listing them here suppresses the LOW/VTX compliance
                             flags on the Corp Health wallet anomaly board. --}}
                        <hr style="border-color: rgba(255,255,255,0.08); margin: 20px 0;">
                        <h4 style="color: var(--hr-text-white);">
                            <i class="fas fa-handshake" style="color: var(--hr-info, #17a2b8);"></i>
                            {{ trans('hr-manager::settings.alliance_tax_heading') }}
                        </h4>
                        <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.alliance_tax_intro') }}</p>
                        <div class="form-group" style="max-width: 560px;">
                            <label>{{ trans('hr-manager::settings.alliance_tax_label') }}</label>
                            <textarea name="alliance_tax_exempt_chars" class="form-control" rows="4"
                                      placeholder="91234567&#10;92345678">{{ old('alliance_tax_exempt_chars', $allianceTaxExemptText ?? '') }}</textarea>
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.alliance_tax_help') }}</small>
                            @if(!empty($allianceTaxExemptNames))
                                <small class="d-block mt-1" style="color: var(--hr-text-light);">
                                    <i class="fas fa-check-circle" style="color: var(--hr-success, #28a745);"></i>
                                    {{ trans('hr-manager::settings.alliance_tax_current') }}
                                    {{ implode(', ', array_map(fn($n) => $n, $allianceTaxExemptNames)) }}
                                </small>
                            @endif
                        </div>

                        {{-- Sensitive Discord roles. Operator-curated list of
                             Discord roles that grant real power (leadership,
                             directors, FCs, wallet/structure access). The
                             Discord Access Depth views highlight anyone holding
                             one. Populated from the same role registry the
                             webhook mention-picker uses (discord-pings /
                             seat-connector); empty when neither is installed. --}}
                        <hr style="border-color: rgba(255,255,255,0.08); margin: 20px 0;">
                        <h4 style="color: var(--hr-text-white);">
                            <i class="fab fa-discord" style="color: #5865F2;"></i>
                            {{ trans('hr-manager::settings.sensitive_discord_heading') }}
                        </h4>
                        <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.sensitive_discord_intro') }}</p>
                        @if(empty($discordRoles))
                            <div class="alert" style="background: rgba(88,101,242,0.08); border: 1px solid rgba(88,101,242,0.3); color: var(--hr-text-light);">
                                <i class="fas fa-info-circle"></i> {{ trans('hr-manager::settings.sensitive_discord_none') }}
                            </div>
                        @else
                            <small class="d-block mb-2" style="color: var(--hr-text-muted);">
                                {{ trans('hr-manager::settings.sensitive_discord_provider', ['provider' => $discordRolesProvider]) }}
                                &mdash; {{ trans('hr-manager::settings.sensitive_discord_count', ['count' => count($settings['sensitive_discord_roles'])]) }}
                            </small>
                            <div class="row" style="max-height: 280px; overflow-y: auto; margin: 0; padding: 8px; background: rgba(0,0,0,0.15); border-radius: 6px;">
                                @foreach($discordRoles as $role)
                                    @php $rid = (string) ($role['id'] ?? ''); @endphp
                                    @continue($rid === '')
                                    <div class="col-md-4 col-sm-6">
                                        <div class="form-check mb-2">
                                            <input type="checkbox" name="sensitive_discord_roles[]" value="{{ $rid }}"
                                                   class="form-check-input" id="sdr_{{ $rid }}"
                                                   {{ in_array($rid, $settings['sensitive_discord_roles'], true) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="sdr_{{ $rid }}" style="color: var(--hr-text-light);">
                                                @if(!empty($role['color']) && is_string($role['color']) && str_starts_with($role['color'], '#'))
                                                    <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $role['color'] }};margin-right:4px;"></span>
                                                @endif
                                                {{ $role['name'] ?? $rid }}
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <button type="submit" class="btn btn-hr-primary btn-icon">
                            <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                        </button>
                    </form>
                </div>

                {{-- Activity Tiers Tab --}}
                <div class="tab-pane" id="tiers">

                    {{-- Auto-resolution status banner --}}
                    @if($tierAuto)
                        <div class="alert" style="background: rgba(40,167,69,0.08); border: 1px solid rgba(40,167,69,0.3); color: var(--hr-text-light);">
                            <i class="fas fa-check-circle text-success"></i>
                            {{ trans('hr-manager::settings.tier_auto_available') }}
                        </div>
                    @else
                        <div class="alert" style="background: rgba(255,193,7,0.08); border: 1px solid rgba(255,193,7,0.3); color: var(--hr-text-light);">
                            <i class="fas fa-exclamation-triangle text-warning"></i>
                            {{ trans('hr-manager::settings.tier_auto_unavailable') }}
                        </div>
                    @endif

                    {{-- Personnel Manager coherence recommendation (info box) --}}
                    <div class="alert" style="background: rgba(102,126,234,0.08); border: 1px solid rgba(102,126,234,0.3); color: var(--hr-text-light);">
                        <i class="fas fa-info-circle" style="color: #667eea;"></i>
                        {{ trans('hr-manager::settings.tier_personnel_coherence_rec') }}
                    </div>

                    {{-- ==== Per-tier defaults form ==== --}}
                    <div class="card mb-3" style="background: var(--hr-dark-card); border: 1px solid var(--hr-border);">
                        <div class="card-header">
                            <h5 class="mb-0" style="color: var(--hr-text-white);">
                                <i class="fas fa-sliders-h"></i> {{ trans('hr-manager::settings.tier_defaults_heading') }}
                            </h5>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.tier_defaults_intro') }}</small>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('hr-manager.settings.tiers.defaults') }}">
                                @csrf
                                <div class="row">
                                    @foreach($tierLevels as $level)
                                        @php
                                            $slug = \HrManager\Support\TierLevel::slug($level);
                                            $key = \HrManager\Support\TierLevel::thresholdSettingKey($level);
                                            $current = $tierDefaults[$level] ?? null;
                                        @endphp
                                        <div class="col-md-2 mb-2">
                                            <div class="form-group">
                                                <label style="font-size: 0.85rem;">
                                                    <span class="badge badge-hr {{ \HrManager\Support\TierLevel::badgeClass($level) }}">
                                                        {{ \HrManager\Support\TierLevel::shortLabel($level) }}
                                                    </span>
                                                    {{ \HrManager\Support\TierLevel::label($level) }}
                                                </label>
                                                <input type="number" name="{{ $key }}" class="form-control form-control-sm"
                                                       min="1" max="3650"
                                                       value="{{ $current !== null ? $current : '' }}"
                                                       placeholder="{{ trans('hr-manager::tiers.no_threshold') }}">
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <button type="submit" class="btn btn-sm btn-hr-primary btn-icon">
                                    <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- ==== Tier mappings table ==== --}}
                    <div class="card mb-3" style="background: var(--hr-dark-card); border: 1px solid var(--hr-border);">
                        <div class="card-header">
                            <h5 class="mb-0" style="color: var(--hr-text-white);">
                                <i class="fas fa-link"></i> {{ trans('hr-manager::settings.tier_mappings_heading') }}
                            </h5>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.tier_mappings_intro') }}</small>
                        </div>
                        <div class="card-body p-0">
                            @if($tierMappings->isEmpty())
                                <p class="text-muted text-center p-3 mb-0">
                                    {{ trans('hr-manager::settings.tier_no_mappings') }}
                                </p>
                            @else
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>{{ trans('hr-manager::settings.tier_role') }}</th>
                                                <th>{{ trans('hr-manager::settings.tier_corporation') }}</th>
                                                <th>{{ trans('hr-manager::settings.tier') ?? 'Tier' }}</th>
                                                <th>{{ trans('hr-manager::settings.tier_threshold_override') }}</th>
                                                <th>{{ trans('hr-manager::settings.tier_notes') }}</th>
                                                <th>{{ trans('hr-manager::applications.actions') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($tierMappings as $mapping)
                                                {{-- Read-only display row --}}
                                                <tr id="tier-map-view-{{ $mapping->id }}">
                                                    <td>
                                                        @include('hr-manager::settings.partials._role_pill', [
                                                            'roleId'  => $mapping->discord_role_id,
                                                            'roleMap' => $discordRoleMap,
                                                        ])
                                                    </td>
                                                    <td>
                                                        @if($mapping->corporation_id)
                                                            @php
                                                                $corpName = optional($corporations->firstWhere('corporation_id', $mapping->corporation_id))->name;
                                                            @endphp
                                                            {{ $corpName ?? '#' . $mapping->corporation_id }}
                                                        @else
                                                            <em style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.tier_corporation_global') }}</em>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-hr {{ \HrManager\Support\TierLevel::badgeClass($mapping->tier_level) }}">
                                                            {{ \HrManager\Support\TierLevel::shortLabel($mapping->tier_level) }}
                                                            {{ \HrManager\Support\TierLevel::label($mapping->tier_level) }}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        @if($mapping->threshold_days)
                                                            {{ $mapping->threshold_days }} {{ trans('hr-manager::players.days') }}
                                                        @else
                                                            <em style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.tier_threshold_default_hint') }}</em>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if(!empty($mapping->notes))
                                                            <span style="color: var(--hr-text-light);">{{ $mapping->notes }}</span>
                                                        @else
                                                            <span style="color: var(--hr-text-muted);">&mdash;</span>
                                                        @endif
                                                    </td>
                                                    <td style="white-space: nowrap;">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-icon"
                                                                onclick="hrToggleTierEdit({{ $mapping->id }}, true)"
                                                                title="{{ trans('hr-manager::settings.tier_edit') }}">
                                                            <i class="fas fa-pen"></i>
                                                        </button>
                                                        <form method="POST" action="{{ route('hr-manager.settings.tiers.destroy', $mapping->id) }}"
                                                              class="d-inline"
                                                              onsubmit="return confirm(@js(trans('hr-manager::settings.confirm_delete_tier_mapping')))">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-sm btn-outline-danger btn-icon">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                {{-- Inline edit row (hidden until Edit clicked). Role + corp
                                                     are the mapping's identity, so only tier / override / notes
                                                     are editable — matching updateTierMapping. --}}
                                                <tr id="tier-map-edit-{{ $mapping->id }}" style="display: none; background: rgba(102,126,234,0.06);">
                                                    <td colspan="6">
                                                        <form method="POST" action="{{ route('hr-manager.settings.tiers.update', $mapping->id) }}"
                                                              class="d-flex flex-wrap align-items-end" style="gap: 12px;">
                                                            @csrf
                                                            @method('PUT')
                                                            <div>
                                                                <label class="d-block mb-1" style="font-size: 0.72rem; color: var(--hr-text-muted); text-transform: uppercase;">{{ trans('hr-manager::settings.tier_role') }}</label>
                                                                @include('hr-manager::settings.partials._role_pill', [
                                                                    'roleId'  => $mapping->discord_role_id,
                                                                    'roleMap' => $discordRoleMap,
                                                                ])
                                                            </div>
                                                            <div class="form-group mb-0">
                                                                <label class="d-block mb-1" style="font-size: 0.72rem; color: var(--hr-text-muted); text-transform: uppercase;">{{ trans('hr-manager::settings.tier') ?? 'Tier' }}</label>
                                                                <select name="tier_level" class="form-control form-control-sm">
                                                                    @foreach($tierLevels as $level)
                                                                        <option value="{{ $level }}" {{ (int) $mapping->tier_level === (int) $level ? 'selected' : '' }}>
                                                                            {{ \HrManager\Support\TierLevel::shortLabel($level) }} · {{ \HrManager\Support\TierLevel::label($level) }}
                                                                        </option>
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                            <div class="form-group mb-0">
                                                                <label class="d-block mb-1" style="font-size: 0.72rem; color: var(--hr-text-muted); text-transform: uppercase;">{{ trans('hr-manager::settings.tier_threshold_override') }}</label>
                                                                <input type="number" name="threshold_days" class="form-control form-control-sm" min="1" max="3650"
                                                                       value="{{ $mapping->threshold_days }}" placeholder="{{ trans('hr-manager::settings.tier_threshold_default_hint') }}" style="max-width: 150px;">
                                                            </div>
                                                            <div class="form-group mb-0" style="flex: 1 1 220px;">
                                                                <label class="d-block mb-1" style="font-size: 0.72rem; color: var(--hr-text-muted); text-transform: uppercase;">{{ trans('hr-manager::settings.tier_notes') }}</label>
                                                                <input type="text" name="notes" class="form-control form-control-sm" maxlength="500" value="{{ $mapping->notes }}">
                                                            </div>
                                                            <div class="form-group mb-0">
                                                                <button type="submit" class="btn btn-sm btn-hr-primary btn-icon">
                                                                    <i class="fas fa-save"></i> {{ trans('hr-manager::settings.tier_save') }}
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hrToggleTierEdit({{ $mapping->id }}, false)">
                                                                    {{ trans('hr-manager::settings.tier_cancel') }}
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- ==== Add mapping form ==== --}}
                    <div class="card mb-3" style="background: var(--hr-dark-card); border: 1px solid var(--hr-border);">
                        <div class="card-header">
                            <h5 class="mb-0" style="color: var(--hr-text-white);">
                                <i class="fas fa-plus"></i> {{ trans('hr-manager::settings.tier_add_mapping') }}
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('hr-manager.settings.tiers.store') }}">
                                @csrf
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>{{ trans('hr-manager::settings.tier_role') }}</label>
                                            @include('hr-manager::settings.partials._role_picker_field', [
                                                'name'       => 'discord_role_id',
                                                'id'         => 'tierMappingRoleId',
                                                'pickerId'   => 'tierMappingRolePicker',
                                                'roles'      => $discordRoles,
                                                'provider'   => $discordRolesProvider,
                                            ])
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>{{ trans('hr-manager::settings.tier_corporation') }}</label>
                                            <select name="corporation_id" class="form-control">
                                                <option value="">{{ trans('hr-manager::settings.tier_corporation_global') }}</option>
                                                @foreach(($corporations ?? []) as $corp)
                                                    <option value="{{ $corp->corporation_id }}">{{ $corp->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>{{ trans('hr-manager::tiers.tier_level') }}</label>
                                            <select name="tier_level" class="form-control" required>
                                                @foreach($tierLevels as $level)
                                                    <option value="{{ $level }}" {{ $level === \HrManager\Support\TierLevel::MEMBER ? 'selected' : '' }}>
                                                        {{ \HrManager\Support\TierLevel::shortLabel($level) }} - {{ \HrManager\Support\TierLevel::label($level) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>{{ trans('hr-manager::settings.tier_threshold_override') }}</label>
                                            <input type="number" name="threshold_days" class="form-control"
                                                   min="1" max="3650"
                                                   placeholder="{{ trans('hr-manager::settings.tier_threshold_default_hint') }}">
                                        </div>
                                    </div>
                                    <div class="col-md-9">
                                        <div class="form-group">
                                            <label>{{ trans('hr-manager::settings.tier_notes_optional') }}</label>
                                            <input type="text" name="notes" class="form-control" maxlength="500">
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-hr-primary btn-icon">
                                    <i class="fas fa-plus"></i> {{ trans('hr-manager::settings.tier_add_mapping') }}
                                </button>
                            </form>
                        </div>
                    </div>

                </div>

                {{-- Webhooks Tab --}}
                <div class="tab-pane" id="purge-squads">
                    @php $excludableSquads = array_values(array_filter($purgeSquads['all_squads'], fn ($s) => in_array($s['type'], ['manual', 'hidden'], true))); @endphp
                    <div class="alert" style="background: rgba(102,126,234,0.12); border-left: 4px solid #667eea; color: var(--hr-text-light);">
                        <strong><i class="fas fa-user-minus"></i> {{ trans('hr-manager::settings.purge_squads_heading') }}</strong>
                        <p class="mb-0 mt-1" style="font-size: 0.9rem;">{{ trans('hr-manager::settings.purge_squads_intro') }}</p>
                    </div>

                    <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                        @csrf
                        <input type="hidden" name="purge_squads_form" value="1">

                        {{-- Master toggle (opt-in; off by default) --}}
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="purge_auto_squad_removal" name="purge_auto_squad_removal" value="1" {{ $purgeSquads['enabled'] ? 'checked' : '' }}>
                                <label class="custom-control-label" for="purge_auto_squad_removal">{{ trans('hr-manager::settings.purge_squads_auto_label') }}</label>
                            </div>
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.purge_squads_auto_help') }}</small>
                        </div>

                        {{-- Safety-window timing --}}
                        <div class="form-group">
                            <label>{{ trans('hr-manager::settings.purge_squads_hours_label') }}</label>
                            <select name="purge_auto_squad_removal_hours" class="form-control" style="max-width: 360px;">
                                <option value="24" {{ $purgeSquads['hours'] === 24 ? 'selected' : '' }}>{{ trans('hr-manager::settings.purge_squads_hours_24') }}</option>
                                <option value="12" {{ $purgeSquads['hours'] === 12 ? 'selected' : '' }}>{{ trans('hr-manager::settings.purge_squads_hours_12') }}</option>
                            </select>
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.purge_squads_hours_help') }}</small>
                        </div>

                        {{-- Never-touch exclusions (manual / hidden only; auto is never removed) --}}
                        <h5 class="mt-4" style="color: var(--hr-text-white);"><i class="fas fa-lock"></i> {{ trans('hr-manager::settings.purge_squads_excl_heading') }}</h5>
                        <p style="color: var(--hr-text-muted); font-size: 0.9rem;">{{ trans('hr-manager::settings.purge_squads_excl_intro') }}</p>

                        @if(empty($excludableSquads))
                            <p style="color: var(--hr-text-muted);"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::settings.purge_squads_excl_empty') }}</p>
                        @else
                            <div style="max-height: 280px; overflow-y: auto; border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; padding: 10px 14px;">
                                @foreach($excludableSquads as $sq)
                                    <div class="custom-control custom-checkbox mb-1">
                                        <input type="checkbox" class="custom-control-input" id="excl_squad_{{ $sq['id'] }}" name="purge_squad_exclusions[]" value="{{ $sq['id'] }}" {{ in_array($sq['id'], $purgeSquads['excluded'], true) ? 'checked' : '' }}>
                                        <label class="custom-control-label" for="excl_squad_{{ $sq['id'] }}">
                                            {{ $sq['name'] }}
                                            <span class="badge" style="background: rgba(255,255,255,0.06); color: var(--hr-text-muted); font-weight: normal;">{{ $sq['type'] }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.purge_squads_excl_help') }}</small>
                        @endif

                        <button type="submit" class="btn btn-hr-primary btn-icon mt-3">
                            <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                        </button>
                    </form>
                </div>

                {{-- Consolidated Notifications console: every notification type in one
                     place with a master on/off, grouped by area. Off here = never sent
                     on any channel (on top of the per-webhook category toggles). --}}
                <div class="tab-pane" id="notifications">
                    <div class="alert" style="background: rgba(23,162,184,0.12); border: 1px solid rgba(23,162,184,0.4); color: var(--hr-text-light);">
                        <i class="fas fa-info-circle"></i> {!! trans('hr-manager::settings.notif_console_intro') !!}
                    </div>
                    @php
                        $catalogGroups = \HrManager\Services\NotificationCatalog::groups();
                        $catalogTypes  = \HrManager\Services\NotificationCatalog::types();
                        $notifFpActive = \HrManager\Services\MembershipNotificationHandler::fastPollAvailable();
                        $groupIcons = [
                            'applications' => 'fa-file-signature',
                            'membership'   => 'fa-users',
                            'security'     => 'fa-shield-alt',
                            'health'       => 'fa-heartbeat',
                            'wallet'       => 'fa-wallet',
                            'purge_tokens' => 'fa-user-slash',
                        ];
                    @endphp
                    <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                        @csrf
                        <input type="hidden" name="notifications_form" value="1">
                        @foreach($catalogGroups as $groupKey => $typeKeys)
                            <div class="card card-dark mb-3">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas {{ $groupIcons[$groupKey] ?? 'fa-bell' }}"></i>
                                        {{ trans('hr-manager::settings.notif_group_' . $groupKey) }}
                                    </h3>
                                </div>
                                <div class="card-body">
                                    @foreach($typeKeys as $tk)
                                        @php $meta = $catalogTypes[$tk] ?? null; @endphp
                                        @if($meta)
                                            <div class="d-flex align-items-start" style="gap: 12px; padding: 10px 0;{{ !$loop->last ? ' border-bottom: 1px solid rgba(255,255,255,0.05);' : '' }}">
                                                <div class="form-check" style="padding-top: 2px;">
                                                    <input type="checkbox" class="form-check-input" style="transform: scale(1.25);"
                                                           id="notif_{{ $tk }}" name="notif_{{ $tk }}" value="1"
                                                           {{ !empty($notifStates[$tk]) ? 'checked' : '' }}>
                                                </div>
                                                <label class="mb-0" for="notif_{{ $tk }}" style="flex: 1; cursor: pointer;">
                                                    <span style="color: var(--hr-text-white, #fff); font-weight: 600;">{{ trans('hr-manager::settings.' . $meta['label']) }}</span>
                                                    @if(!empty($meta['fast_poll']))
                                                        <span class="badge ml-1" style="background: {{ $notifFpActive ? 'rgba(40,167,69,0.18)' : 'rgba(108,117,125,0.18)' }}; color: {{ $notifFpActive ? '#3fb950' : '#adb5bd' }}; font-size: 0.6rem;" title="{{ $notifFpActive ? trans('hr-manager::settings.fast_poll_badge_active') : trans('hr-manager::settings.fast_poll_badge_inactive') }}">
                                                            <i class="fas fa-bolt"></i> {{ trans('hr-manager::settings.fast_poll_badge') }}
                                                        </span>
                                                    @endif
                                                    @if(!empty($meta['cadence']))
                                                        <span class="badge ml-1" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); font-size: 0.6rem;">
                                                            <i class="fas fa-redo"></i> {{ trans('hr-manager::settings.notif_recurring_badge') }}
                                                        </span>
                                                    @endif
                                                    <small class="d-block" style="color: var(--hr-text-muted); font-weight: normal;">{{ trans('hr-manager::settings.' . $meta['desc']) }}</small>
                                                </label>
                                            </div>
                                        @endif
                                    @endforeach

                                    @if($groupKey === 'wallet')
                                        {{-- The recurring wallet alerts share one cadence knob — how often HR
                                             re-pings while the condition persists. --}}
                                        <div class="mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.08);">
                                            <label style="color: var(--hr-text-white); font-weight: 600;">
                                                <i class="fas fa-redo"></i> {{ trans('hr-manager::settings.wallet_alert_repeat_label') }}
                                            </label>
                                            <select name="wallet_alert_repeat_hours" class="form-control" style="max-width: 440px;">
                                                <option value="0"   {{ $walletAlertRepeatHours === 0   ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_once') }}</option>
                                                <option value="12"  {{ $walletAlertRepeatHours === 12  ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_12h') }}</option>
                                                <option value="24"  {{ $walletAlertRepeatHours === 24  ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_24h') }}</option>
                                                <option value="72"  {{ $walletAlertRepeatHours === 72  ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_72h') }}</option>
                                                <option value="168" {{ $walletAlertRepeatHours === 168 ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_168h') }}</option>
                                            </select>
                                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.wallet_alert_repeat_help') }}</small>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        <button type="submit" class="btn btn-hr-primary btn-icon">
                            <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                        </button>
                    </form>
                </div>

                <div class="tab-pane" id="webhooks">
                    {{-- Wallet-alert cadence: how often HR re-pings for an ONGOING
                         recurring wallet condition (tax compliance dropped /
                         contributions stalled). Applies across every webhook. --}}
                    <form method="POST" action="{{ route('hr-manager.settings.update') }}" class="mb-3">
                        @csrf
                        <input type="hidden" name="wallet_alerts_form" value="1">
                        <div class="card mb-0" style="background: var(--hr-dark-card); border: 1px solid var(--hr-border);">
                            <div class="card-body">
                                <label style="color: var(--hr-text-white); font-weight: 600;">
                                    <i class="fas fa-bell"></i> {{ trans('hr-manager::settings.wallet_alert_repeat_label') }}
                                </label>
                                <select name="wallet_alert_repeat_hours" class="form-control" style="max-width: 440px;">
                                    <option value="0"   {{ $walletAlertRepeatHours === 0   ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_once') }}</option>
                                    <option value="12"  {{ $walletAlertRepeatHours === 12  ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_12h') }}</option>
                                    <option value="24"  {{ $walletAlertRepeatHours === 24  ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_24h') }}</option>
                                    <option value="72"  {{ $walletAlertRepeatHours === 72  ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_72h') }}</option>
                                    <option value="168" {{ $walletAlertRepeatHours === 168 ? 'selected' : '' }}>{{ trans('hr-manager::settings.wallet_alert_repeat_168h') }}</option>
                                </select>
                                <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.wallet_alert_repeat_help') }}</small>

                                <hr style="border-color: rgba(255,255,255,0.08); margin: 16px 0;">

                                {{-- Inactive-director alert cadence. The alert only
                                     fires once a director is actually past their
                                     inactivity threshold; this controls whether it
                                     repeats while they stay dark. --}}
                                <label style="color: var(--hr-text-white); font-weight: 600;">
                                    <i class="fas fa-user-shield"></i> {{ trans('hr-manager::settings.director_alert_repeat_label') }}
                                </label>
                                <select name="inactive_director_repeat_days" class="form-control" style="max-width: 440px;">
                                    <option value="0"  {{ $inactiveDirectorRepeatDays === 0  ? 'selected' : '' }}>{{ trans('hr-manager::settings.director_alert_repeat_once') }}</option>
                                    <option value="1"  {{ $inactiveDirectorRepeatDays === 1  ? 'selected' : '' }}>{{ trans('hr-manager::settings.director_alert_repeat_daily') }}</option>
                                    <option value="3"  {{ $inactiveDirectorRepeatDays === 3  ? 'selected' : '' }}>{{ trans('hr-manager::settings.director_alert_repeat_3d') }}</option>
                                    <option value="7"  {{ $inactiveDirectorRepeatDays === 7  ? 'selected' : '' }}>{{ trans('hr-manager::settings.director_alert_repeat_weekly') }}</option>
                                    <option value="14" {{ $inactiveDirectorRepeatDays === 14 ? 'selected' : '' }}>{{ trans('hr-manager::settings.director_alert_repeat_biweekly') }}</option>
                                    <option value="30" {{ $inactiveDirectorRepeatDays === 30 ? 'selected' : '' }}>{{ trans('hr-manager::settings.director_alert_repeat_monthly') }}</option>
                                </select>
                                <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.director_alert_repeat_help') }}</small>

                                <button type="submit" class="btn btn-hr-primary btn-icon mt-3">
                                    <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                                </button>
                            </div>
                        </div>
                    </form>

                    {{-- Fast-poll status + legend for the bolt badge that marks the
                         membership categories in the webhook forms below. --}}
                    @php
                        $fastPollActive = \HrManager\Services\MembershipNotificationHandler::fastPollAvailable();
                        $fastPollKeys   = \HrManager\Services\MembershipNotificationHandler::fastPollCategories();
                    @endphp
                    <div class="card mb-3" style="background: var(--hr-dark-card); border: 1px solid var(--hr-border);">
                        <div class="card-body" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                            <span class="badge" style="background: {{ $fastPollActive ? 'rgba(40,167,69,0.18)' : 'rgba(108,117,125,0.18)' }}; color: {{ $fastPollActive ? '#3fb950' : '#adb5bd' }};">
                                <i class="fas fa-bolt"></i>
                                {{ $fastPollActive ? trans('hr-manager::settings.fast_poll_status_active') : trans('hr-manager::settings.fast_poll_status_inactive') }}
                            </span>
                            <small style="color: var(--hr-text-muted); flex:1; min-width:220px;">{{ trans('hr-manager::settings.fast_poll_status_help') }}</small>
                        </div>
                    </div>

                    @php
                        // Notification class -> colour, so each webhook's chips read at a
                        // glance: security + critical pop, the rest grouped by area.
                        $whClassOf = [
                            'notify_flagged_applicant' => 'security', 'notify_token_revoked' => 'security',
                            'notify_join_no_application' => 'security', 'notify_member_unregistered' => 'security',
                            'notify_inactive_director' => 'critical', 'notify_wallet_compliance_dropped' => 'critical',
                            'notify_application_submitted' => 'application', 'notify_application_accepted' => 'application',
                            'notify_application_rejected' => 'application', 'notify_status_change' => 'application',
                            'notify_member_joined' => 'membership', 'notify_member_left' => 'membership',
                            'notify_wallet_stalled' => 'wallet', 'notify_wallet_milestone' => 'wallet',
                            'notify_purge_reminder' => 'lifecycle',
                            'notify_loa_marked' => 'lifecycle', 'notify_marked_for_purge' => 'lifecycle', 'notify_status_cleared' => 'lifecycle',
                            'notify_dead_weight' => 'lifecycle', 'notify_token_coverage' => 'lifecycle',
                        ];
                        $whClassStyle = [
                            'security'    => 'background: rgba(220,53,69,0.20); color:#ff9aa5; border:1px solid rgba(220,53,69,0.55);',
                            'critical'    => 'background: rgba(253,126,20,0.20); color:#ffb471; border:1px solid rgba(253,126,20,0.55);',
                            'application' => 'background: rgba(102,126,234,0.20); color:#aeb9f6; border:1px solid rgba(102,126,234,0.50);',
                            'membership'  => 'background: rgba(23,162,184,0.20); color:#6fd6e6; border:1px solid rgba(23,162,184,0.50);',
                            'wallet'      => 'background: rgba(255,193,7,0.16); color:#ffd968; border:1px solid rgba(255,193,7,0.45);',
                            'lifecycle'   => 'background: rgba(160,120,220,0.18); color:#cbaef2; border:1px solid rgba(160,120,220,0.45);',
                        ];
                        $whClassLabel = [
                            'security'    => trans('hr-manager::settings.wh_class_security'),
                            'critical'    => trans('hr-manager::settings.wh_class_critical'),
                            'application' => trans('hr-manager::settings.wh_class_application'),
                            'membership'  => trans('hr-manager::settings.wh_class_membership'),
                            'wallet'      => trans('hr-manager::settings.wh_class_wallet'),
                            'lifecycle'   => trans('hr-manager::settings.wh_class_lifecycle'),
                        ];
                    @endphp
                    @if($webhooks->isEmpty())
                        <p class="text-muted text-center">{{ trans('hr-manager::settings.no_webhooks') }}</p>
                    @else
                        {{-- Colour legend for the notification-class chips below. --}}
                        <div class="mb-2" style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.wh_legend_label') }}:</small>
                            @foreach($whClassLabel as $wcls => $wlbl)
                                <span class="badge" style="font-weight:normal; {{ $whClassStyle[$wcls] }}">{{ $wlbl }}</span>
                            @endforeach
                        </div>
                        <div class="table-responsive mb-3">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ trans('hr-manager::settings.webhook_name') }}</th>
                                        <th>{{ trans('hr-manager::settings.webhook_type') }}</th>
                                        <th>{{ trans('hr-manager::settings.webhook_corporation') }}</th>
                                        <th>{{ trans('hr-manager::settings.webhook_discord_role') }}</th>
                                        <th>{{ trans('hr-manager::settings.webhook_notifications') }}</th>
                                        <th>{{ trans('hr-manager::settings.webhook_enabled') }}</th>
                                        <th>{{ trans('hr-manager::applications.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($webhooks as $webhook)
                                        @php
                                            $whFlagKeys = ['notify_application_submitted','notify_application_accepted','notify_application_rejected','notify_status_change','notify_flagged_applicant','notify_inactive_director','notify_dead_weight','notify_purge_reminder','notify_loa_marked','notify_marked_for_purge','notify_status_cleared','notify_token_revoked','notify_token_coverage','notify_member_joined','notify_member_left','notify_join_no_application','notify_member_unregistered','notify_wallet_stalled','notify_wallet_compliance_dropped','notify_wallet_milestone'];
                                            $whOn = array_values(array_filter($whFlagKeys, fn($k) => (bool) $webhook->{$k}));
                                        @endphp
                                        <tr>
                                            <td>{{ $webhook->name }}</td>
                                            <td>{{ ucfirst($webhook->type) }}</td>
                                            <td>
                                                @if($webhook->corporation_id)
                                                    <span style="color: var(--hr-text-light);">{{ $corpNameLookup[$webhook->corporation_id] ?? ('Corp #' . $webhook->corporation_id) }}</span>
                                                @else
                                                    <span class="badge" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border:1px solid rgba(102,126,234,0.4);"><i class="fas fa-globe"></i> {{ trans('hr-manager::settings.webhook_corporation_global') }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @include('hr-manager::settings.partials._role_pill', [
                                                    'roleId'  => $webhook->discord_role_id,
                                                    'roleMap' => $discordRoleMap,
                                                ])
                                            </td>
                                            <td style="max-width: 320px;">
                                                @forelse($whOn as $k)
                                                    @php $whCls = $whClassOf[$k] ?? 'lifecycle'; @endphp
                                                    <span class="badge mb-1" style="font-weight: normal; {{ $whClassStyle[$whCls] }}">@if(in_array($k, $fastPollKeys, true))<i class="fas fa-bolt" style="opacity: 0.7;" title="{{ $fastPollActive ? trans('hr-manager::settings.fast_poll_badge_active') : trans('hr-manager::settings.fast_poll_badge_inactive') }}"></i> @endif{{ trans('hr-manager::settings.' . $k) }}</span>
                                                @empty
                                                    <span class="text-muted"><small>{{ trans('hr-manager::settings.wh_no_flags') }}</small></span>
                                                @endforelse
                                            </td>
                                            <td>
                                                <span class="badge badge-hr {{ $webhook->is_enabled ? 'badge-accepted' : 'badge-withdrawn' }}">
                                                    {{ $webhook->is_enabled ? trans('hr-manager::settings.webhook_enabled') : trans('hr-manager::settings.webhook_disabled') }}
                                                </span>
                                            </td>
                                            <td style="white-space: nowrap;">
                                                <button type="button" class="btn btn-sm btn-hr-secondary btn-icon"
                                                        data-toggle="collapse" data-target="#wh-edit-{{ $webhook->id }}"
                                                        title="{{ trans('hr-manager::settings.edit_webhook') }}">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" action="{{ route('hr-manager.settings.webhooks.test', $webhook->id) }}" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-hr-secondary btn-icon" title="{{ trans('hr-manager::settings.test_webhook') }}">
                                                        <i class="fas fa-vial"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('hr-manager.settings.webhooks.destroy', $webhook->id) }}"
                                                      class="d-inline" onsubmit="return confirm(@js(trans('hr-manager::settings.confirm_delete_webhook')))">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-icon">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        {{-- Collapsible inline edit form (posts PUT to updateWebhook). --}}
                                        <tr>
                                            <td colspan="7" class="p-0" style="border-top: 0;">
                                                <div class="collapse" id="wh-edit-{{ $webhook->id }}">
                                                    <div class="card card-dark m-2">
                                                        <div class="card-body">
                                                            <h6 style="color: var(--hr-text-white);"><i class="fas fa-edit"></i> {{ trans('hr-manager::settings.edit_webhook') }}: {{ $webhook->name }}</h6>
                                                            <form method="POST" action="{{ route('hr-manager.settings.webhooks.update', $webhook->id) }}">
                                                                @csrf
                                                                @method('PUT')
                                                                @include('hr-manager::settings.partials._webhook_form_fields', ['webhook' => $webhook, 'connectorAvailable' => $connectorAvailable])
                                                                <button type="submit" class="btn btn-hr-primary btn-icon">
                                                                    <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_webhook') }}
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <hr style="border-color: var(--hr-border);">

                    {{-- Add Webhook Form --}}
                    <h5 style="color: var(--hr-text-white);"><i class="fas fa-plus"></i> {{ trans('hr-manager::settings.add_webhook') }}</h5>
                    <form method="POST" action="{{ route('hr-manager.settings.webhooks.store') }}">
                        @csrf
                        {{-- Shared field partial — SAME source as the inline edit
                             form, so the category list can never drift between
                             Add and Edit again. `webhook => null` MUST be passed
                             explicitly: Blade includes inherit the parent scope,
                             so omitting it leaks $webhook from the edit-form
                             loop above — which pre-filled this form with that
                             webhook's values and reused its element ids (breaking
                             the role picker via duplicate DOM ids). --}}
                        @include('hr-manager::settings.partials._webhook_form_fields', ['webhook' => null, 'connectorAvailable' => $connectorAvailable])

                        <button type="submit" class="btn btn-hr-primary btn-icon">
                            <i class="fas fa-plus"></i> {{ trans('hr-manager::settings.add_webhook') }}
                        </button>
                    </form>
                </div>

                {{-- Notification Routing Map — read-only view of which
                     webhooks fire for which categories + which Discord
                     role each pings. Mirrors the SM/MM pattern. --}}
                <div class="tab-pane" id="routing-map">
                    @include('hr-manager::settings.partials._routing_map')
                </div>

                {{-- Recruiter Access — temporary SeAT role grants for
                     handlers, scoped to the applicant's character IDs.
                     Off by default; operator opts in + picks the
                     permission set + max duration. --}}
                <div class="tab-pane" id="recruiter-access">
                    <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                        @csrf
                        <input type="hidden" name="access_settings_form" value="1">

                        <div class="alert alert-info">
                            <strong><i class="fas fa-info-circle"></i> {{ trans('hr-manager::settings.access_intro_heading') }}</strong>
                            <p class="mb-1 mt-2">{{ trans('hr-manager::settings.access_intro_body') }}</p>
                            <ul class="mb-0">
                                <li>{{ trans('hr-manager::settings.access_intro_join') }}</li>
                                <li>{{ trans('hr-manager::settings.access_intro_leave') }}</li>
                                <li>{{ trans('hr-manager::settings.access_intro_close') }}</li>
                                <li>{{ trans('hr-manager::settings.access_intro_expire') }}</li>
                            </ul>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" name="recruiter_access_enabled" value="1"
                                       class="form-check-input" id="accessEnabled"
                                       {{ $accessSettings['enabled'] ? 'checked' : '' }}>
                                <label class="form-check-label" for="accessEnabled">
                                    <strong>{{ trans('hr-manager::settings.access_enabled_label') }}</strong>
                                </label>
                            </div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.access_enabled_help') }}</small>
                        </div>

                        <div class="form-group">
                            <label>{{ trans('hr-manager::settings.access_permissions_label') }}</label>
                            <small class="d-block mb-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.access_permissions_help') }}</small>
                            <div class="row">
                                @foreach($accessSettings['available_perms'] as $perm)
                                    <div class="col-md-4 col-sm-6">
                                        <div class="form-check">
                                            <input type="checkbox"
                                                   name="recruiter_access_permissions[]"
                                                   value="{{ $perm }}"
                                                   class="form-check-input"
                                                   id="perm_{{ str_replace('.', '_', $perm) }}"
                                                   {{ in_array($perm, $accessSettings['permissions'], true) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="perm_{{ str_replace('.', '_', $perm) }}">
                                                <code>{{ $perm }}</code>
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.access_max_duration_label') }}</label>
                                    <input type="number" name="recruiter_access_max_duration"
                                           class="form-control" min="1" max="30"
                                           value="{{ $accessSettings['max_duration'] }}">
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.access_max_duration_help') }}</small>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group">
                                    <div class="form-check mt-4">
                                        <input type="checkbox" name="recruiter_access_include_alts" value="1"
                                               class="form-check-input" id="accessIncludeAlts"
                                               {{ $accessSettings['include_alts'] ? 'checked' : '' }}>
                                        <label class="form-check-label" for="accessIncludeAlts">
                                            <strong>{{ trans('hr-manager::settings.access_include_alts_label') }}</strong>
                                        </label>
                                    </div>
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.access_include_alts_help') }}</small>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-hr-primary btn-icon">
                            <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                        </button>
                    </form>

                    {{-- Applicant Discord-link access (SeAT Connector). A second
                         temporary-grant feature in this tab: where the block above
                         grants RECRUITERS access to applicant data, this grants the
                         APPLICANT the seat-connector.view permission so they can reach
                         the Connector identity page and link Discord. Own form/marker
                         so saving one block never resets the other. --}}
                    <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">

                    <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                        @csrf
                        <input type="hidden" name="connector_access_form" value="1">

                        <div class="alert alert-info">
                            <strong><i class="fab fa-discord"></i> {{ trans('hr-manager::settings.connector_access_intro_heading') }}</strong>
                            <p class="mb-1 mt-2">{{ trans('hr-manager::settings.connector_access_intro_body') }}</p>
                            <ul class="mb-0">
                                <li>{{ trans('hr-manager::settings.connector_access_intro_grant') }}</li>
                                <li>{{ trans('hr-manager::settings.connector_access_intro_hold') }}</li>
                                <li>{{ trans('hr-manager::settings.connector_access_intro_revoke') }}</li>
                            </ul>
                        </div>

                        @if(!$connectorAccessSettings['connector_available'])
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::settings.connector_access_unavailable') }}
                            </div>
                        @endif

                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" name="applicant_connector_access_enabled" value="1"
                                       class="form-check-input" id="connectorAccessEnabled"
                                       {{ $connectorAccessSettings['enabled'] ? 'checked' : '' }}>
                                <label class="form-check-label" for="connectorAccessEnabled">
                                    <strong>{{ trans('hr-manager::settings.connector_access_enabled_label') }}</strong>
                                </label>
                            </div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.connector_access_enabled_help') }}</small>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.connector_access_max_duration_label') }}</label>
                                    <input type="number" name="applicant_connector_access_max_duration"
                                           class="form-control" min="1" max="180"
                                           value="{{ $connectorAccessSettings['max_duration'] }}">
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.connector_access_max_duration_help') }}</small>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.connector_access_permission_label') }}</label>
                                    <input type="text" name="applicant_connector_permission"
                                           class="form-control"
                                           value="{{ $connectorAccessSettings['permission'] }}"
                                           placeholder="{{ $connectorAccessSettings['default_permission'] }}">
                                    <small style="color: var(--hr-text-muted);">{!! trans('hr-manager::settings.connector_access_permission_help', ['default' => $connectorAccessSettings['default_permission']]) !!}</small>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-hr-primary btn-icon">
                            <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                        </button>
                    </form>
                </div>

                {{-- SSO & Scopes Tab --}}
                <div class="tab-pane" id="sso">
                    <div class="alert alert-info">
                        <strong><i class="fas fa-id-badge"></i> {{ trans('hr-manager::settings.sso_intro_heading') }}</strong>
                        <p class="mb-0 mt-2">{{ trans('hr-manager::settings.sso_intro_body') }}</p>
                    </div>

                    @if(empty($ssoProfiles))
                        <div class="alert alert-warning">
                            {{ trans('hr-manager::settings.sso_no_profiles') }}
                        </div>
                    @else
                        <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                            @csrf
                            <input type="hidden" name="sso_settings_form" value="1">

                            <div class="form-group" style="max-width: 560px;">
                                <label>{{ trans('hr-manager::settings.sso_profile_label') }}</label>
                                <select name="recruitment_sso_profile" class="form-control">
                                    <option value="">{{ trans('hr-manager::settings.sso_profile_default_option') }}</option>
                                    @foreach($ssoProfiles as $p)
                                        <option value="{{ $p->name }}" {{ (string) $ssoSelectedProfile === (string) $p->name ? 'selected' : '' }}>
                                            {{ $p->name }}@if(!empty($p->default)) ({{ trans('hr-manager::settings.sso_profile_is_default') }})@endif - {{ count($p->scopes ?? []) }} {{ trans('hr-manager::settings.sso_scopes_word') }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.sso_profile_help') }}</small>
                            </div>

                            @if($ssoAnalysis['stale'])
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    {{ trans('hr-manager::settings.sso_stale_warning', ['name' => $ssoSelectedProfile]) }}
                                </div>
                            @endif

                            @if(!empty($ssoScopesLost))
                                {{-- Downgrade risk: SeAT overwrites a token's scopes on
                                     every login (it does not merge), so an existing
                                     character logging in fresh through this profile loses
                                     any scope the profile doesn't request. --}}
                                <div class="alert alert-danger">
                                    <strong><i class="fas fa-triangle-exclamation"></i> {{ trans('hr-manager::settings.sso_downgrade_heading') }}</strong>
                                    <p class="mb-1 mt-2">{{ trans('hr-manager::settings.sso_downgrade_body') }}</p>
                                    <div style="margin: 8px 0;">
                                        @foreach($ssoScopesLost as $lost)
                                            <span class="sso-scope-chip">{{ $lost }}</span>
                                        @endforeach
                                    </div>
                                    <small>{{ trans('hr-manager::settings.sso_downgrade_fix') }}</small>
                                </div>
                            @endif

                            <button type="submit" class="btn btn-hr-primary btn-icon">
                                <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                            </button>
                        </form>

                        {{-- Sufficiency analysis for the effective profile --}}
                        <hr style="border-color: rgba(255,255,255,0.08); margin: 20px 0;">
                        <h4 style="color: var(--hr-text-white);">
                            <i class="fas fa-clipboard-check"></i> {{ trans('hr-manager::settings.sso_verify_heading') }}
                        </h4>
                        <p style="color: var(--hr-text-muted);">
                            {!! trans('hr-manager::settings.sso_verify_intro', ['profile' => '<strong style="color: var(--hr-text-white);">' . e($ssoAnalysis['profile_name'] ?? trans('hr-manager::settings.sso_profile_none')) . '</strong>' . ($ssoAnalysis['is_default'] ? ' (' . e(trans('hr-manager::settings.sso_profile_is_default')) . ')' : '')]) !!}
                        </p>

                        {{-- Outcome banners: minimal / full / broken --}}
                        @if(!$ssoAnalysis['minimal_ok'])
                            <div class="alert alert-danger">
                                <strong><i class="fas fa-times-circle"></i> {{ trans('hr-manager::settings.sso_result_broken_heading') }}</strong>
                                <p class="mb-0 mt-1">{{ trans('hr-manager::settings.sso_result_broken_body') }}</p>
                            </div>
                        @elseif(!$ssoAnalysis['full_ok'])
                            <div class="alert alert-warning">
                                <strong><i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::settings.sso_result_minimal_heading') }}</strong>
                                <p class="mb-1 mt-1">{{ trans('hr-manager::settings.sso_result_minimal_body') }}</p>
                                <ul class="mb-0">
                                    @foreach($ssoAnalysis['rows'] as $row)
                                        @if(!$row['present'] && $row['tier'] === 'recommended')
                                            <li><code>{{ $row['scope'] }}</code> - {{ $row['feature'] }}</li>
                                        @endif
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <div class="alert alert-success">
                                <strong><i class="fas fa-check-circle"></i> {{ trans('hr-manager::settings.sso_result_full_heading') }}</strong>
                                <p class="mb-0 mt-1">{{ trans('hr-manager::settings.sso_result_full_body') }}</p>
                            </div>
                        @endif

                        <div class="table-responsive">
                            <table class="table table-sm" style="color: var(--hr-text-light);">
                                <thead>
                                    <tr style="color: var(--hr-text-muted);">
                                        <th>{{ trans('hr-manager::settings.sso_col_scope') }}</th>
                                        <th>{{ trans('hr-manager::settings.sso_col_tier') }}</th>
                                        <th>{{ trans('hr-manager::settings.sso_col_unlocks') }}</th>
                                        <th class="text-center">{{ trans('hr-manager::settings.sso_col_status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($ssoAnalysis['rows'] as $row)
                                        <tr>
                                            <td><code>{{ $row['scope'] }}</code></td>
                                            <td>
                                                @if($row['tier'] === 'required')
                                                    <span class="badge badge-danger">{{ trans('hr-manager::settings.sso_tier_required') }}</span>
                                                @elseif($row['tier'] === 'optional')
                                                    <span class="badge badge-info">{{ trans('hr-manager::settings.sso_tier_optional') }}</span>
                                                @else
                                                    <span class="badge badge-secondary">{{ trans('hr-manager::settings.sso_tier_recommended') }}</span>
                                                @endif
                                            </td>
                                            <td>{{ $row['feature'] }}</td>
                                            <td class="text-center">
                                                @if($row['present'])
                                                    <span style="color: var(--hr-success, #28a745);"><i class="fas fa-check"></i></span>
                                                @elseif($row['tier'] === 'optional')
                                                    <span style="color: var(--hr-text-muted, #8b95a5);" title="Optional, not granted"><i class="fas fa-minus"></i></span>
                                                @else
                                                    <span style="color: var(--hr-danger, #dc3545);"><i class="fas fa-times"></i></span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <p style="color: var(--hr-text-muted);">
                            <i class="fas fa-info-circle"></i>
                            @if(\Illuminate\Support\Facades\Route::has('seatcore::configuration.sso'))
                                {!! trans('hr-manager::settings.sso_edit_pointer_link', ['url' => route('seatcore::configuration.sso')]) !!}
                            @else
                                {{ trans('hr-manager::settings.sso_edit_pointer') }}
                            @endif
                        </p>

                        {{-- Member token requirement: which SSO profile a member's
                             token must satisfy. Drives the Members token badge +
                             the Corp Health token-coverage card. --}}
                        <hr style="border-color: rgba(255,255,255,0.08); margin: 24px 0;">
                        <h4 style="color: var(--hr-text-white);">
                            <i class="fas fa-user-shield"></i> {{ trans('hr-manager::settings.token_req_heading') }}
                        </h4>
                        <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.token_req_intro') }}</p>

                        <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                            @csrf
                            <input type="hidden" name="token_req_form" value="1">
                            <div class="form-group" style="max-width: 560px;">
                                <label>{{ trans('hr-manager::settings.token_req_profile_label') }}</label>
                                <select name="token_required_profile" class="form-control">
                                    <option value="">{{ trans('hr-manager::settings.token_req_profile_none') }}</option>
                                    @foreach($ssoProfiles as $p)
                                        <option value="{{ $p->name }}" {{ (string) $tokenRequiredProfile === (string) $p->name ? 'selected' : '' }}>
                                            {{ $p->name }}@if(!empty($p->default)) ({{ trans('hr-manager::settings.sso_profile_is_default') }})@endif - {{ count($p->scopes ?? []) }} {{ trans('hr-manager::settings.sso_scopes_word') }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.token_req_profile_help') }}</small>
                            </div>

                            @if($tokenReqStale)
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    {{ trans('hr-manager::settings.token_req_stale', ['name' => $tokenRequiredProfile]) }}
                                </div>
                            @endif

                            @if(!empty($tokenRequiredScopes))
                                <p style="color: var(--hr-text-muted); margin-bottom: 6px;">{{ trans('hr-manager::settings.token_req_scopes_label') }}</p>
                                <div style="margin-bottom: 12px;">
                                    @foreach($tokenRequiredScopes as $s)
                                        <span class="sso-scope-chip">{{ $s }}</span>
                                    @endforeach
                                </div>
                            @endif

                            <button type="submit" class="btn btn-hr-primary btn-icon">
                                <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                            </button>
                        </form>
                    @endif
                </div>

                {{-- Assessment Criteria Tab --}}
                <div class="tab-pane" id="assessment">
                    <div class="alert" style="background: rgba(102,126,234,0.12); border-left: 4px solid #667eea; color: var(--hr-text-light);">
                        <strong><i class="fas fa-user-check"></i> {{ trans('hr-manager::settings.assess_settings_heading') }}</strong>
                        <p class="mb-0 mt-1" style="font-size: 0.9rem;">{{ trans('hr-manager::settings.assess_settings_intro') }}</p>
                    </div>

                    <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                        @csrf
                        <input type="hidden" name="assessment_criteria_form" value="1">

                        <h5 style="color: var(--hr-text-white);"><i class="fas fa-building"></i> {{ trans('hr-manager::settings.assess_group_corp') }}</h5>
                        <div class="form-group">
                            <label>{{ trans('hr-manager::settings.assess_crit_hopper') }}</label>
                            <input type="number" name="assess_hopper_corps_12mo" class="form-control" min="1" max="50"
                                   value="{{ $assessmentCriteria['assess_hopper_corps_12mo'] }}">
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.assess_crit_hopper_help') }} ({{ trans('hr-manager::settings.assess_crit_default') }} {{ $assessmentDefaults['assess_hopper_corps_12mo'] }})</small>
                        </div>
                        <div class="form-group">
                            <label>{{ trans('hr-manager::settings.assess_crit_avg_tenure') }}</label>
                            <input type="number" name="assess_min_avg_tenure_days" class="form-control" min="1" max="3650"
                                   value="{{ $assessmentCriteria['assess_min_avg_tenure_days'] }}">
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.assess_crit_avg_tenure_help') }} ({{ trans('hr-manager::settings.assess_crit_default') }} {{ $assessmentDefaults['assess_min_avg_tenure_days'] }})</small>
                        </div>
                        <div class="form-group">
                            <label>{{ trans('hr-manager::settings.assess_crit_npc_park') }}</label>
                            <input type="number" name="assess_npc_park_days" class="form-control" min="1" max="3650"
                                   value="{{ $assessmentCriteria['assess_npc_park_days'] }}">
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.assess_crit_npc_park_help') }} ({{ trans('hr-manager::settings.assess_crit_default') }} {{ $assessmentDefaults['assess_npc_park_days'] }})</small>
                        </div>

                        <h5 class="mt-4" style="color: var(--hr-text-white);"><i class="fas fa-user"></i> {{ trans('hr-manager::settings.assess_group_character') }}</h5>
                        <div class="form-group">
                            <label>{{ trans('hr-manager::settings.assess_crit_min_age') }}</label>
                            <input type="number" name="assess_min_age_days" class="form-control" min="0" max="3650"
                                   value="{{ $assessmentCriteria['assess_min_age_days'] }}">
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.assess_crit_min_age_help') }} ({{ trans('hr-manager::settings.assess_crit_default') }} {{ $assessmentDefaults['assess_min_age_days'] }})</small>
                        </div>
                        <div class="form-group">
                            <label>{{ trans('hr-manager::settings.assess_crit_min_sp') }}</label>
                            <input type="number" name="assess_min_sp" class="form-control" min="0" max="1000000000" step="100000"
                                   value="{{ $assessmentCriteria['assess_min_sp'] }}">
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.assess_crit_min_sp_help') }} ({{ trans('hr-manager::settings.assess_crit_default') }} {{ number_format($assessmentDefaults['assess_min_sp']) }})</small>
                        </div>
                        <div class="form-group">
                            <label>{{ trans('hr-manager::settings.assess_crit_sec_floor') }}</label>
                            <input type="number" name="assess_sec_floor" class="form-control" min="-10" max="5" step="0.1"
                                   value="{{ $assessmentCriteria['assess_sec_floor'] }}">
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.assess_crit_sec_floor_help') }} ({{ trans('hr-manager::settings.assess_crit_default') }} {{ $assessmentDefaults['assess_sec_floor'] }})</small>
                        </div>

                        <button type="submit" class="btn btn-hr-primary btn-icon">
                            <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                        </button>
                    </form>

                    {{-- Standings reference (spy / opsec) ------------------- --}}
                    <hr style="border-color: rgba(255,255,255,0.08); margin: 26px 0;">
                    <h5 style="color: var(--hr-text-white);"><i class="fas fa-handshake-slash"></i> {{ trans('hr-manager::settings.assess_std_heading') }}</h5>
                    <p style="color: var(--hr-text-muted); font-size: 0.9rem;">{{ trans('hr-manager::settings.assess_std_intro') }}</p>

                    <form method="POST" action="{{ route('hr-manager.settings.update') }}">
                        @csrf
                        <input type="hidden" name="assessment_standings_form" value="1">

                        <div class="form-group">
                            <label>{{ trans('hr-manager::settings.assess_std_source') }}</label>
                            <select name="assess_standings_source" class="form-control" id="assess-std-source">
                                <option value="off"  {{ $standingsSettings['source'] === 'off' ? 'selected' : '' }}>{{ trans('hr-manager::settings.assess_std_source_off') }}</option>
                                <option value="seat" {{ $standingsSettings['source'] === 'seat' ? 'selected' : '' }} {{ $standingsSettings['seat_available'] ? '' : 'disabled' }}>{{ trans('hr-manager::settings.assess_std_source_seat') }}@unless($standingsSettings['seat_available']) ({{ trans('hr-manager::settings.assess_std_seat_unavailable') }})@endunless</option>
                                <option value="own"  {{ $standingsSettings['source'] === 'own' ? 'selected' : '' }}>{{ trans('hr-manager::settings.assess_std_source_own') }}</option>
                            </select>
                        </div>

                        <div class="form-group" data-std-source="seat">
                            <label>{{ trans('hr-manager::settings.assess_std_seat_profile') }}</label>
                            <select name="assess_standings_seat_profile" class="form-control">
                                <option value="0">{{ trans('hr-manager::settings.assess_std_seat_profile_none') }}</option>
                                @foreach($standingsSettings['seat_profiles'] as $p)
                                    <option value="{{ $p->id }}" {{ (int) $standingsSettings['seat_profile'] === (int) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                                @endforeach
                            </select>
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.assess_std_seat_profile_help') }}</small>
                        </div>

                        <div data-std-source="own">
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label>{{ trans('hr-manager::settings.assess_std_hostile_alliances') }}</label>
                                    <textarea name="assess_hostile_alliances" class="form-control" rows="3" placeholder="99000001&#10;99000002">{{ $standingsSettings['hostile_alliances'] }}</textarea>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label>{{ trans('hr-manager::settings.assess_std_hostile_corps') }}</label>
                                    <textarea name="assess_hostile_corps" class="form-control" rows="3" placeholder="98000001">{{ $standingsSettings['hostile_corps'] }}</textarea>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label>{{ trans('hr-manager::settings.assess_std_friendly_alliances') }}</label>
                                    <textarea name="assess_friendly_alliances" class="form-control" rows="3">{{ $standingsSettings['friendly_alliances'] }}</textarea>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label>{{ trans('hr-manager::settings.assess_std_friendly_corps') }}</label>
                                    <textarea name="assess_friendly_corps" class="form-control" rows="3">{{ $standingsSettings['friendly_corps'] }}</textarea>
                                </div>
                            </div>
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.assess_std_ids_help') }}</small>
                        </div>

                        <div class="form-group mt-3">
                            <label>{{ trans('hr-manager::settings.assess_std_precedence') }}</label>
                            <select name="assess_standings_precedence" class="form-control">
                                <option value="corp"     {{ $standingsSettings['precedence'] === 'corp' ? 'selected' : '' }}>{{ trans('hr-manager::settings.assess_std_precedence_corp') }}</option>
                                <option value="alliance" {{ $standingsSettings['precedence'] === 'alliance' ? 'selected' : '' }}>{{ trans('hr-manager::settings.assess_std_precedence_alliance') }}</option>
                            </select>
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::settings.assess_std_precedence_help') }}</small>
                        </div>

                        <button type="submit" class="btn btn-hr-primary btn-icon">
                            <i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}
                        </button>
                    </form>

                    <script>
                    (function () {
                        var sel = document.getElementById('assess-std-source');
                        if (!sel) return;
                        function sync() {
                            var v = sel.value;
                            document.querySelectorAll('[data-std-source]').forEach(function (el) {
                                el.style.display = (el.getAttribute('data-std-source') === v) ? '' : 'none';
                            });
                        }
                        sel.addEventListener('change', sync);
                        sync();
                    })();
                    </script>
                </div>

                {{-- Buyback Contribution — per-corp valuation policy. Only
                     rendered when HR has seen a buyback programme. --}}
                @if(!empty($buybackProgrammes))
                <div class="tab-pane" id="buyback">
                    <h4 class="mb-1">{{ trans('hr-manager::settings.buyback_tab') }}</h4>
                    <p class="text-muted" style="font-size: 0.85rem;">{{ trans('hr-manager::settings.buyback_intro') }}</p>

                    @foreach($buybackProgrammes as $prog)
                        @php
                            $pol = $prog['policy'];
                            $attr = (int) $pol['attributed_corporation_id'];
                            $isSelf = $attr === (int) $prog['corporation_id'];
                            $tt = $prog['observed_target_type'];
                            $targetCorpName = $prog['target_corporation_id']
                                ? ($corpNameLookup[$prog['target_corporation_id']] ?? ('Corp #' . $prog['target_corporation_id']))
                                : null;
                            $targetLabel = $tt === 'corp'
                                ? trans('hr-manager::settings.buyback_target_corp', ['corp' => $targetCorpName ?? '?'])
                                : ($tt === 'player'
                                    ? trans('hr-manager::settings.buyback_target_player')
                                    : ($tt === 'my_corp'
                                        ? trans('hr-manager::settings.buyback_target_my_corp')
                                        : trans('hr-manager::settings.buyback_target_unknown')));
                        @endphp
                        <div class="card card-dark mb-2">
                            <div class="card-body" style="padding: 0.8rem 1rem;">
                                <form method="POST" action="{{ route('hr-manager.settings.buyback.policy') }}">
                                    @csrf
                                    <input type="hidden" name="corporation_id" value="{{ $prog['corporation_id'] }}">

                                    <div class="d-flex flex-wrap align-items-center" style="gap: 8px; margin-bottom: 8px;">
                                        <strong style="color: var(--hr-text-white);">{{ $prog['corporation_name'] }}</strong>
                                        <span class="badge badge-secondary">{{ $targetLabel }}</span>
                                        @if($pol['is_default'])
                                            <span class="badge" style="background: var(--hr-warning, #ffc107); color: #1a1a1a;">{{ trans('hr-manager::settings.buyback_default_review') }}</span>
                                        @endif
                                        <span class="text-muted" style="font-size: 0.78rem;">
                                            {{ trans('hr-manager::settings.buyback_counts', ['o' => $prog['offers_count'], 'c' => $prog['completed_count']]) }}
                                        </span>
                                    </div>

                                    <div class="form-row align-items-end">
                                        <div class="col-auto">
                                            <div class="form-check mb-1">
                                                <input type="checkbox" class="form-check-input" name="counted" value="1" id="bbcount{{ $prog['corporation_id'] }}" {{ $pol['counted'] ? 'checked' : '' }}>
                                                <label class="form-check-label" for="bbcount{{ $prog['corporation_id'] }}">{{ trans('hr-manager::settings.buyback_counted') }}</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <label style="font-size: 0.74rem;">{{ trans('hr-manager::settings.buyback_tier') }}</label>
                                            <select name="tier" class="form-control form-control-sm">
                                                @foreach($buybackTiers as $t)
                                                    <option value="{{ $t }}" {{ $pol['tier'] === $t ? 'selected' : '' }}>{{ trans('hr-manager::settings.buyback_tier_' . $t) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label style="font-size: 0.74rem;">{{ trans('hr-manager::settings.buyback_weight') }}</label>
                                            <input type="number" step="0.05" min="0" max="100" name="weight" class="form-control form-control-sm" value="{{ rtrim(rtrim(number_format($pol['weight'], 2, '.', ''), '0'), '.') }}">
                                        </div>
                                        <div class="col-md-4">
                                            <label style="font-size: 0.74rem;">{{ trans('hr-manager::settings.buyback_attributes_to') }}</label>
                                            <select name="attributed_corporation_id" class="form-control form-control-sm">
                                                <option value="" {{ $isSelf ? 'selected' : '' }}>{{ trans('hr-manager::settings.buyback_self', ['corp' => $prog['corporation_name']]) }}</option>
                                                @foreach($corporations as $c)
                                                    <option value="{{ $c->corporation_id }}" {{ (!$isSelf && $attr === (int) $c->corporation_id) ? 'selected' : '' }}>{{ $c->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <div class="mt-2 d-flex flex-wrap align-items-center" style="gap: 12px;">
                                        <div class="form-check mb-0">
                                            <input type="checkbox" class="form-check-input" name="apply_to_history" value="1" id="bbapply{{ $prog['corporation_id'] }}" {{ $pol['is_default'] ? 'checked' : '' }}>
                                            <label class="form-check-label" for="bbapply{{ $prog['corporation_id'] }}" style="font-size: 0.8rem;">{{ trans('hr-manager::settings.buyback_apply_history') }}</label>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-hr-primary btn-icon"><i class="fas fa-save"></i> {{ trans('hr-manager::settings.save_settings') }}</button>
                                    </div>
                                    <small class="form-text" style="color: var(--hr-text-muted); font-size: 0.72rem;">
                                        {{ trans('hr-manager::settings.buyback_apply_history_help') }}
                                        @if(($prog['frozen_count'] ?? 0) > 0)
                                            &nbsp;<i class="fas fa-lock" style="opacity: 0.7;"></i> {{ trans('hr-manager::settings.buyback_frozen_state', ['frozen' => $prog['frozen_count'], 'live' => $prog['live_count'] ?? 0]) }}
                                        @endif
                                    </small>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
                @endif

                    </div>{{-- /.tab-content --}}
                </div>{{-- /.card-body --}}
            </div>{{-- /.card.card-dark (settings content) --}}
        </div>{{-- /.hr-settings-content --}}
    </div>{{-- /.hr-settings-wrapper --}}

</div>

{{-- Global tab restore from the URL hash. The post-save redirects append
     #features / #recruiter-access / #sso so the operator stays on the
     tab they saved from. --}}
<script>
window.addEventListener('load', function () {
    if (window.location.hash && window.jQuery) {
        var link = document.querySelector('.nav-link[href="' + window.location.hash + '"]');
        if (link) { window.jQuery(link).tab('show'); }
    }
});

// Inline edit toggle for the tier-mapping table: swap a row's read-only
// display for its edit form (and back on Cancel), no page reload needed.
function hrToggleTierEdit(id, editing) {
    var view = document.getElementById('tier-map-view-' + id);
    var edit = document.getElementById('tier-map-edit-' + id);
    if (!view || !edit) { return; }
    view.style.display = editing ? 'none' : '';
    edit.style.display = editing ? '' : 'none';
}
</script>
@endsection
{{-- Role picker JS now lives inside the _role_picker_field partial (guarded
     by @once so it renders exactly once no matter how many pickers the
     page hosts). Add picker instances anywhere in the suite by including
     the partial; no per-page wiring required. --}}

@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::settings.settings'))
@section('page_header', trans('hr-manager::settings.settings'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.8">
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
                            <a class="nav-link" data-toggle="tab" href="#webhooks">
                                <i class="fas fa-bell"></i> {{ trans('hr-manager::settings.webhooks') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#routing-map">
                                <i class="fas fa-project-diagram"></i> {{ trans('hr-manager::settings.routing_map_tab') }}
                            </a>
                        </li>
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
                                                <th>{{ trans('hr-manager::applications.actions') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($tierMappings as $mapping)
                                                <tr>
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

                <div class="tab-pane" id="webhooks">
                    @if($webhooks->isEmpty())
                        <p class="text-muted text-center">{{ trans('hr-manager::settings.no_webhooks') }}</p>
                    @else
                        <div class="table-responsive mb-3">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ trans('hr-manager::settings.webhook_name') }}</th>
                                        <th>{{ trans('hr-manager::settings.webhook_type') }}</th>
                                        <th>{{ trans('hr-manager::settings.webhook_discord_role') }}</th>
                                        <th>{{ trans('hr-manager::settings.webhook_notifications') }}</th>
                                        <th>{{ trans('hr-manager::settings.webhook_enabled') }}</th>
                                        <th>{{ trans('hr-manager::applications.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($webhooks as $webhook)
                                        @php
                                            $whFlagKeys = ['notify_application_submitted','notify_application_accepted','notify_application_rejected','notify_status_change','notify_inactive_director','notify_dead_weight','notify_purge_reminder','notify_player_status','notify_wallet_stalled','notify_wallet_compliance_dropped','notify_wallet_milestone'];
                                            $whOn = array_values(array_filter($whFlagKeys, fn($k) => (bool) $webhook->{$k}));
                                        @endphp
                                        <tr>
                                            <td>{{ $webhook->name }}</td>
                                            <td>{{ ucfirst($webhook->type) }}</td>
                                            <td>
                                                @include('hr-manager::settings.partials._role_pill', [
                                                    'roleId'  => $webhook->discord_role_id,
                                                    'roleMap' => $discordRoleMap,
                                                ])
                                            </td>
                                            <td style="max-width: 320px;">
                                                @forelse($whOn as $k)
                                                    <span class="badge badge-secondary mb-1" style="font-weight: normal;">{{ trans('hr-manager::settings.' . $k) }}</span>
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
                                            <td colspan="6" class="p-0" style="border-top: 0;">
                                                <div class="collapse" id="wh-edit-{{ $webhook->id }}">
                                                    <div class="card card-dark m-2">
                                                        <div class="card-body">
                                                            <h6 style="color: var(--hr-text-white);"><i class="fas fa-edit"></i> {{ trans('hr-manager::settings.edit_webhook') }}: {{ $webhook->name }}</h6>
                                                            <form method="POST" action="{{ route('hr-manager.settings.webhooks.update', $webhook->id) }}">
                                                                @csrf
                                                                @method('PUT')
                                                                @include('hr-manager::settings.partials._webhook_form_fields', ['webhook' => $webhook])
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
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.webhook_name') }}</label>
                                    <input type="text" name="name" class="form-control" required maxlength="255">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.webhook_type') }}</label>
                                    <select name="type" class="form-control">
                                        <option value="discord">Discord</option>
                                        <option value="slack">Slack</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.webhook_corporation') }}</label>
                                    <select name="corporation_id" class="form-control">
                                        <option value="">{{ trans('hr-manager::settings.webhook_corporation_global') }}</option>
                                        @foreach(($corporations ?? []) as $corp)
                                            <option value="{{ $corp->corporation_id }}">{{ $corp->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.webhook_url') }}</label>
                                    <input type="url" name="webhook_url" class="form-control" required maxlength="2048" placeholder="https://discord.com/api/webhooks/...">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::settings.webhook_discord_role') }}</label>
                                    @include('hr-manager::settings.partials._role_picker_field', [
                                        'name'       => 'discord_role_id',
                                        'id'         => 'webhookRoleIdInput',
                                        'pickerId'   => 'webhookRolePicker',
                                        'roles'      => $discordRoles,
                                        'provider'   => $discordRolesProvider,
                                        'helpText'   => trans('hr-manager::settings.webhook_discord_role_help'),
                                    ])
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_application_submitted" value="1" class="form-check-input" id="notifySubmitted" checked>
                                    <label class="form-check-label" for="notifySubmitted">{{ trans('hr-manager::settings.notify_application_submitted') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_application_accepted" value="1" class="form-check-input" id="notifyAccepted" checked>
                                    <label class="form-check-label" for="notifyAccepted">{{ trans('hr-manager::settings.notify_application_accepted') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_application_rejected" value="1" class="form-check-input" id="notifyRejected">
                                    <label class="form-check-label" for="notifyRejected">{{ trans('hr-manager::settings.notify_application_rejected') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_status_change" value="1" class="form-check-input" id="notifyStatus" checked>
                                    <label class="form-check-label" for="notifyStatus">{{ trans('hr-manager::settings.notify_status_change') }}</label>
                                </div>
                            </div>
                        </div>

                        {{-- Director / classifier / purge notifications --}}
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_inactive_director" value="1" class="form-check-input" id="notifyInactiveDirector" checked>
                                    <label class="form-check-label" for="notifyInactiveDirector">{{ trans('hr-manager::settings.notify_inactive_director') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_dead_weight" value="1" class="form-check-input" id="notifyDeadWeight">
                                    <label class="form-check-label" for="notifyDeadWeight">{{ trans('hr-manager::settings.notify_dead_weight') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_purge_reminder" value="1" class="form-check-input" id="notifyPurgeReminder" checked>
                                    <label class="form-check-label" for="notifyPurgeReminder">{{ trans('hr-manager::settings.notify_purge_reminder') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_player_status" value="1" class="form-check-input" id="notifyPlayerStatus" checked>
                                    <label class="form-check-label" for="notifyPlayerStatus">{{ trans('hr-manager::settings.notify_player_status') }}</label>
                                </div>
                            </div>
                        </div>

                        {{-- CWM wallet-signal notifications (Round-2 CWM
                             integration). Default OFF — operators opt in
                             per webhook so the firehose can be muted on
                             general-purpose channels. --}}
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_wallet_stalled" value="1" class="form-check-input" id="notifyWalletStalled">
                                    <label class="form-check-label" for="notifyWalletStalled">{{ trans('hr-manager::settings.notify_wallet_stalled') }}</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_wallet_compliance_dropped" value="1" class="form-check-input" id="notifyWalletComplianceDropped">
                                    <label class="form-check-label" for="notifyWalletComplianceDropped">{{ trans('hr-manager::settings.notify_wallet_compliance_dropped') }}</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input type="checkbox" name="notify_wallet_milestone" value="1" class="form-check-input" id="notifyWalletMilestone">
                                    <label class="form-check-label" for="notifyWalletMilestone">{{ trans('hr-manager::settings.notify_wallet_milestone') }}</label>
                                </div>
                            </div>
                        </div>

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
</script>
@endsection
{{-- Role picker JS now lives inside the _role_picker_field partial (guarded
     by @once so it renders exactly once no matter how many pickers the
     page hosts). Add picker instances anywhere in the suite by including
     the partial; no per-page wiring required. --}}

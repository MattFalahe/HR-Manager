@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::corp-health.corp_health'))
@section('page_header', trans('hr-manager::corp-health.corp_health'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.3">
<style>
    /* Corp Health uses the canonical AdminLTE .card-tabs structure (matches
       CWM / Mining Manager). The .hr-corp-tabs hook class makes HR-specific
       style overrides easy to pinpoint. Base dark tab colours come from
       hr-manager.css (.hr-manager-wrapper .nav-tabs). Keep this block thin. */
    .hr-manager-wrapper .hr-corp-tabs > .card-header { background: transparent; border-bottom: none; }
    .hr-manager-wrapper .hr-corp-tabs .nav-tabs { padding: 0 6px; }
    .hr-manager-wrapper .hr-corp-tabs .nav-tabs .nav-link { padding: 10px 16px; }
    .hr-manager-wrapper .hr-corp-tabs .nav-tabs .nav-link i { margin-right: 6px; }
    /* Collapsible structure-compliance cards (native details element) */
    .hr-manager-wrapper details.sc-structure > summary.sc-summary { list-style: none; }
    .hr-manager-wrapper details.sc-structure > summary.sc-summary::-webkit-details-marker { display: none; }
    /* AdminLTE's .card-header adds a clearfix ::after; inside our flex summary it
       becomes a phantom third flex item that space-between pushes the status
       badge in front of (badge floats mid-row instead of flush right). Drop it
       so the badge sits hard right, matching the Structure Manager page. */
    .hr-manager-wrapper details.sc-structure > summary.sc-summary::after { display: none; }
    .hr-manager-wrapper details.sc-structure[open] > summary .sc-chevron { transform: rotate(90deg); }
    .hr-manager-wrapper details.sc-structure .sc-chevron { transition: transform 0.12s ease; }
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

    <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.corp_health_intro') }}</p>

    @if($corporations->count() > 1)
        <div class="mb-3">
            <form method="GET" action="{{ route('hr-manager.corp-health.index') }}" class="form-inline" style="gap: 8px;">
                <label class="mb-0 mr-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.corporation_context') }}:</label>
                <select name="corporation_id" class="form-control form-control-sm" style="min-width: 320px;" onchange="this.form.submit()">
                    @foreach($corporations as $corp)
                        <option value="{{ $corp->corporation_id }}" {{ (int) $corp->corporation_id === (int) $corporationId ? 'selected' : '' }}>
                            @if(!empty($corp->ticker))[{{ $corp->ticker }}] @endif{{ $corp->name }}
                        </option>
                    @endforeach
                </select>
                {{-- Preserve the active tab when switching corp --}}
                <input type="hidden" name="ch_tab" value="{{ $activeTab }}">
                <noscript><button type="submit" class="btn btn-sm btn-hr-primary ml-2">Go</button></noscript>
            </form>
        </div>
    @endif

    {{-- ============================================================
         Canonical AdminLTE card-tabs (matches CWM / Mining Manager). The
         hr-corp-tabs hook class is HR-specific so style overrides are easy
         to pinpoint. Each link reloads with ?ch_tab=X so only that tab's
         sections build server-side (lazy). Economy is director-only.
         ============================================================ --}}
    @php
        $tabLink = fn($tab) => route('hr-manager.corp-health.index', array_filter([
            'ch_tab' => $tab,
            'corporation_id' => $corporationId,
        ]));
    @endphp
    <div class="card card-dark card-tabs hr-corp-tabs">
        <div class="card-header p-0 pt-1">
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'overview' ? 'active' : '' }}" href="{{ $tabLink('overview') }}">
                        <i class="fas fa-heartbeat"></i> {{ trans('hr-manager::corp-health.tab_overview') }}
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'composition' ? 'active' : '' }}" href="{{ $tabLink('composition') }}">
                        <i class="fas fa-users-cog"></i> {{ trans('hr-manager::corp-health.tab_composition') }}
                    </a>
                </li>
                @can('hr-manager.director')
                    <li class="nav-item">
                        <a class="nav-link {{ $activeTab === 'economy' ? 'active' : '' }}" href="{{ $tabLink('economy') }}">
                            <i class="fas fa-coins"></i> {{ trans('hr-manager::corp-health.tab_economy') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ $activeTab === 'structure-compliance' ? 'active' : '' }}" href="{{ $tabLink('structure-compliance') }}">
                            <i class="fas fa-building"></i> {{ trans('hr-manager::corp-health.tab_structure_compliance') }}
                        </a>
                    </li>
                @endcan
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'recruitment' ? 'active' : '' }}" href="{{ $tabLink('recruitment') }}">
                        <i class="fas fa-bullhorn"></i> {{ trans('hr-manager::corp-health.tab_recruitment') }}
                    </a>
                </li>
                @can('hr-manager.director')
                    <li class="nav-item">
                        <a class="nav-link {{ $activeTab === 'purge' ? 'active' : '' }}" href="{{ $tabLink('purge') }}">
                            <i class="fas fa-user-slash"></i> {{ trans('hr-manager::corp-health.tab_purge') }}
                        </a>
                    </li>
                @endcan
            </ul>
        </div>
        <div class="card-body">

    {{-- =================================================================
         OVERVIEW TAB — the at-a-glance health dashboard.
         ================================================================= --}}
    @if($activeTab === 'overview')
        @php $ov = $corpStatus['overview']; @endphp

        {{-- Overview headline cards --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-flag"></i>
                    {{ $ov['corp_name'] ?? ('#' . $corporationId) }}
                    @if($ov['ticker']) <code style="font-size: 0.85rem;">[{{ $ov['ticker'] }}]</code> @endif
                </h3>
                <div class="card-tools">
                    @if($ov['ceo_name'])
                        <small style="color: var(--hr-text-muted);">CEO: <strong>{{ $ov['ceo_name'] }}</strong></small>
                    @endif
                </div>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-2 col-6 mb-2">
                        <div style="font-size: 1.8rem; color: var(--hr-text-white);"><strong>{{ number_format($ov['char_count']) }}</strong></div>
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.kpi_chars') }}</small>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div style="font-size: 1.8rem; color: var(--hr-text-white);"><strong>{{ number_format($ov['human_count']) }}</strong></div>
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.kpi_humans') }}</small>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div style="font-size: 1.8rem; color: var(--hr-text-white);"><strong>{{ $ov['alts_per_human'] }}</strong></div>
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.kpi_alts_per_human') }}</small>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div style="font-size: 1.8rem; color: var(--hr-success, #28a745);"><strong>{{ $ov['registered_pct'] }}%</strong></div>
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.kpi_registered_pct') }}</small>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div style="font-size: 1.8rem; color: var(--hr-success, #28a745);"><strong>{{ number_format($ov['active_count']) }}</strong></div>
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.kpi_active') }}</small>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div style="font-size: 1.8rem; color: var(--hr-warning, #ffc107);"><strong>{{ number_format($ov['pending_apps']) }}</strong></div>
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.kpi_pending_apps') }}</small>
                    </div>
                </div>
                @if($ov['esi_member_count'] !== null && $ov['esi_member_count'] != $ov['char_count'])
                    <small class="d-block mt-2" style="color: var(--hr-text-muted);">
                        <i class="fas fa-info-circle"></i>
                        {{ trans('hr-manager::corp-health.kpi_esi_mismatch', ['esi' => number_format($ov['esi_member_count']), 'tracked' => number_format($ov['char_count'])]) }}
                    </small>
                @endif
                @if($ov['unregistered_count'] > 0)
                    <small class="d-block" style="color: var(--hr-text-muted);">
                        <i class="fas fa-user-slash"></i>
                        {{ trans('hr-manager::corp-health.kpi_unregistered', ['n' => number_format($ov['unregistered_count'])]) }}
                    </small>
                @endif
            </div>
        </div>

        {{-- Top-level rollup --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-pie"></i> {{ trans('hr-manager::corp-health.distribution_heading') }}</h3>
                <div class="card-tools">
                    @if($latestClassifiedAt)
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.last_classified') }}: @hrDate($latestClassifiedAt)</small>
                    @else
                        <small class="text-warning">{{ trans('hr-manager::corp-health.never_classified') }}</small>
                    @endif
                    <form method="POST" action="{{ route('hr-manager.corp-health.run-now') }}" class="d-inline ml-2">
                        @csrf
                        <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                        <button type="submit" class="btn btn-sm btn-hr-secondary"><i class="fas fa-sync-alt"></i> {{ trans('hr-manager::corp-health.run_now') }}</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col"><div style="font-size: 2.2rem; color: var(--hr-success, #28a745);"><strong>{{ $byCategory['active'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.cat_active') }}</small></div>
                    <div class="col"><div style="font-size: 2.2rem; color: var(--hr-warning, #ffc107);"><strong>{{ $byCategory['at_risk'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.cat_at_risk') }}</small></div>
                    <div class="col"><div style="font-size: 2.2rem; color: var(--hr-danger, #dc3545);"><strong>{{ $byCategory['inactive'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.cat_inactive') }}</small></div>
                    <div class="col"><div style="font-size: 2.2rem; color: #495057;"><strong>{{ $byCategory['dead_weight'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.cat_dead_weight') }}</small></div>
                </div>
                <p class="text-center mb-0 mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.distribution_footnote') }}</p>
            </div>
        </div>

        {{-- Structure health (Structure Manager integration: SeAT-core corporation_structures, SM-gated) --}}
        @php $sh = $corpStatus['structure_health'] ?? ['available' => false]; @endphp
        @if(!empty($sh['available']) && ($sh['total'] ?? 0) > 0)
            <div class="card card-dark mb-3" style="border-left: 4px solid #f59e0b;">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-city"></i> {{ trans('hr-manager::corp-health.structure_heading') }}</h3>
                    <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ $sh['total'] }} {{ trans('hr-manager::corp-health.structure_total') }}</small></div>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-success, #28a745);"><strong>{{ $sh['fuel']['healthy'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.structure_fuel_healthy') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-warning, #ffc107);"><strong>{{ $sh['fuel']['low'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.structure_fuel_low') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-danger, #dc3545);"><strong>{{ $sh['fuel']['critical'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.structure_fuel_critical') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: #495057;"><strong>{{ $sh['fuel']['unfuelled'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.structure_fuel_unfuelled') }}</small></div>
                    </div>
                    @if(!empty($sh['by_group']))
                        <div class="mt-2" style="display: flex; flex-wrap: wrap; gap: 6px;">
                            @foreach($sh['by_group'] as $group => $count)
                                <span class="badge" style="background: rgba(102,126,234,0.15); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.35); font-weight: 500;">
                                    {{ $count }} {{ \Illuminate\Support\Str::plural($group, $count) }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @if(!empty($sh['soonest']))
                        <small class="d-block mt-2" style="color: var(--hr-text-muted);">
                            <i class="fas fa-gas-pump"></i>
                            {{ trans('hr-manager::corp-health.structure_soonest', ['name' => $sh['soonest']['name'], 'when' => $sh['soonest']['human']]) }}
                        </small>
                    @endif
                    @if(!empty($sh['threatened']))
                        <div class="mt-3">
                            @foreach($sh['threatened'] as $t)
                                <div style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; margin-bottom: 4px; background: rgba(220,53,69,0.12); border-left: 3px solid #dc3545; border-radius: 4px;">
                                    <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
                                    <span style="color: var(--hr-text-light); font-size: 0.85rem;">
                                        <strong>{{ $t['name'] }}</strong>
                                        {{ trans('hr-manager::corp-health.structure_state_' . $t['state']) }}
                                        @if(!empty($t['timer_end'])) <span style="color: var(--hr-text-muted);">({{ \Carbon\Carbon::parse($t['timer_end'])->diffForHumans() }})</span> @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if(!empty($sh['incidents']['available']))
                        @php $inc = $sh['incidents']; @endphp
                        <div class="mt-3" style="border-top: 1px solid rgba(255,255,255,0.06); padding-top: 10px;">
                            <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.7rem;">{{ trans('hr-manager::corp-health.structure_incidents_heading', ['days' => $inc['days']]) }}</small>
                            <div class="mt-1" style="display: flex; flex-wrap: wrap; gap: 16px; font-size: 0.88rem;">
                                <span style="color: var(--hr-text-light);"><strong style="color: #ffc107;">{{ $inc['reinforced'] }}</strong> {{ trans('hr-manager::corp-health.structure_inc_reinforced') }}</span>
                                <span style="color: var(--hr-text-light);"><strong style="color: #fd7e14;">{{ $inc['fuel_critical'] }}</strong> {{ trans('hr-manager::corp-health.structure_inc_fuel') }}</span>
                                <span style="color: var(--hr-text-light);"><strong style="color: #dc3545;">{{ $inc['destroyed'] }}</strong> {{ trans('hr-manager::corp-health.structure_inc_lost') }}</span>
                            </div>
                            @if(!empty($inc['most_hit']))
                                <small class="d-block mt-1" style="color: var(--hr-text-muted);"><i class="fas fa-crosshairs"></i> {{ trans('hr-manager::corp-health.structure_most_hit', ['name' => $inc['most_hit']['name'], 'count' => $inc['most_hit']['count']]) }}</small>
                            @endif
                        </div>
                    @endif
                    <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.75rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.structure_footnote') }}</small>
                </div>
            </div>
        @endif

        {{-- Corp-wide activity — ALL members by last login (registered or not) --}}
        @php $ra = $rosterActivity ?? ['available' => false]; @endphp
        @if(!empty($ra['available']))
            <div class="card card-dark mb-3" style="border-left: 4px solid #667eea;">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-users"></i> {{ trans('hr-manager::corp-health.roster_activity_heading') }}</h3>
                    <div class="card-tools">
                        <span class="badge" style="background: rgba(102,126,234,0.25); color:#c7d2fe;">{{ $ra['total'] }} {{ trans('hr-manager::corp-health.ra_members') }}</span>
                        <span class="badge" style="background: rgba(40,167,69,0.18); color:#6ee7b7;">{{ $ra['registered'] }} {{ trans('hr-manager::corp-health.ra_registered') }}</span>
                        @if($ra['unregistered'] > 0)
                            <span class="badge" style="background: rgba(255,193,7,0.22); color:#ffe08a;">{{ $ra['unregistered'] }} {{ trans('hr-manager::corp-health.ra_unregistered') }}</span>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col"><div style="font-size: 2.2rem; color: var(--hr-success, #28a745);"><strong>{{ $ra['buckets']['active'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.ra_active', ['days' => $ra['active_days']]) }}</small></div>
                        <div class="col"><div style="font-size: 2.2rem; color: var(--hr-warning, #ffc107);"><strong>{{ $ra['buckets']['at_risk'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.ra_at_risk', ['from' => $ra['active_days'], 'to' => $ra['at_risk_days']]) }}</small></div>
                        <div class="col"><div style="font-size: 2.2rem; color: var(--hr-danger, #dc3545);"><strong>{{ $ra['buckets']['inactive'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.ra_inactive', ['from' => $ra['at_risk_days'], 'to' => $ra['inactive_days']]) }}</small></div>
                        <div class="col"><div style="font-size: 2.2rem; color: #495057;"><strong>{{ $ra['buckets']['dead_weight'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.ra_dead_weight', ['days' => $ra['inactive_days']]) }}</small></div>
                        @if($ra['buckets']['unknown'] > 0)
                            <div class="col"><div style="font-size: 2.2rem; color: var(--hr-text-muted);"><strong>{{ $ra['buckets']['unknown'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.ra_unknown') }}</small></div>
                        @endif
                    </div>
                    @if(!empty($ra['flagged']))
                        @php
                            $raCatColor = ['at_risk' => '#ffc107', 'inactive' => '#dc3545', 'dead_weight' => '#8a929b'];
                            $raCatLabel = [
                                'at_risk'     => trans('hr-manager::corp-health.cat_at_risk'),
                                'inactive'    => trans('hr-manager::corp-health.cat_inactive'),
                                'dead_weight' => trans('hr-manager::corp-health.cat_dead_weight'),
                            ];
                        @endphp
                        <details class="mt-3">
                            <summary style="cursor: pointer; color: #c7d2fe; font-size: 0.85rem;"><i class="fas fa-list-ul"></i> {{ trans('hr-manager::corp-health.ra_flagged_toggle', ['count' => $ra['flagged_total']]) }}</summary>
                            <p class="mt-2 mb-2" style="color: var(--hr-text-muted); font-size: 0.76rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.ra_flagged_note') }}</p>
                            <div style="max-height: 320px; overflow-y: auto;">
                                @foreach($ra['flagged'] as $fm)
                                    @php $rc = $raCatColor[$fm['category']] ?? '#8a929b'; @endphp
                                    <div class="d-flex align-items-center" style="gap: 0.5rem; padding: 0.35rem 0.5rem; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <span style="width: 8px; height: 8px; border-radius: 50%; background: {{ $rc }}; flex-shrink: 0;"></span>
                                        <span style="flex: 1; color: var(--hr-text-light); font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $fm['name'] }}</span>
                                        <span class="badge" style="background: {{ $rc }}22; color: {{ $rc }};">{{ $raCatLabel[$fm['category']] ?? $fm['category'] }}</span>
                                        <span style="color: var(--hr-text-muted); font-size: 0.78rem; min-width: 64px; text-align: right;">{{ trans('hr-manager::corp-health.ra_days_dark', ['days' => $fm['days']]) }}</span>
                                        @if(!empty($fm['registered']) && !empty($fm['user_id']))
                                            <a class="btn btn-sm btn-hr-secondary" href="{{ route('hr-manager.players.show', ['id' => $fm['user_id'], 'corporation_id' => $corporationId]) }}">{{ trans('hr-manager::corp-health.ra_view_profile') }}</a>
                                        @else
                                            <span class="badge" style="background: rgba(255,193,7,0.18); color: #ffe08a;" title="{{ trans('hr-manager::corp-health.ra_flagged_note') }}">{{ trans('hr-manager::corp-health.ra_not_in_seat') }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            @if($ra['flagged_total'] > count($ra['flagged']))
                                <p class="mt-2 mb-0" style="color: var(--hr-text-muted); font-size: 0.75rem;">{{ trans('hr-manager::corp-health.ra_flagged_more', ['shown' => count($ra['flagged']), 'total' => $ra['flagged_total']]) }}</p>
                            @endif
                        </details>
                    @endif
                    <p class="text-center mb-0 mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.roster_activity_footnote', ['active' => $ra['active_days'], 'atrisk' => $ra['at_risk_days'], 'inactive' => $ra['inactive_days']]) }}</p>
                </div>
            </div>
        @endif

        {{-- CWM wallet signals overview --}}
        @php $hasAnyWalletSignal = collect($walletSignals)->sum() > 0; @endphp
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-wallet"></i> {{ trans('hr-manager::corp-health.wallet_signals_heading') }}</h3>
                <div class="card-tools">
                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wallet_signals_intro') }}</small>
                </div>
            </div>
            <div class="card-body">
                @if(!$hasAnyWalletSignal)
                    <p class="text-muted text-center mb-0">{{ trans('hr-manager::corp-health.no_wallet_signals') }}</p>
                @else
                    <div class="row text-center">
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-warning, #ffc107);"><strong>{{ $walletSignals['stalled'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wf_stalled') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-warning, #ffc107);"><strong>{{ $walletSignals['contribution_drop'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wf_contribution_drop') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-danger, #dc3545);"><strong>{{ $walletSignals['compliance_dropped'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wf_compliance_dropped') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-danger, #dc3545);"><strong>{{ $walletSignals['unusual_recipient'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wf_unusual_recipient') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-success, #28a745);"><strong>{{ $walletSignals['milestone'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wf_milestone') }}</small></div>
                    </div>
                    <p class="text-center mb-0 mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.wallet_signals_footnote') }}</p>
                @endif
            </div>
        </div>

        {{-- tier distribution + last login distribution --}}
        <div class="row">
            <div class="col-md-6">
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-layer-group"></i> {{ trans('hr-manager::corp-health.tier_distribution_heading') }}</h3>
                    </div>
                    <div class="card-body">
                        @php $td = $corpStatus['tier_distribution']; @endphp
                        @foreach($td['by_tier'] as $bucket)
                            @php $pct = $td['max'] > 0 ? ($bucket['count'] / $td['max']) * 100 : 0; @endphp
                            <div style="display: flex; align-items: center; gap: 8px; padding: 4px 0;">
                                <span class="badge badge-hr {{ $bucket['badge_class'] }}" style="min-width: 110px; text-align: left;">
                                    {{ $bucket['short_label'] }} {{ $bucket['label'] }}
                                </span>
                                <div style="flex: 1; background: rgba(255,255,255,0.05); height: 14px; border-radius: 3px; overflow: hidden;">
                                    <div style="width: {{ $pct }}%; background: linear-gradient(135deg, #667eea, #764ba2); height: 100%;"></div>
                                </div>
                                <strong style="width: 50px; text-align: right; color: var(--hr-text-white);">{{ $bucket['count'] }}</strong>
                            </div>
                        @endforeach
                        @if($td['unmapped_count'] > 0)
                            <small class="d-block mt-2" style="color: var(--hr-text-muted);">
                                <i class="fas fa-question-circle"></i>
                                {{ trans('hr-manager::corp-health.tier_unmapped', ['n' => $td['unmapped_count']]) }}
                            </small>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-sign-in-alt"></i> {{ trans('hr-manager::corp-health.last_login_heading') }}</h3>
                        <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.last_login_source') }}</small></div>
                    </div>
                    <div class="card-body">
                        @php $ll = $corpStatus['last_login_distribution']; @endphp
                        @if(empty($ll['available']))
                            <p class="text-muted mb-0">
                                <i class="fas fa-info-circle"></i>
                                {{ trans('hr-manager::corp-health.last_login_unavailable') }}
                            </p>
                        @else
                            @php
                                $colors = [
                                    '24h'     => 'var(--hr-success, #28a745)',
                                    '7d'      => 'var(--hr-success, #28a745)',
                                    '30d'     => 'var(--hr-warning, #ffc107)',
                                    '60d'     => 'var(--hr-warning, #ffc107)',
                                    '90d'     => 'var(--hr-danger, #dc3545)',
                                    'dormant' => '#495057',
                                ];
                                $labels = [
                                    '24h'     => trans('hr-manager::corp-health.bucket_24h'),
                                    '7d'      => trans('hr-manager::corp-health.bucket_7d'),
                                    '30d'     => trans('hr-manager::corp-health.bucket_30d'),
                                    '60d'     => trans('hr-manager::corp-health.bucket_60d'),
                                    '90d'     => trans('hr-manager::corp-health.bucket_90d'),
                                    'dormant' => trans('hr-manager::corp-health.bucket_dormant'),
                                ];
                                $maxBucket = max(1, max($ll['buckets']));
                            @endphp
                            @foreach($ll['buckets'] as $key => $count)
                                @php $pct = ($count / $maxBucket) * 100; @endphp
                                <div style="display: flex; align-items: center; gap: 8px; padding: 4px 0;">
                                    <span style="min-width: 110px; color: var(--hr-text-light);">{{ $labels[$key] }}</span>
                                    <div style="flex: 1; background: rgba(255,255,255,0.05); height: 14px; border-radius: 3px; overflow: hidden;">
                                        <div style="width: {{ $pct }}%; background: {{ $colors[$key] }}; height: 100%;"></div>
                                    </div>
                                    <strong style="width: 50px; text-align: right; color: var(--hr-text-white);">{{ $count }}</strong>
                                </div>
                            @endforeach
                            <small class="d-block mt-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.last_login_sample', ['n' => $ll['total']]) }}</small>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Membership trend (joins by day) --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-line"></i> {{ trans('hr-manager::corp-health.membership_trend_heading') }}</h3>
                <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.last_90d') }}</small></div>
            </div>
            <div class="card-body">
                @php $mt = $corpStatus['membership_trend']; @endphp
                @if(empty($mt['available']))
                    <p class="text-muted mb-0">{{ trans('hr-manager::corp-health.membership_trend_unavailable') }}</p>
                @else
                    @php
                        $w = 1100; $h = 110; $n = count($mt['by_day']);
                        $stepX = $n > 1 ? $w / ($n - 1) : 0;
                        $maxJ = max(1, $mt['max_joins']);
                        $points = [];
                        foreach ($mt['by_day'] as $i => $row) {
                            $x = $i * $stepX;
                            $y = $h - ($row['joins'] / $maxJ) * ($h - 4) - 2;
                            $points[] = sprintf('%.1f,%.1f', $x, $y);
                        }
                        $polyline = implode(' ', $points);
                        $first = explode(',', $points[0] ?? '0,0');
                        $last = explode(',', end($points) ?: '0,0');
                        // No trailing "Z": that's a <path> command and is invalid
                        // inside <polygon points> (the parser errors "Expected
                        // number"). A polygon closes back to its first point on
                        // its own, so the fill is correct without it.
                        $area = sprintf('%s,%d %s %s,%d', $first[0], $h, $polyline, $last[0], $h);
                    @endphp
                    <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" style="width: 100%; height: {{ $h }}px;">
                        <defs>
                            <linearGradient id="trendGrad" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0" stop-color="#667eea" stop-opacity="0.7"/>
                                <stop offset="1" stop-color="#764ba2" stop-opacity="0.05"/>
                            </linearGradient>
                        </defs>
                        <polygon points="{{ $area }}" fill="url(#trendGrad)"/>
                        <polyline points="{{ $polyline }}" fill="none" stroke="#667eea" stroke-width="2"/>
                    </svg>
                    <div class="d-flex justify-content-between mt-1" style="color: var(--hr-text-muted); font-size: 0.8rem;">
                        <span>{{ $mt['by_day'][0]['date'] ?? '' }}</span>
                        <span>{{ trans('hr-manager::corp-health.membership_trend_total', ['n' => $mt['total_joins']]) }}</span>
                        <span>{{ end($mt['by_day'])['date'] ?? '' }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Inactive directors (critical) — roster-based so it catches
             UNAUTHED dark directors, not just classified users. --}}
        @php $dh = $directorHealth ?? ['available' => false]; @endphp
        <div class="card card-dark mb-3">
            <div class="card-header" style="border-left: 4px solid var(--hr-danger, #dc3545);">
                <h3 class="card-title"><i class="fas fa-exclamation-triangle text-danger"></i> {{ trans('hr-manager::corp-health.inactive_directors_heading') }}</h3>
                @if(!empty($dh['available']) && ($dh['total'] ?? 0) > 0)
                    <div class="card-tools">
                        <span class="badge" style="background: rgba(40,167,69,0.2); color:#6ee7b7;">{{ $dh['active_count'] }} {{ trans('hr-manager::corp-health.dir_active') }}</span>
                        @if(($dh['inactive_count'] ?? 0) > 0)
                            <span class="badge" style="background: rgba(220,53,69,0.25); color:#f5a3ac;">{{ $dh['inactive_count'] }} {{ trans('hr-manager::corp-health.dir_inactive') }}</span>
                        @endif
                    </div>
                @endif
            </div>
            <div class="card-body p-0">
                @if(empty($dh['available']))
                    <p class="p-3 mb-0 text-muted">{{ trans('hr-manager::corp-health.no_director_data') }}</p>
                @elseif(empty($dh['inactive']))
                    <p class="p-3 mb-0" style="color: var(--hr-success);">{{ trans('hr-manager::corp-health.no_inactive_directors') }}</p>
                @else
                    <p class="px-3 pt-3 mb-2" style="color: var(--hr-text-muted); font-size: 0.85rem;">{{ trans('hr-manager::corp-health.inactive_directors_help', ['days' => $dh['threshold_days']]) }}</p>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('hr-manager::corp-health.col_player') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.col_last_logon') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.col_status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($dh['inactive'] as $d)
                                    <tr>
                                        <td>
                                            <img src="https://images.evetech.net/characters/{{ $d['character_id'] }}/portrait?size=32" style="width:28px;height:28px;border-radius:50%;vertical-align:middle;margin-right:6px;" alt="">
                                            <strong>{{ $d['name'] }}</strong>
                                        </td>
                                        <td>
                                            <strong class="text-danger">{{ $d['days_since_logon'] !== null ? $d['days_since_logon'] . 'd' : trans('hr-manager::corp-health.unknown') }}</strong>
                                            @if($d['last_logon'])
                                                <small class="text-muted d-block">{{ \Carbon\Carbon::parse($d['last_logon'])->diffForHumans() }}</small>
                                            @endif
                                        </td>
                                        <td>
                                            @if(!$d['is_authed'])
                                                <span class="badge" style="background: rgba(255,193,7,0.25); color:#ffe08a;" title="{{ trans('hr-manager::corp-health.not_in_seat_hint') }}"><i class="fas fa-user-slash"></i> {{ trans('hr-manager::corp-health.not_in_seat') }}</span>
                                            @elseif($d['classifier'])
                                                <span class="badge badge-hr">{{ trans('hr-manager::corp-health.cat_' . $d['classifier']) }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($d['user_id'])
                                                <a class="btn btn-sm btn-hr-secondary" href="{{ route('hr-manager.players.show', ['id' => $d['user_id'], 'corporation_id' => $corporationId]) }}">{{ trans('hr-manager::corp-health.view_player') }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Directors not registered in SeAT (warning) — limited visibility/control --}}
        @if(!empty($dh['available']) && ($dh['unauthed_count'] ?? 0) > 0)
            <div class="card card-dark mb-3">
                <div class="card-header" style="border-left: 4px solid var(--hr-warning, #ffc107);">
                    <h3 class="card-title"><i class="fas fa-user-slash" style="color: var(--hr-warning, #ffc107);"></i> {{ trans('hr-manager::corp-health.unauthed_directors_heading') }}</h3>
                    <div class="card-tools">
                        <span class="badge" style="background: rgba(255,193,7,0.25); color:#ffe08a;">{{ $dh['unauthed_count'] }} / {{ $dh['total'] }}</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <p class="px-3 pt-3 mb-2" style="color: var(--hr-text-muted); font-size: 0.85rem;">{{ trans('hr-manager::corp-health.unauthed_directors_help') }}</p>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('hr-manager::corp-health.col_player') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.col_last_logon') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($dh['unauthed'] as $d)
                                    @php $dlogon = $d['last_logon'] ? \Carbon\Carbon::parse($d['last_logon']) : null; $ddark = ($d['days_since_logon'] ?? 0) >= $dh['threshold_days']; @endphp
                                    <tr>
                                        <td>
                                            <img src="https://images.evetech.net/characters/{{ $d['character_id'] }}/portrait?size=32" style="width:28px;height:28px;border-radius:50%;vertical-align:middle;margin-right:6px;" alt="">
                                            <strong>{{ $d['name'] }}</strong>
                                        </td>
                                        <td>
                                            @if($dlogon)
                                                <span style="color: {{ $ddark ? 'var(--hr-danger, #dc3545)' : 'var(--hr-text-muted)' }};">{{ $dlogon->diffForHumans() }}</span>
                                            @else
                                                <em class="text-muted">{{ trans('hr-manager::corp-health.unknown') }}</em>
                                            @endif
                                        </td>
                                        <td>
                                            @if($ddark)
                                                <span class="badge" style="background: rgba(220,53,69,0.25); color:#f5a3ac;">{{ trans('hr-manager::corp-health.dir_inactive') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        {{-- At-risk and worse --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-line"></i> {{ trans('hr-manager::corp-health.at_risk_heading') }}</h3>
            </div>
            <div class="card-body p-0">
                @if($atRiskOrWorse->isEmpty())
                    <p class="p-3 mb-0" style="color: var(--hr-success);">{{ trans('hr-manager::corp-health.no_concerns') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('hr-manager::corp-health.col_player') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.col_tier') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.col_category') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.col_wallet_flags') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.col_days_inactive') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.col_threshold') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.col_last_activity') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($atRiskOrWorse as $c)
                                    <tr>
                                        <td>User #{{ $c->user_id }}</td>
                                        <td>
                                            @if($c->tier_level !== null)
                                                <span class="badge badge-hr {{ \HrManager\Support\TierLevel::badgeClass($c->tier_level) }}">
                                                    {{ \HrManager\Support\TierLevel::shortLabel($c->tier_level) }}
                                                    {{ \HrManager\Support\TierLevel::label($c->tier_level) }}
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge badge-hr {{ $c->badge_class }}">
                                                {{ trans('hr-manager::corp-health.cat_' . $c->category) }}
                                            </span>
                                        </td>
                                        <td>
                                            @foreach((array) ($c->wallet_flags ?? []) as $flag)
                                                @php
                                                    $flagClass = match ($flag) {
                                                        'silent_wallet_director', 'compliance_very_low' => 'badge-danger',
                                                        'loyalty_hold', 'blueprint_hold' => 'badge-success',
                                                        default => 'badge-warning',
                                                    };
                                                @endphp
                                                <span class="badge {{ $flagClass }} mr-1" title="{{ trans('hr-manager::corp-health.wf_' . $flag) }}">
                                                    {{ trans('hr-manager::corp-health.wf_short_' . $flag) }}
                                                </span>
                                            @endforeach
                                        </td>
                                        <td>{{ $c->days_inactive }}d</td>
                                        <td>{{ $c->threshold_days ?? '-' }}d</td>
                                        <td>{{ $c->last_activity_at?->diffForHumans() ?? '-' }}</td>
                                        <td>
                                            <a class="btn btn-sm btn-hr-secondary" href="{{ route('hr-manager.players.show', ['id' => $c->user_id, 'corporation_id' => $corporationId]) }}">
                                                {{ trans('hr-manager::corp-health.view_player') }}
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- =================================================================
         COMPOSITION TAB — who / what the corp is made of.
         ================================================================= --}}
    @if($activeTab === 'composition')
        {{-- Corp composition by activity --}}
        @php $rdist = $corpStatus['role_distribution'] ?? null; @endphp
        @if(!empty($rdist['available']))
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-users-cog"></i> {{ trans('hr-manager::corp-health.composition_heading') }}</h3>
                    <div class="card-tools">
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.composition_roster', ['n' => $rdist['roster_size']]) }}</small>
                    </div>
                </div>
                <div class="card-body">
                    <small class="d-block mb-2" style="color: var(--hr-text-muted);">{!! trans('hr-manager::corp-health.composition_help') !!}</small>
                    @php
                        $roleColors = [
                            'ratter'   => '#dc3545', 'miner' => '#17a2b8', 'trader' => '#ffc107',
                            'pi'       => '#9b7ed5', 'industry' => '#fd7e14',
                        ];
                    @endphp
                    @foreach($rdist['roles'] as $role)
                        <div style="display: flex; align-items: center; gap: 8px; padding: 4px 0;">
                            <span style="min-width: 130px; color: var(--hr-text-white); font-size: 0.88rem;">
                                <i class="fas {{ $role['icon'] }}" style="color: {{ $roleColors[$role['key']] ?? '#888' }};"></i>
                                {{ $role['label'] }}
                            </span>
                            <div style="flex: 1; background: rgba(255,255,255,0.05); height: 16px; border-radius: 3px; overflow: hidden;">
                                <div style="width: {{ $role['pct'] }}%; background: {{ $roleColors[$role['key']] ?? '#888' }}; height: 100%; transition: width 0.3s;"></div>
                            </div>
                            <strong style="width: 90px; text-align: right; color: var(--hr-text-white); font-size: 0.85rem;">
                                {{ $role['count'] }} <span style="color: var(--hr-text-muted); font-weight: normal;">({{ $role['pct'] }}%)</span>
                            </strong>
                        </div>
                    @endforeach
                    <div style="display: flex; align-items: center; gap: 8px; padding: 4px 0; margin-top: 4px; border-top: 1px dashed rgba(255,255,255,0.08);">
                        <span style="min-width: 130px; color: var(--hr-text-muted); font-size: 0.88rem;">
                            <i class="fas fa-ghost"></i> {{ trans('hr-manager::corp-health.composition_no_activity') }}
                        </span>
                        <div style="flex: 1; background: rgba(255,255,255,0.05); height: 16px; border-radius: 3px; overflow: hidden;">
                            <div style="width: {{ $rdist['no_activity_pct'] }}%; background: rgba(255,255,255,0.15); height: 100%;"></div>
                        </div>
                        <strong style="width: 90px; text-align: right; color: var(--hr-text-muted); font-size: 0.85rem;">
                            {{ $rdist['no_activity'] }} <span style="font-weight: normal;">({{ $rdist['no_activity_pct'] }}%)</span>
                        </strong>
                    </div>
                    <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;">
                        <i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.composition_footnote') }}
                        @unless($rdist['cwm_present'])
                            <br>{{ trans('hr-manager::corp-health.composition_no_cwm') }}
                        @endunless
                    </small>
                </div>
            </div>
        @endif

        {{-- Character quality --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-user-graduate"></i> {{ trans('hr-manager::corp-health.character_quality_heading') }}</h3>
            </div>
            <div class="card-body">
                @php $cq = $corpStatus['character_quality']; @endphp
                @if(empty($cq['available']))
                    <p class="text-muted mb-0">{{ trans('hr-manager::corp-health.character_quality_unavailable') }}</p>
                @else
                    <div class="row text-center">
                        <div class="col">
                            <div style="font-size: 1.4rem; color: var(--hr-text-white);"><strong>{{ $cq['sp_avg'] !== null ? number_format($cq['sp_avg'] / 1e6, 1) . 'M' : '-' }}</strong></div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.sp_avg') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.4rem; color: var(--hr-text-white);"><strong>{{ $cq['sp_median'] !== null ? number_format($cq['sp_median'] / 1e6, 1) . 'M' : '-' }}</strong></div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.sp_median') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.4rem; color: {{ ($cq['sec_avg'] ?? 0) >= 0 ? 'var(--hr-success)' : 'var(--hr-danger)' }};">
                                <strong>{{ $cq['sec_avg'] !== null ? number_format($cq['sec_avg'], 2) : '-' }}</strong>
                            </div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.sec_avg') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.4rem; color: var(--hr-danger);"><strong>{{ number_format($cq['sec_negative']) }}</strong></div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.sec_negative') }}</small>
                        </div>
                    </div>
                    <small class="d-block mt-2 text-center" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.sample_n', ['n' => $cq['sec_count']]) }}</small>
                @endif
            </div>
        </div>

        {{-- Director roster + role headcount --}}
        <div class="row">
            <div class="col-md-8">
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-crown"></i> {{ trans('hr-manager::corp-health.director_roster_heading') }}</h3>
                    </div>
                    <div class="card-body p-0">
                        @php $dr = $corpStatus['director_roster']; @endphp
                        @if(empty($dr['available']))
                            <p class="text-muted text-center p-3 mb-0">{{ trans('hr-manager::corp-health.no_director_data') }}</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ trans('hr-manager::corp-health.col_player') }}</th>
                                            <th>{{ trans('hr-manager::corp-health.col_last_logon') }}</th>
                                            <th>{{ trans('hr-manager::corp-health.col_category') }}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($dr['list'] as $d)
                                            @php
                                                $lastLogon = $d['last_logon'] ? \Carbon\Carbon::parse($d['last_logon']) : null;
                                                $staleness = $lastLogon ? $lastLogon->diffInDays(now()) : null;
                                                $staleColor = $staleness === null ? 'var(--hr-text-muted)' : ($staleness > 30 ? 'var(--hr-danger, #dc3545)' : ($staleness > 7 ? 'var(--hr-warning, #ffc107)' : 'var(--hr-success, #28a745)'));
                                            @endphp
                                            <tr>
                                                <td>
                                                    <img src="https://images.evetech.net/characters/{{ $d['character_id'] }}/portrait?size=32"
                                                         style="width: 28px; height: 28px; border-radius: 50%; vertical-align: middle; margin-right: 6px;" alt="">
                                                    <strong>{{ $d['name'] }}</strong>
                                                </td>
                                                <td>
                                                    @if($lastLogon)
                                                        <span style="color: {{ $staleColor }};">{{ $lastLogon->diffForHumans() }}</span>
                                                    @else
                                                        <em style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.unknown') }}</em>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if(!$d['user_id'])
                                                        <span class="badge" style="background: rgba(255,193,7,0.25); color:#ffe08a;" title="{{ trans('hr-manager::corp-health.not_in_seat_hint') }}"><i class="fas fa-user-slash"></i> {{ trans('hr-manager::corp-health.not_in_seat') }}</span>
                                                    @elseif($d['classifier'])
                                                        <span class="badge badge-hr">{{ trans('hr-manager::corp-health.cat_' . $d['classifier']) }}</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($d['user_id'])
                                                        <a class="btn btn-sm btn-hr-secondary" href="{{ route('hr-manager.players.show', ['id' => $d['user_id'], 'corporation_id' => $corporationId]) }}">
                                                            {{ trans('hr-manager::corp-health.view_player') }}
                                                        </a>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-id-badge"></i> {{ trans('hr-manager::corp-health.role_holders_heading') }}</h3>
                    </div>
                    <div class="card-body">
                        @php $rh = $corpStatus['role_holders']; @endphp
                        @if(empty($rh['available']))
                            <p class="text-muted mb-0"><small>{{ trans('hr-manager::corp-health.no_role_data') }}</small></p>
                        @else
                            @foreach($rh['by_role'] as $row)
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small style="color: var(--hr-text-light);">{{ $row['role'] }}</small>
                                    <strong style="color: var(--hr-text-white);">{{ $row['count'] }}</strong>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Personnel Manager coherence --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-shield-alt"></i> {{ trans('hr-manager::corp-health.coherence_heading') }}</h3>
            </div>
            <div class="card-body">
                <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.coherence_intro') }}</p>
                <div class="row text-center">
                    <div class="col"><div style="font-size: 1.8rem; color: var(--hr-text-white);"><strong>{{ $coherence['total_in_corp_users'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.coherence_total') }}</small></div>
                    <div class="col"><div style="font-size: 1.8rem; color: var(--hr-success);"><strong>{{ $coherence['has_personnel_manager'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.coherence_with_role') }}</small></div>
                    <div class="col"><div style="font-size: 1.8rem; color: var(--hr-warning);"><strong>{{ $coherence['missing_personnel_manager'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.coherence_without_role') }}</small></div>
                    <div class="col"><div style="font-size: 1.8rem; color: var(--hr-text-white);"><strong>{{ $coherence['coverage_pct'] }}%</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.coherence_coverage') }}</small></div>
                </div>
                @if(!empty($coherence['sample_missing_user_ids']))
                    <details class="mt-3">
                        <summary style="cursor: pointer; color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.coherence_sample_missing') }}</summary>
                        <div class="mt-2" style="color: var(--hr-text-light); font-family: monospace; font-size: 0.85rem;">
                            {{ implode(', ', $coherence['sample_missing_user_ids']) }}
                        </div>
                    </details>
                @endif
            </div>
        </div>

        {{-- FC activity roster — who leads fleets, who's faded, who's new.
             From HR's EventBus-accumulated fc_activity (SeAT Broadcast).
             Forward-only: builds over time, "new" is noisy right after
             install. --}}
        @php $fcs = $corpStatus['fc_status'] ?? null; @endphp
        @if(!empty($fcs['available']) && (($fcs['total_fcs'] ?? 0) > 0 || !empty($fcs['organizers'])))
            <div class="card card-dark mb-3" style="border-left: 4px solid #f59e0b;">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-broadcast-tower" style="color: #f59e0b;"></i> {{ trans('hr-manager::corp-health.fc_heading') }}</h3>
                    <div class="card-tools">
                        <span class="badge" style="background: rgba(40,167,69,0.2); color: #6ee7b7;">{{ count($fcs['active']) }} {{ trans('hr-manager::corp-health.fc_active') }}</span>
                        @if(count($fcs['inactive']) > 0)
                            <span class="badge" style="background: rgba(108,117,125,0.25); color: #adb5bd;">{{ count($fcs['inactive']) }} {{ trans('hr-manager::corp-health.fc_inactive') }}</span>
                        @endif
                        @if($fcs['new_count'] > 0)
                            <span class="badge" style="background: rgba(102,126,234,0.25); color: #c7d2fe;">{{ $fcs['new_count'] }} {{ trans('hr-manager::corp-health.fc_new') }}</span>
                        @endif
                        @if(($fcs['total_formups'] ?? 0) > 0)
                            <span class="badge" style="background: rgba(40,167,69,0.15); color: #6ee7b7;">{{ $fcs['total_formups'] }} {{ trans('hr-manager::corp-health.fc_planned') }}</span>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <small class="d-block mb-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.fc_help') }}</small>
                    <div class="row">
                        {{-- Active FC leaderboard --}}
                        <div class="col-md-7">
                            <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::corp-health.fc_active_heading') }}</small>
                            @forelse($fcs['active'] as $i => $fc)
                                <div class="d-flex align-items-center mb-1" style="gap: 8px; padding: 5px 8px; background: rgba(245,158,11,0.06); border-radius: 4px;">
                                    <span style="min-width: 22px; color: var(--hr-text-muted); font-weight: 700;">{{ ['🥇','🥈','🥉'][$i] ?? ('#' . ($i+1)) }}</span>
                                    @if($fc['character_id'])
                                        <img src="https://images.evetech.net/characters/{{ $fc['character_id'] }}/portrait?size=32" style="width: 26px; height: 26px; border-radius: 50%;" onerror="this.style.display='none'">
                                    @endif
                                    <a href="{{ route('hr-manager.players.show', ['id' => $fc['user_id'], 'corporation_id' => $corporationId]) }}" style="flex: 1; color: var(--hr-text-white); text-decoration: none; font-size: 0.9rem;">
                                        {{ $fc['name'] }}
                                        @if($fc['is_new'])<span class="badge" style="background: rgba(102,126,234,0.25); color: #c7d2fe; font-size: 0.6rem;">{{ trans('hr-manager::corp-health.fc_new') }}</span>@endif
                                    </a>
                                    <span class="badge" style="background: rgba(245,158,11,0.2); color: #fcd34d;">{{ $fc['per_month'] }}/mo</span>
                                    <small style="color: var(--hr-text-muted); min-width: 60px; text-align: right;">{{ $fc['total'] }} {{ trans('hr-manager::corp-health.fc_broadcasts') }}</small>
                                </div>
                            @empty
                                <p class="text-muted mb-0"><small>{{ trans('hr-manager::corp-health.fc_none_active') }}</small></p>
                            @endforelse
                        </div>

                        {{-- Faded / inactive FCs --}}
                        <div class="col-md-5">
                            <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::corp-health.fc_inactive_heading') }}</small>
                            @forelse($fcs['inactive'] as $fc)
                                @php $lastSeen = \Illuminate\Support\Carbon::parse($fc['last_at']); @endphp
                                <div class="d-flex align-items-center mb-1" style="gap: 8px; padding: 5px 8px; background: rgba(255,255,255,0.02); border-radius: 4px; opacity: 0.75;">
                                    @if($fc['character_id'])
                                        <img src="https://images.evetech.net/characters/{{ $fc['character_id'] }}/portrait?size=32" style="width: 24px; height: 24px; border-radius: 50%; filter: grayscale(0.5);" onerror="this.style.display='none'">
                                    @endif
                                    <a href="{{ route('hr-manager.players.show', ['id' => $fc['user_id'], 'corporation_id' => $corporationId]) }}" style="flex: 1; color: var(--hr-text-light); text-decoration: none; font-size: 0.85rem;">{{ $fc['name'] }}</a>
                                    <small style="color: var(--hr-text-muted);" title="{{ trans('hr-manager::corp-health.fc_last_broadcast') }}: {{ $lastSeen->format('M d, Y') }}">{{ $lastSeen->diffForHumans(null, true) }} {{ trans('hr-manager::corp-health.fc_ago') }}</small>
                                </div>
                            @empty
                                <p class="text-muted mb-0"><small>{{ trans('hr-manager::corp-health.fc_none_inactive') }}</small></p>
                            @endforelse
                        </div>
                    </div>

                    {{-- Organizers — who PLANS ops (pings.formup.scheduled).
                         Distinct from the broadcast leaderboard: proactive
                         leadership, scheduling fleets ahead for tactical events. --}}
                    @if(!empty($fcs['organizers']))
                        <div class="mt-3 pt-2" style="border-top: 1px solid rgba(255,255,255,0.06);">
                            <small class="d-block mb-2" style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                                <i class="fas fa-calendar-check" style="color: #6ee7b7;"></i> {{ trans('hr-manager::corp-health.fc_organizers_heading') }}
                            </small>
                            @foreach($fcs['organizers'] as $org)
                                <div class="d-flex align-items-center mb-1" style="gap: 8px; padding: 5px 8px; background: rgba(40,167,69,0.06); border-radius: 4px;">
                                    @if($org['character_id'])
                                        <img src="https://images.evetech.net/characters/{{ $org['character_id'] }}/portrait?size=32" style="width: 24px; height: 24px; border-radius: 50%;" onerror="this.style.display='none'">
                                    @endif
                                    <a href="{{ route('hr-manager.players.show', ['id' => $org['user_id'], 'corporation_id' => $corporationId]) }}" style="flex: 1; color: var(--hr-text-white); text-decoration: none; font-size: 0.88rem;">{{ $org['name'] }}</a>
                                    @php $orgNext = !empty($org['next_at']) ? \Illuminate\Support\Carbon::parse($org['next_at']) : null; @endphp
                                    @if($orgNext && $orgNext->isFuture())
                                        <small style="color: #6ee7b7;" title="{{ trans('hr-manager::corp-health.fc_next_op') }}"><i class="fas fa-clock"></i> {{ $orgNext->diffForHumans() }}</small>
                                    @endif
                                    <span class="badge" style="background: rgba(40,167,69,0.2); color: #6ee7b7;">{{ $org['formups'] }} {{ trans('hr-manager::corp-health.fc_planned') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;">
                        <i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.fc_footnote') }}
                    </small>
                </div>
            </div>
        @endif
    @endif

    {{-- =================================================================
         ECONOMY TAB — director-only money view.
         ================================================================= --}}
    @if($activeTab === 'economy')
        @php
            $iskFmt = function ($v) {
                if ($v === null) return '-';
                $abs = abs((float) $v); $sign = $v < 0 ? '-' : '';
                if ($abs >= 1.0e12) return $sign . number_format($abs / 1.0e12, 2) . 'T';
                if ($abs >= 1.0e9)  return $sign . number_format($abs / 1.0e9, 2)  . 'B';
                if ($abs >= 1.0e6)  return $sign . number_format($abs / 1.0e6, 2)  . 'M';
                if ($abs >= 1.0e3)  return $sign . number_format($abs / 1.0e3, 2)  . 'K';
                return number_format($v, 0);
            };
        @endphp

        {{-- Corp financial pulse: the corp's OWN wallet health (balance + income
             / expense / net + monthly trend), via CWM v3.1+ wallet.getCorpSummary.
             Self-hides when CWM is absent or too old. --}}
        @php $cfs = $corpStatus['corp_financial_summary'] ?? ['available' => false]; @endphp
        @if(!empty($cfs['available']))
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-line"></i> {{ trans('hr-manager::corp-health.fin_pulse_heading') }}</h3>
                    <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.fin_pulse_via', ['months' => $cfs['months']]) }}</small></div>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col">
                            <div style="font-size: 1.5rem; color: var(--hr-text-white);"><strong>{{ $cfs['balance_available'] ? $iskFmt($cfs['balance']) : '-' }}</strong></div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.fin_balance') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.5rem; color: var(--hr-success, #28a745);"><strong>{{ $iskFmt($cfs['income_total']) }}</strong></div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.fin_income') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.5rem; color: var(--hr-danger, #dc3545);"><strong>{{ $iskFmt($cfs['expense_total']) }}</strong></div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.fin_expense') }}</small>
                        </div>
                        <div class="col">
                            @php $netPos = $cfs['net_total'] >= 0; @endphp
                            <div style="font-size: 1.5rem; color: {{ $netPos ? 'var(--hr-success, #28a745)' : 'var(--hr-danger, #dc3545)' }};">
                                <strong>{{ ($netPos ? '+' : '') . $iskFmt($cfs['net_total']) }}</strong>
                            </div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.fin_net', ['months' => $cfs['months']]) }}</small>
                        </div>
                    </div>

                    @if(!empty($cfs['monthly']))
                        @php
                            $nets = array_map(fn ($m) => (float) $m['net'], $cfs['monthly']);
                            $maxAbs = 0; foreach ($nets as $n) { $maxAbs = max($maxAbs, abs($n)); }
                            $maxAbs = $maxAbs > 0 ? $maxAbs : 1;
                        @endphp
                        <div style="display: flex; align-items: flex-end; gap: 6px; height: 64px; margin-top: 18px;">
                            @foreach($cfs['monthly'] as $m)
                                @php $n = (float) $m['net']; $h = (int) round(abs($n) / $maxAbs * 48); $pos = $n >= 0; @endphp
                                <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%;"
                                     title="{{ $m['period'] }}: {{ ($pos ? '+' : '') . $iskFmt($n) }}">
                                    <div style="width: 100%; height: {{ max(2, $h) }}px; background: {{ $pos ? 'var(--hr-success, #28a745)' : 'var(--hr-danger, #dc3545)' }}; opacity: 0.78; border-radius: 2px;"></div>
                                    <small style="color: var(--hr-text-muted); font-size: 0.62rem; margin-top: 3px;">{{ \Carbon\Carbon::parse($m['period'] . '-01')->format('M') }}</small>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <small style="color: var(--hr-text-muted); display: block; margin-top: 12px;">{{ trans('hr-manager::corp-health.fin_pulse_note') }}</small>
                </div>
            </div>
        @endif

        {{-- Corp wallet aggregates --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-coins"></i> {{ trans('hr-manager::corp-health.wallet_corp_heading') }}</h3>
                <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.via_cwm') }}</small></div>
            </div>
            <div class="card-body">
                @php $wc = $corpStatus['wallet_corp_totals']; @endphp
                @if(empty($wc['available']))
                    <p class="text-muted mb-0">{{ trans('hr-manager::corp-health.wallet_corp_unavailable') }}</p>
                @else
                    <div class="row text-center">
                        <div class="col">
                            <div style="font-size: 1.4rem; color: var(--hr-text-white);"><strong>{{ $iskFmt($wc['lifetime_contribution']) }}</strong></div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wc_lifetime_contributed') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.4rem; color: {{ ($wc['net_position_6mo'] ?? 0) >= 0 ? 'var(--hr-success)' : 'var(--hr-danger)' }};">
                                <strong>{{ $iskFmt($wc['net_position_6mo']) }}</strong>
                            </div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wc_net_6mo') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.4rem; color: {{ ($wc['avg_compliance_pct'] ?? 100) >= 80 ? 'var(--hr-success)' : (($wc['avg_compliance_pct'] ?? 100) >= 50 ? 'var(--hr-warning)' : 'var(--hr-danger)') }};">
                                <strong>{{ $wc['avg_compliance_pct'] !== null ? $wc['avg_compliance_pct'] . '%' : '-' }}</strong>
                            </div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wc_avg_compliance') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.4rem; color: var(--hr-warning);"><strong>{{ $wc['low_compliance_count'] }}</strong></div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wc_low_compliance') }}</small>
                        </div>
                    </div>
                    <small class="d-block mt-2 text-center" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.sample_n', ['n' => $wc['sample_size']]) }}</small>
                @endif
            </div>
        </div>

        {{-- Top contributors leaderboard --}}
        @php $tc = $corpStatus['top_contributors'] ?? null; @endphp
        @if(!empty($tc['available']) && !empty($tc['list']))
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-trophy"></i> {{ trans('hr-manager::corp-health.top_contributors_heading') }}</h3>
                    <div class="card-tools">
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.top_contributors_caveat') }}</small>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach($tc['list'] as $i => $entry)
                            <div class="col-md col-6 text-center mb-2">
                                @php $medal = ['🥇','🥈','🥉','#4','#5'][$i] ?? ('#' . ($i + 1)); @endphp
                                <div style="font-size: 1.4rem;">{{ $medal }}</div>
                                <img src="https://images.evetech.net/characters/{{ $entry['character_id'] }}/portrait?size=64"
                                     style="width: 56px; height: 56px; border-radius: 50%; margin: 4px 0;"
                                     onerror="this.style.display='none'">
                                <div style="font-size: 0.9rem; color: var(--hr-text-white); font-weight: 600; word-break: break-word;">
                                    {{ $entry['name'] }}
                                </div>
                                <div style="color: var(--hr-text-light); font-size: 0.9rem;">
                                    {{ $iskFmt($entry['contributed']) }} ISK
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- Wallet Insights cluster --}}
        @php
            $wi_untaxed = $corpStatus['untaxed_earners'] ?? null;
            $wi_anom    = $corpStatus['wallet_anomalies'] ?? null;
            $wi_free    = $corpStatus['wallet_freeloaders'] ?? null;
            $wi_loyal   = $corpStatus['loyalty_streaks'] ?? null;
            $wi_out     = $corpStatus['corp_outflows'] ?? null;
            $wi_any = (!empty($wi_untaxed['available']) || !empty($wi_anom['available'])
                || !empty($wi_free['available']) || !empty($wi_loyal['available'])
                || !empty($wi_out['available']));
            // All five member cards share one data source; read whichever is present.
            $wi_source = $wi_free['source'] ?? $wi_untaxed['source'] ?? $wi_anom['source'] ?? $wi_loyal['source'] ?? 'registered';
            $wi_corpwide = $wi_source === 'corp-wide';
        @endphp
        @if($wi_any)
            <h4 style="color: var(--hr-text-white); margin: 1rem 0 0.5rem;">
                <i class="fas fa-coins"></i> {{ trans('hr-manager::corp-health.wallet_insights_heading') }}
            </h4>
            @if($wi_corpwide)
                <div class="alert" style="background: rgba(40,167,69,0.08); border-left: 3px solid var(--hr-success, #28a745); font-size: 0.82rem; color: var(--hr-text-muted); padding: 8px 12px;">
                    <i class="fas fa-check-circle"></i> {{ trans('hr-manager::corp-health.wallet_insights_scope_corpwide') }}
                </div>
            @else
                <div class="alert" style="background: rgba(255,193,7,0.08); border-left: 3px solid var(--hr-warning, #ffc107); font-size: 0.82rem; color: var(--hr-text-muted); padding: 8px 12px;">
                    <i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.wallet_insights_scope') }}
                </div>
            @endif
            <div class="row">
                @if(!empty($wi_untaxed['available']))
                    <div class="col-lg-6">
                        <div class="card card-dark mb-3" style="border-left: 4px solid var(--hr-danger, #dc3545);">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-fire"></i> {{ trans('hr-manager::corp-health.untaxed_earners_heading') }}</h3>
                                <div class="card-tools"><span class="badge badge-secondary">{{ $wi_untaxed['total'] ?? 0 }}</span></div>
                            </div>
                            <div class="card-body">
                                <small class="d-block mb-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.untaxed_earners_help') }}</small>
                                @forelse($wi_untaxed['list'] as $row)
                                    <div class="d-flex align-items-center mb-1" style="gap: 8px; padding: 5px 8px; background: rgba(220,53,69,0.07); border-radius: 4px;">
                                        <img src="https://images.evetech.net/characters/{{ $row['character_id'] }}/portrait?size=32" style="width: 28px; height: 28px; border-radius: 50%;" onerror="this.style.display='none'">
                                        <a href="{{ route('hr-manager.members.show', $row['character_id']) }}" style="flex: 1; color: var(--hr-text-white); text-decoration: none; font-size: 0.9rem;">{{ $row['name'] }}</a>
                                        <span class="badge" style="background: rgba(220,53,69,0.2); color: #fca5a5;">{{ number_format($row['compliance_pct'], 0) }}%</span>
                                        <small style="color: var(--hr-text-muted); min-width: 70px; text-align: right;">{{ $iskFmt(max($row['ratting_income'], $row['mining_value'])) }} earned</small>
                                    </div>
                                @empty
                                    <p class="text-muted mb-0">{{ trans('hr-manager::corp-health.wallet_insights_clean') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif

                @if(!empty($wi_anom['available']))
                    <div class="col-lg-6">
                        <div class="card card-dark mb-3" style="border-left: 4px solid var(--hr-warning, #ffc107);">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::corp-health.wallet_anomalies_heading') }}</h3>
                                <div class="card-tools"><span class="badge badge-secondary">{{ $wi_anom['total'] ?? 0 }}</span></div>
                            </div>
                            <div class="card-body">
                                <small class="d-block mb-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.wallet_anomalies_help') }}</small>
                                @forelse($wi_anom['list'] as $row)
                                    <div class="d-flex align-items-center mb-1" style="gap: 8px; padding: 5px 8px; background: rgba(255,193,7,0.07); border-radius: 4px;">
                                        <img src="https://images.evetech.net/characters/{{ $row['character_id'] }}/portrait?size=32" style="width: 28px; height: 28px; border-radius: 50%;" onerror="this.style.display='none'">
                                        @if(!empty($row['entity_type']))
                                            <span style="flex: 1; color: var(--hr-text-white); font-size: 0.9rem;">{{ $row['name'] }}</span>
                                            <span class="badge" style="background: rgba(102,126,234,0.2); color: #a5b4fc; font-size: 0.6rem; border: 1px solid rgba(102,126,234,0.4);"><i class="fas fa-{{ $row['entity_type'] === 'alliance' ? 'sitemap' : 'building' }}"></i> {{ $row['entity_type'] === 'alliance' ? trans('hr-manager::corp-health.entity_alliance') : trans('hr-manager::corp-health.entity_corp') }}</span>
                                        @else
                                            <a href="{{ route('hr-manager.members.show', $row['character_id']) }}" style="flex: 1; color: var(--hr-text-white); text-decoration: none; font-size: 0.9rem;">{{ $row['name'] }}</a>
                                        @endif
                                        @foreach($row['flags'] as $flag)
                                            <span class="badge" style="background: rgba(220,53,69,0.2); color: #fca5a5; font-size: 0.65rem;">{{ $flag }}</span>
                                        @endforeach
                                        @if(!empty($row['alliance_tax_exempt']))
                                            <span class="badge" style="background: rgba(23,162,184,0.2); color: #5bc0de; font-size: 0.6rem; border: 1px solid rgba(23,162,184,0.4);" title="{{ trans('hr-manager::corp-health.alliance_tax_exempt_title') }}"><i class="fas fa-handshake"></i> {{ trans('hr-manager::corp-health.alliance_tax_exempt_badge') }}</span>
                                        @endif
                                        @if($row['net_position'] !== null)
                                            <small style="color: {{ $row['net_position'] < 0 ? '#fca5a5' : 'var(--hr-text-muted)' }}; min-width: 60px; text-align: right;">{{ $iskFmt($row['net_position']) }}</small>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-muted mb-0">{{ trans('hr-manager::corp-health.wallet_insights_clean') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif

                @if(!empty($wi_free['available']))
                    <div class="col-lg-6">
                        <div class="card card-dark mb-3">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-couch"></i> {{ trans('hr-manager::corp-health.freeloaders_heading') }}</h3>
                            </div>
                            <div class="card-body">
                                <small class="d-block mb-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.freeloaders_help') }}</small>
                                @forelse($wi_free['list'] as $row)
                                    <div class="d-flex align-items-center mb-1" style="gap: 8px; padding: 5px 8px; background: rgba(255,255,255,0.03); border-radius: 4px;">
                                        <img src="https://images.evetech.net/characters/{{ $row['character_id'] }}/portrait?size=32" style="width: 28px; height: 28px; border-radius: 50%;" onerror="this.style.display='none'">
                                        @if(!empty($row['entity_type']))
                                            <span style="flex: 1; color: var(--hr-text-white); font-size: 0.9rem;">{{ $row['name'] }}</span>
                                            <span class="badge" style="background: rgba(102,126,234,0.2); color: #a5b4fc; font-size: 0.6rem; border: 1px solid rgba(102,126,234,0.4);"><i class="fas fa-{{ $row['entity_type'] === 'alliance' ? 'sitemap' : 'building' }}"></i> {{ $row['entity_type'] === 'alliance' ? trans('hr-manager::corp-health.entity_alliance') : trans('hr-manager::corp-health.entity_corp') }}</span>
                                        @else
                                            <a href="{{ route('hr-manager.members.show', $row['character_id']) }}" style="flex: 1; color: var(--hr-text-white); text-decoration: none; font-size: 0.9rem;">{{ $row['name'] }}</a>
                                        @endif
                                        @if($row['active_but_not_paying'])
                                            <span class="badge" style="background: rgba(220,53,69,0.2); color: #fca5a5; font-size: 0.65rem;" title="{{ trans('hr-manager::corp-health.freeloader_active_help') }}">{{ trans('hr-manager::corp-health.freeloader_active') }}</span>
                                        @endif
                                        <small style="color: var(--hr-text-muted); min-width: 60px; text-align: right;">{{ $iskFmt($row['contributed']) }}</small>
                                    </div>
                                @empty
                                    <p class="text-muted mb-0">{{ trans('hr-manager::corp-health.wallet_insights_clean') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif

                @if(!empty($wi_loyal['available']) && !empty($wi_loyal['list']))
                    <div class="col-lg-6">
                        <div class="card card-dark mb-3" style="border-left: 4px solid var(--hr-success, #28a745);">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-medal"></i> {{ trans('hr-manager::corp-health.loyalty_heading') }}</h3>
                            </div>
                            <div class="card-body">
                                <small class="d-block mb-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.loyalty_help') }}</small>
                                @foreach($wi_loyal['list'] as $row)
                                    <div class="d-flex align-items-center mb-1" style="gap: 8px; padding: 5px 8px; background: rgba(40,167,69,0.07); border-radius: 4px;">
                                        <img src="https://images.evetech.net/characters/{{ $row['character_id'] }}/portrait?size=32" style="width: 28px; height: 28px; border-radius: 50%;" onerror="this.style.display='none'">
                                        <a href="{{ route('hr-manager.members.show', $row['character_id']) }}" style="flex: 1; color: var(--hr-text-white); text-decoration: none; font-size: 0.9rem;">{{ $row['name'] }}</a>
                                        @if($row['active_months'] > 0)
                                            <small style="color: var(--hr-text-muted);">{{ $row['active_months'] }}mo</small>
                                        @endif
                                        <small style="color: #6ee7b7; min-width: 60px; text-align: right;">+{{ $iskFmt($row['net_position']) }}</small>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            @if(!empty($wi_out['available']))
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-sign-out-alt"></i> {{ trans('hr-manager::corp-health.outflows_heading') }}</h3>
                        <div class="card-tools">
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.outflows_window', ['n' => $wi_out['months']]) }}</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <small class="d-block mb-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.outflows_help') }}</small>
                        @if(empty($wi_out['top_recipients']))
                            <p class="text-muted mb-0">{{ trans('hr-manager::corp-health.outflows_none') }}</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-dark table-sm mb-2">
                                    <thead>
                                        <tr>
                                            <th>{{ trans('hr-manager::corp-health.outflows_recipient') }}</th>
                                            <th class="text-right">{{ trans('hr-manager::corp-health.outflows_amount') }}</th>
                                            <th class="text-right">{{ trans('hr-manager::corp-health.outflows_count') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($wi_out['top_recipients'] as $rec)
                                            <tr>
                                                <td>
                                                    <img src="https://images.evetech.net/characters/{{ $rec['character_id'] }}/portrait?size=32" style="width: 24px; height: 24px; border-radius: 50%; margin-right: 6px;" onerror="this.style.display='none'">
                                                    <a href="{{ route('hr-manager.members.show', $rec['character_id']) }}" style="color: var(--hr-text-white); text-decoration: none;">{{ $rec['character_name'] }}</a>
                                                </td>
                                                <td class="text-right" style="color: #fca5a5;">{{ $iskFmt($rec['amount']) }}</td>
                                                <td class="text-right" style="color: var(--hr-text-muted);">{{ $rec['count'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if($wi_out['unattributed_amount'] > 0)
                                <small style="color: var(--hr-text-muted);">
                                    <i class="fas fa-question-circle"></i>
                                    {{ trans('hr-manager::corp-health.outflows_unattributed', ['amount' => $iskFmt($wi_out['unattributed_amount']), 'count' => $wi_out['unattributed_count']]) }}
                                </small>
                            @endif
                        @endif
                    </div>
                </div>
            @endif

            {{-- Members with recent wallet flags — ALL members (incl.
                 unregistered), from HR event history. The corp-wide drill-down
                 the assessment-cache cards above can't show. --}}
            @php $fm = $corpStatus['flagged_members'] ?? null; @endphp
            @if(!empty($fm['available']) && !empty($fm['list']))
                <div class="card card-dark mb-3" style="border-left: 4px solid var(--hr-warning, #ffc107);">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-flag"></i> {{ trans('hr-manager::corp-health.flagged_members_heading') }}</h3>
                        <div class="card-tools"><span class="badge" style="background: rgba(255,193,7,0.22); color:#ffe08a;">{{ $fm['total'] }}</span></div>
                    </div>
                    <div class="card-body p-0">
                        <p class="px-3 pt-3 mb-2" style="color: var(--hr-text-muted); font-size: 0.85rem;">{{ trans('hr-manager::corp-health.flagged_members_help') }}</p>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr>
                                    <th>{{ trans('hr-manager::corp-health.col_player') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.flagged_signals') }}</th>
                                    <th>{{ trans('hr-manager::corp-health.flagged_last') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach($fm['list'] as $m)
                                        <tr>
                                            <td>
                                                <img src="https://images.evetech.net/characters/{{ $m['character_id'] }}/portrait?size=32" style="width:26px;height:26px;border-radius:50%;vertical-align:middle;margin-right:6px;" alt="">
                                                <strong>{{ $m['name'] }}</strong>
                                                @if(!$m['is_registered'])
                                                    <span class="badge" style="background: rgba(255,193,7,0.25); color:#ffe08a; font-size:0.6rem;"><i class="fas fa-user-slash"></i> {{ trans('hr-manager::corp-health.not_in_seat') }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @foreach($m['types'] as $t)
                                                    <span class="badge badge-hr" style="font-size:0.65rem;">{{ trans('hr-manager::corp-health.sig_' . $t) }}</span>
                                                @endforeach
                                            </td>
                                            <td><small style="color: var(--hr-text-muted);">{{ \Carbon\Carbon::parse($m['last_at'])->diffForHumans() }}</small></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        @else
            <p class="text-muted">{{ trans('hr-manager::corp-health.economy_no_data') }}</p>
        @endif

        {{-- Blueprint engagement — who uses the blueprint library, fulfilment
             vs rejection, pending backlog. From Blueprint Manager via MC. --}}
        @if(!empty($blueprintCorpSummary['available']) && ($blueprintCorpSummary['total_requests'] ?? 0) > 0)
            @php
                $bps = $blueprintCorpSummary;
                $bpsRej = (float) ($bps['rejection_rate'] ?? 0);
                $bpsOldest = !empty($bps['oldest_pending']) ? \Carbon\Carbon::parse($bps['oldest_pending']) : null;
            @endphp
            <div class="card card-dark mb-3" style="border-left: 4px solid #8b5cf6;">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-drafting-compass"></i> {{ trans('hr-manager::corp-health.bp_heading') }}</h3>
                    <div class="card-tools">
                        <span class="badge" style="background: rgba(139,92,246,0.2); color: #c4b5fd;">{{ $bps['unique_requesters'] }} {{ trans('hr-manager::corp-health.bp_requesters') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-text-white);"><strong>{{ $bps['total_requests'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.bp_total') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-warning, #ffc107);"><strong>{{ $bps['pending'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.bp_pending') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-success, #28a745);"><strong>{{ $bps['fulfilled'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.bp_fulfilled') }}</small></div>
                        <div class="col"><div style="font-size: 1.8rem; color: var(--hr-danger, #dc3545);"><strong>{{ $bps['rejected'] }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.bp_rejected') }} ({{ $bpsRej }}%)</small></div>
                    </div>
                    @if($bpsOldest && ($bps['pending'] ?? 0) > 0)
                        <p class="text-center mt-2 mb-2" style="color: var(--hr-text-muted); font-size: 0.8rem;"><i class="fas fa-hourglass-half"></i> {{ trans('hr-manager::corp-health.bp_oldest_pending', ['when' => $bpsOldest->diffForHumans()]) }}</p>
                    @endif
                    @if(!empty($bps['top_requesters']))
                        <table class="table table-sm" style="margin-top: 8px;">
                            <thead><tr>
                                <th>{{ trans('hr-manager::corp-health.bp_col_member') }}</th>
                                <th class="text-right">{{ trans('hr-manager::corp-health.bp_total') }}</th>
                                <th class="text-right">{{ trans('hr-manager::corp-health.bp_fulfilled') }}</th>
                                <th class="text-right">{{ trans('hr-manager::corp-health.bp_rejected') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach($bps['top_requesters'] as $tr)
                                    <tr>
                                        <td>{{ $tr['character_name'] }}</td>
                                        <td class="text-right">{{ $tr['total_requests'] }}</td>
                                        <td class="text-right" style="color: #6ee7b7;">{{ $tr['fulfilled'] }}</td>
                                        <td class="text-right" style="color: #fca5a5;">{{ $tr['rejected'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                    <small class="d-block text-center mt-2" style="color: var(--hr-text-muted); font-size: 0.75rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.bp_footnote') }}</small>
                </div>
            </div>
        @endif
    @endif

    {{-- =================================================================
         RECRUITMENT TAB — pipeline health.
         ================================================================= --}}
    @if($activeTab === 'recruitment')
        <div class="row">
            <div class="col-md-6">
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-import"></i> {{ trans('hr-manager::corp-health.applications_heading') }}</h3>
                    </div>
                    <div class="card-body">
                        @php $af = $corpStatus['application_funnel']; @endphp
                        <div class="text-center mb-2">
                            <div style="font-size: 1.8rem; color: var(--hr-warning, #ffc107);"><strong>{{ $af['pending'] }}</strong></div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.app_pending') }}</small>
                        </div>
                        <hr style="border-color: var(--hr-border); margin: 8px 0;">
                        <small class="d-block" style="color: var(--hr-text-light);"><span class="text-success">{{ $af['accepted_30d'] }}</span> {{ trans('hr-manager::corp-health.app_accepted_30d') }}</small>
                        <small class="d-block" style="color: var(--hr-text-light);"><span class="text-danger">{{ $af['rejected_30d'] }}</span> {{ trans('hr-manager::corp-health.app_rejected_30d') }}</small>
                        <small class="d-block" style="color: var(--hr-text-light);"><span class="text-muted">{{ $af['withdrew_30d'] }}</span> {{ trans('hr-manager::corp-health.app_withdrew_30d') }}</small>
                        @if($af['acceptance_pct'] !== null)
                            <hr style="border-color: var(--hr-border); margin: 8px 0;">
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.app_acceptance_pct', ['n' => $af['acceptance_pct']]) }}</small>
                        @endif
                        @php $anj = $corpStatus['accepted_not_joined'] ?? null; @endphp
                        @if(!empty($anj['available']) && ($anj['total'] ?? 0) > 0)
                            <hr style="border-color: var(--hr-border); margin: 8px 0;">
                            <small class="d-block" style="color: var(--hr-warning);">
                                <i class="fas fa-ghost"></i>
                                <strong>{{ $anj['total'] }}</strong> {{ trans('hr-manager::corp-health.accepted_not_joined') }}
                            </small>
                            @if(($anj['ghosted'] ?? 0) > 0)
                                <small class="d-block" style="color: var(--hr-text-muted); padding-left: 18px;">
                                    {{ trans('hr-manager::corp-health.accepted_not_joined_ghosted', ['n' => $anj['ghosted']]) }}
                                </small>
                            @endif
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bullhorn"></i> {{ trans('hr-manager::corp-health.recruitment_heading') }}</h3>
                    </div>
                    <div class="card-body">
                        @php $rs = $corpStatus['recruitment_summary']; @endphp
                        @if(empty($rs['available']) || empty($rs['has_landings']))
                            <p class="text-muted mb-0"><small>{{ trans('hr-manager::corp-health.recruitment_none') }}</small></p>
                        @else
                            <div class="text-center mb-2">
                                <div style="font-size: 1.8rem; color: var(--hr-text-white);"><strong>{{ number_format($rs['views_30d']) }}</strong></div>
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.recruit_views_30d') }}</small>
                            </div>
                            <hr style="border-color: var(--hr-border); margin: 8px 0;">
                            <small class="d-block" style="color: var(--hr-text-light);">{{ $rs['landings_published'] }} / {{ $rs['landings_total'] }} {{ trans('hr-manager::corp-health.recruit_landings') }}</small>
                            <small class="d-block" style="color: var(--hr-text-light);">{{ number_format($rs['lifetime_views']) }} {{ trans('hr-manager::corp-health.recruit_lifetime_views') }}</small>
                            <small class="d-block" style="color: var(--hr-text-light);">{{ number_format($rs['lifetime_apps']) }} {{ trans('hr-manager::corp-health.recruit_lifetime_apps') }}</small>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Recruitment stats: submission trend, decision mix, throughput --}}
        @php $rstat = $corpStatus['recruitment_stats'] ?? ['available' => false]; @endphp
        @if(!empty($rstat['available']))
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-bar"></i> {{ trans('hr-manager::corp-health.rstat_heading') }}</h3>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-2">
                        <div class="col"><div style="font-size: 1.6rem; color: var(--hr-text-white);"><strong>{{ number_format($rstat['sub_30d']) }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.rstat_sub_30d') }}</small></div>
                        <div class="col"><div style="font-size: 1.6rem; color: var(--hr-text-white);"><strong>{{ number_format($rstat['sub_90d']) }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.rstat_sub_90d') }}</small></div>
                        <div class="col"><div style="font-size: 1.6rem; color: var(--hr-text-white);"><strong>{{ number_format($rstat['lifetime']) }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.rstat_lifetime') }}</small></div>
                    </div>
                    @if($rstat['accept_pct'] !== null)
                        <hr style="border-color: var(--hr-border); margin: 8px 0;">
                        <div class="d-flex flex-wrap" style="gap: 14px; font-size: 0.85rem;">
                            <span style="color: var(--hr-text-light);"><span class="text-success"><strong>{{ $rstat['accept_pct'] }}%</strong></span> {{ trans('hr-manager::corp-health.rstat_accepted') }}</span>
                            <span style="color: var(--hr-text-light);"><span class="text-danger"><strong>{{ $rstat['reject_pct'] }}%</strong></span> {{ trans('hr-manager::corp-health.rstat_rejected') }}</span>
                            <span style="color: var(--hr-text-light);"><span class="text-muted"><strong>{{ $rstat['withdraw_pct'] }}%</strong></span> {{ trans('hr-manager::corp-health.rstat_withdrawn') }}</span>
                        </div>
                        <small class="d-block mt-1" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.rstat_decision_basis', ['a' => $rstat['accepted'], 'r' => $rstat['rejected'], 'w' => $rstat['withdrawn']]) }}</small>
                    @endif
                    @if($rstat['avg_decision_days'] !== null || $rstat['oldest_pending_days'] !== null)
                        <hr style="border-color: var(--hr-border); margin: 8px 0;">
                        <div class="d-flex flex-wrap" style="gap: 18px; font-size: 0.85rem;">
                            @if($rstat['avg_decision_days'] !== null)
                                <span style="color: var(--hr-text-light);"><i class="fas fa-stopwatch" style="color: var(--hr-text-muted);"></i> {{ trans('hr-manager::corp-health.rstat_avg_decision', ['n' => $rstat['avg_decision_days']]) }}</span>
                            @endif
                            @if($rstat['oldest_pending_days'] !== null)
                                <span style="color: {{ $rstat['oldest_pending_days'] >= 14 ? 'var(--hr-warning, #ffc107)' : 'var(--hr-text-light)' }};"><i class="fas fa-hourglass-half"></i> {{ trans('hr-manager::corp-health.rstat_oldest_pending', ['n' => $rstat['oldest_pending_days']]) }}</span>
                            @endif
                        </div>
                    @endif
                    @if(!empty($rstat['top_recruiters']))
                        <hr style="border-color: var(--hr-border); margin: 8px 0;">
                        <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.7rem;">{{ trans('hr-manager::corp-health.rstat_top_recruiters') }}</small>
                        <div class="mt-1">
                            @foreach($rstat['top_recruiters'] as $rec)
                                <span class="badge" style="background: rgba(102,126,234,0.18); color: #c7d2fe;">{{ $rec['name'] }} <strong>{{ $rec['count'] }}</strong></span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endif

    {{-- ===================== PURGE TAB ===================== --}}
    @if($activeTab === 'purge')
        @php $pb = $corpStatus['purge_board'] ?? ['available' => false, 'entries' => [], 'counts' => []]; @endphp

        <div class="d-flex flex-wrap align-items-center mb-2" style="gap: 12px;">
            <h3 style="color: var(--hr-text-white); margin: 0; font-size: 1.1rem;"><i class="fas fa-user-slash"></i> {{ trans('hr-manager::corp-health.purge_heading') }}</h3>
            @if(!empty($pb['available']) && !empty($pb['entries']))
                <small style="color: var(--hr-text-muted);">
                    <strong style="color: var(--hr-text-white);">{{ $pb['counts']['total'] }}</strong> {{ trans('hr-manager::corp-health.purge_scheduled') }} &middot;
                    <strong style="color: var(--hr-danger, #dc3545);">{{ $pb['counts']['overdue'] }}</strong> {{ trans('hr-manager::corp-health.purge_overdue') }} &middot;
                    <strong style="color: var(--hr-success, #28a745);">{{ $pb['counts']['left'] }}</strong> {{ trans('hr-manager::corp-health.purge_left') }}
                </small>
            @endif
        </div>
        <p style="color: var(--hr-text-muted); font-size: 0.85rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.purge_intro') }}</p>

        @if(empty($pb['available']))
            <div class="warning-box"><i class="fas fa-clock"></i> {{ trans('hr-manager::corp-health.purge_unavailable') }}</div>
        @elseif(empty($pb['entries']))
            <p class="text-muted mb-0">{{ trans('hr-manager::corp-health.purge_none') }}</p>
        @else
            @foreach($pb['entries'] as $e)
                <div class="card card-dark mb-3" style="border-left: 4px solid {{ $e['left_corp'] ? '#28a745' : ($e['is_overdue'] ? '#dc3545' : '#f59e0b') }};">
                    <div class="card-header">
                        <h3 class="card-title" style="font-size: 1rem;">
                            <i class="fas fa-user"></i> {{ $e['player_name'] }}
                            @if($e['left_corp'])
                                <span class="badge" style="background: rgba(40,167,69,0.2); color: #28a745; border: 1px solid rgba(40,167,69,0.4);"><i class="fas fa-sign-out-alt"></i> {{ trans('hr-manager::corp-health.purge_badge_left') }}</span>
                            @elseif($e['is_overdue'])
                                <span class="badge" style="background: rgba(220,53,69,0.2); color: #fca5a5; border: 1px solid rgba(220,53,69,0.4);"><i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::corp-health.purge_badge_overdue') }}</span>
                            @endif
                        </h3>
                        <div class="card-tools">
                            <small style="color: var(--hr-text-muted);">
                                {{ trans('hr-manager::corp-health.purge_remove_by') }}:
                                @if($e['deadline'])
                                    <strong style="color: var(--hr-text-light);">{{ $e['deadline']->toDateString() }}</strong>
                                    @if($e['deadline_human'])<span>({{ $e['deadline_human'] }})</span>@endif
                                @else
                                    <strong style="color: var(--hr-warning, #ffc107);">{{ trans('hr-manager::corp-health.purge_no_date') }}</strong>
                                @endif
                            </small>
                        </div>
                    </div>
                    <div class="card-body">
                        @if(!empty($e['characters']))
                            <div class="mb-2">
                                @foreach($e['characters'] as $c)
                                    <span class="badge" style="background: rgba(255,255,255,0.06); color: var(--hr-text-light);">{{ $c['name'] }}</span>
                                @endforeach
                            </div>
                        @endif
                        <small class="d-block mb-2" style="color: var(--hr-text-muted);">
                            {{ trans('hr-manager::corp-health.purge_marked') }} {{ optional($e['marked_at'])->diffForHumans() }}@if($e['reason']) &middot; {{ $e['reason'] }}@endif
                            @if($e['left_corp'] && $e['left_corp_to']) &middot; {{ trans('hr-manager::corp-health.purge_went_to') }} <code>#{{ $e['left_corp_to'] }}</code>@endif
                        </small>

                        {{-- Per-character kick list. EVE kicks + strips roles one
                             character at a time, so each alt's own roles/titles
                             are listed individually (account aggregate isn't
                             actionable in-game). --}}
                        @if(!empty($e['ingame_by_character']))
                            <div class="mb-3" style="background: rgba(0,0,0,0.18); border-radius: 6px; padding: 8px 10px;">
                                <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.7rem;"><i class="fas fa-sitemap"></i> {{ trans('hr-manager::corp-health.purge_kick_list') }}</small>
                                @foreach($e['ingame_by_character'] as $ch)
                                    <div style="padding: 5px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <span style="color: var(--hr-text-white); font-weight: 600;"><i class="fas fa-user-astronaut"></i> {{ $ch['name'] }}</span>
                                        @if($ch['is_director'])<span class="badge" style="background: rgba(220,53,69,0.2); color: #fca5a5;"><i class="fas fa-star"></i> Director</span>@endif
                                        <div style="margin-top: 3px;">
                                            @foreach($ch['critical_roles'] as $role)
                                                <span class="badge" style="background: rgba(220,53,69,0.2); color: #fca5a5; border: 1px solid rgba(220,53,69,0.45);"><i class="fas fa-exclamation-circle"></i> {{ str_replace('_', ' ', $role) }}</span>
                                            @endforeach
                                            @foreach(array_diff($ch['roles'], $ch['critical_roles']) as $role)
                                                <span class="badge" style="background: rgba(255,255,255,0.06); color: var(--hr-text-light);">{{ str_replace('_', ' ', $role) }}</span>
                                            @endforeach
                                            @foreach($ch['titles'] as $title)
                                                <span class="badge" style="background: rgba(102,126,234,0.18); color: #c7d2fe;"><i class="fas fa-tag"></i> {{ $title['name'] }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Reminder ladder --}}
                        <div class="mb-3">
                            <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.7rem;">{{ trans('hr-manager::corp-health.purge_reminders') }}</small>
                            <div class="mt-1">
                                @foreach(['t7' => 'T-7d', 't3' => 'T-3d', 't48' => 'T-48h', 't0' => 'T-0'] as $mk => $mlabel)
                                    <span class="badge" style="background: {{ $e['reminders'][$mk] ? 'rgba(102,126,234,0.28)' : 'rgba(255,255,255,0.05)' }}; color: {{ $e['reminders'][$mk] ? '#c7d2fe' : 'var(--hr-text-muted)' }};">
                                        {{ $mlabel }} @if($e['reminders'][$mk])<i class="fas fa-check"></i>@endif
                                    </span>
                                @endforeach
                            </div>
                        </div>

                        {{-- Removal steps --}}
                        <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.7rem;">{{ trans('hr-manager::corp-health.purge_steps') }}</small>
                        <div class="row mt-1 mb-2">
                            <div class="col-md-6 mb-2">
                                <form method="POST" action="{{ route('hr-manager.corp-health.purge-step', $e['status_id']) }}">
                                    @csrf
                                    <input type="hidden" name="step" value="roles">
                                    <input type="hidden" name="done" value="{{ $e['steps']['roles'] ? '0' : '1' }}">
                                    <button type="submit" class="btn btn-sm btn-block {{ $e['steps']['roles'] ? 'btn-hr-secondary' : 'btn-hr-primary' }}">
                                        <i class="{{ $e['steps']['roles'] ? 'fas fa-check-square' : 'far fa-square' }}"></i> {{ trans('hr-manager::corp-health.purge_step_roles') }}
                                    </button>
                                </form>
                                @if($e['steps']['roles'])<small class="d-block text-center" style="color: var(--hr-text-muted);">{{ $e['steps']['roles']->diffForHumans() }}</small>@endif
                            </div>
                            <div class="col-md-6 mb-2">
                                <span class="btn btn-sm btn-block" style="cursor: default; {{ $e['steps']['corp'] ? 'background: rgba(40,167,69,0.18); color: #28a745;' : 'background: rgba(255,255,255,0.04); color: var(--hr-text-muted);' }}">
                                    <i class="fas {{ $e['steps']['corp'] ? 'fa-check-square' : 'fa-hourglass-half' }}"></i> {{ trans('hr-manager::corp-health.purge_step_corp') }}
                                </span>
                                @if($e['steps']['corp'])
                                    <small class="d-block text-center" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.purge_detected') }} {{ $e['steps']['corp']->diffForHumans() }}</small>
                                @else
                                    <small class="d-block text-center" style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.purge_step_auto') }}</small>
                                @endif
                            </div>
                        </div>

                        {{-- Notes --}}
                        <form method="POST" action="{{ route('hr-manager.corp-health.purge-note', $e['status_id']) }}">
                            @csrf
                            <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.7rem;">{{ trans('hr-manager::corp-health.purge_notes_label') }}</small>
                            <textarea name="note" class="form-control form-control-sm mt-1" rows="2" placeholder="{{ trans('hr-manager::corp-health.purge_notes_ph') }}">{{ $e['notes'] }}</textarea>
                            <button type="submit" class="btn btn-sm btn-hr-secondary mt-1"><i class="fas fa-save"></i> {{ trans('hr-manager::corp-health.purge_save_note') }}</button>
                        </form>
                    </div>
                </div>
            @endforeach
        @endif
    @endif

    {{-- ===================== STRUCTURE COMPLIANCE TAB ===================== --}}
    @if($activeTab === 'structure-compliance')
        @php $sc = $structureCompliance ?? ['available' => false]; @endphp

        @if(($sc['reason'] ?? '') === 'sm_absent')
            <div class="warning-box"><i class="fas fa-cube"></i> {{ trans('hr-manager::corp-health.sc_needs_sm') }}</div>
        @else

        <div class="alert" style="background: rgba(23,162,184,0.12); border: 1px solid rgba(23,162,184,0.3); color: var(--hr-text-light);">
            <strong><i class="fas fa-building"></i> {{ trans('hr-manager::corp-health.sc_banner_title') }}</strong>
            <p class="mb-0 mt-1" style="font-size: 0.86rem;">{{ trans('hr-manager::corp-health.sc_banner_body') }}</p>
        </div>

        <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 10px;">
            @if(\Illuminate\Support\Facades\Route::has('structure-manager.doctrines.index'))
                <a href="{{ route('structure-manager.doctrines.index', ['corporation_id' => $corporationId]) }}" class="btn btn-sm btn-hr-primary btn-icon" target="_blank" rel="noopener"><i class="fas fa-cog"></i> {{ trans('hr-manager::corp-health.sc_manage') }}</a>
            @endif
            @if(!empty($sc['available']))
                <small style="color: var(--hr-text-muted);">
                    {{ trans('hr-manager::corp-health.sc_scope_' . $sc['scope_mode']) }} &middot;
                    {{ trans('hr-manager::corp-health.sc_doctrines_n', ['n' => $sc['doctrine_count']]) }}
                    @if($sc['offline_strict']) &middot; {{ trans('hr-manager::corp-health.sc_offline_strict') }}@endif
                </small>
            @endif
        </div>

        @if(empty($sc['available']))
            <div class="warning-box"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.sc_unavailable') }}</div>
        @else
            @if(!$sc['has_assets'])
                <div class="warning-box"><i class="fas fa-key"></i> {{ trans('hr-manager::corp-health.sc_no_assets') }}</div>
            @endif

            @php $sm = $sc['summary']; @endphp
            <div class="row mb-3">
                @foreach([
                    'compliant'          => ['#28a745', trans('hr-manager::corp-health.sc_compliant')],
                    'compliant_upgraded' => ['#3bc47a', trans('hr-manager::corp-health.sc_compliant_upgraded')],
                    'partial'            => ['#ffc107', trans('hr-manager::corp-health.sc_partial')],
                    'non_compliant'      => ['#dc3545', trans('hr-manager::corp-health.sc_non_compliant')],
                    'no_doctrine'        => ['#9ca3af', trans('hr-manager::corp-health.sc_no_doctrine')],
                    'no_data'            => ['#6c757d', trans('hr-manager::corp-health.sc_no_data')],
                ] as $k => $meta)
                    <div class="col-md-2 col-4 mb-2">
                        <div style="background: rgba(255,255,255,0.03); border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: {{ $meta[0] }};">{{ $sm[$k] ?? 0 }}</div>
                            <small style="color: var(--hr-text-muted);">{{ $meta[1] }}</small>
                        </div>
                    </div>
                @endforeach
            </div>

            @if(empty($sc['structures']))
                <p class="text-muted mb-0">{{ trans('hr-manager::corp-health.sc_no_structures') }}</p>
            @else
                @foreach($sc['structures'] as $st)
                    @php
                        $statusMeta = [
                            'compliant'          => ['#28a745', 'fa-check', trans('hr-manager::corp-health.sc_compliant')],
                            'compliant_upgraded' => ['#3bc47a', 'fa-arrow-up', trans('hr-manager::corp-health.sc_compliant_upgraded')],
                            'partial'            => ['#ffc107', 'fa-exclamation-triangle', trans('hr-manager::corp-health.sc_partial')],
                            'non_compliant'      => ['#dc3545', 'fa-times', trans('hr-manager::corp-health.sc_non_compliant')],
                            'no_doctrine'        => ['#9ca3af', 'fa-question', trans('hr-manager::corp-health.sc_no_doctrine')],
                            'no_data'            => ['#6c757d', 'fa-eye-slash', trans('hr-manager::corp-health.sc_no_data')],
                        ][$st['status']] ?? ['#9ca3af', 'fa-circle', $st['status']];
                    @endphp
                    {{-- Default-open the structures that have no comparison table
                         (no doctrine / no data) so their short, actionable message
                         (e.g. the band/scope mismatch diagnosis) is visible without
                         expanding. Comparison tables stay collapsed. --}}
                    <details class="card card-dark mb-2 sc-structure" style="border-left: 4px solid {{ $statusMeta[0] }};" {{ empty($st['sections']) ? 'open' : '' }}>
                        <summary class="card-header sc-summary" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                            <span style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis;">
                                <i class="fas fa-chevron-right sc-chevron" style="color: var(--hr-text-muted); font-size: 0.72rem; margin-right: 5px;"></i>
                                <i class="fas fa-building"></i> <strong style="font-size: 0.95rem;">{{ $st['structure_name'] }}</strong>
                                <small style="color: var(--hr-text-muted);">&middot; {{ $st['structure_type'] }} &middot; {{ $st['system'] }} &middot; {{ trans('hr-manager::corp-health.sc_band_' . $st['band']) }}@if($st['doctrine_name']) &middot; {{ $st['doctrine_name'] }}@endif</small>
                            </span>
                            <span class="badge" style="background: {{ $statusMeta[0] }}22; color: {{ $statusMeta[0] }}; border: 1px solid {{ $statusMeta[0] }}66; white-space: nowrap;">
                                <i class="fas {{ $statusMeta[1] }}"></i> {{ $statusMeta[2] }}
                            </span>
                        </summary>
                        <div class="card-body">
                            @if(!empty($st['reasons']))
                                <div class="mt-2">
                                    @foreach($st['reasons'] as $r)
                                        <span class="badge" style="background: rgba(220,53,69,0.18); color: #fca5a5;">{{ trans('hr-manager::corp-health.sc_reason_' . $r) }}</span>
                                    @endforeach
                                </div>
                            @endif

                            @if(!empty($st['sections']))
                                @php
                                    // state -> [icon, colour, optional diff-label key]
                                    $stateMeta = [
                                        'exact'      => ['fa-check',               '#28a745', null],
                                        'upgraded'   => ['fa-arrow-up',            '#3bc47a', 'sc_diff_upgraded'],
                                        'lower_tier' => ['fa-exclamation-triangle','#f0ad4e', 'sc_diff_lower'],
                                        'mismatch'   => ['fa-exchange-alt',        '#dc3545', 'sc_diff_mismatch'],
                                        'missing'    => ['fa-times',               '#dc3545', 'sc_diff_missing'],
                                        'extra'      => ['fa-plus',                '#6c9bd1', 'sc_diff_extra'],
                                        'empty'      => ['fa-minus',               '#5a5a5a', null],
                                    ];
                                    $emptyLabel = trans('hr-manager::corp-health.sc_empty_slot');
                                @endphp
                                <div class="mt-2" style="background: rgba(0,0,0,0.18); border-radius: 6px; padding: 6px 8px; overflow-x: auto;">
                                    <table style="width: 100%; font-family: monospace; font-size: 0.8rem; border-collapse: collapse;">
                                        <thead>
                                            <tr style="color: var(--hr-text-muted); text-align: left; font-size: 0.64rem; text-transform: uppercase; letter-spacing: 0.4px;">
                                                <th style="padding: 2px 10px 4px 4px; font-weight: 600;">{{ trans('hr-manager::corp-health.sc_col_current') }}</th>
                                                <th style="padding: 2px 10px 4px 4px; font-weight: 600;">{{ trans('hr-manager::corp-health.sc_col_required') }}</th>
                                                <th style="padding: 2px 4px 4px; font-weight: 600;">{{ trans('hr-manager::corp-health.sc_col_diff') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($st['sections'] as $section)
                                                <tr><td colspan="3" style="padding: 6px 4px 1px; color: var(--hr-text-muted); font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.4px;">{{ trans('hr-manager::corp-health.sc_section_' . $section['section']) }} <span style="opacity: 0.55;">({{ $section['slot_count'] }})</span></td></tr>
                                                @foreach($section['rows'] as $row)
                                                    @php $sm = $stateMeta[$row['state']] ?? ['fa-circle', '#9ca3af', null]; @endphp
                                                    <tr style="{{ $row['state'] === 'empty' ? 'opacity: 0.4;' : '' }}">
                                                        <td style="padding: 1px 10px 1px 4px; color: var(--hr-text-muted);">{{ $row['current'] ?? $emptyLabel }}</td>
                                                        <td style="padding: 1px 10px 1px 4px; color: var(--hr-text-light);">{{ $row['required'] ?? $emptyLabel }}</td>
                                                        <td style="padding: 1px 4px; color: {{ $sm[1] }}; white-space: nowrap;"><i class="fas {{ $sm[0] }}"></i>@if($sm[2]) {{ trans('hr-manager::corp-health.' . $sm[2]) }}@endif</td>
                                                    </tr>
                                                @endforeach
                                            @endforeach
                                            @if(!empty($st['optional']))
                                                <tr><td colspan="3" style="padding: 6px 4px 1px; color: var(--hr-text-muted); font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.4px;">{{ trans('hr-manager::corp-health.sc_section_optional') }}</td></tr>
                                                @foreach($st['optional'] as $op)
                                                    <tr>
                                                        <td style="padding: 1px 10px 1px 4px; color: var(--hr-text-muted);">{{ $op['type_name'] }} x{{ $op['quantity'] }}</td>
                                                        <td style="padding: 1px 10px 1px 4px; color: var(--hr-text-muted);">&mdash;</td>
                                                        <td style="padding: 1px 4px; color: var(--hr-text-muted); white-space: nowrap;">{{ trans('hr-manager::corp-health.sc_optional_tag') }}</td>
                                                    </tr>
                                                @endforeach
                                            @endif
                                        </tbody>
                                    </table>
                                </div>
                            @elseif($st['status'] === 'no_doctrine')
                                @php $diag = $st['diag'] ?? null; @endphp
                                @if($diag && !empty($diag['existing']))
                                    <div class="mt-2" style="background: rgba(240,173,78,0.1); border: 1px solid rgba(240,173,78,0.3); border-radius: 6px; padding: 8px 10px;">
                                        <div style="color: var(--hr-warning, #ffc107); font-size: 0.85rem;"><i class="fas fa-exclamation-triangle"></i>
                                            {{ trans('hr-manager::corp-health.sc_diag_' . $diag['reason'], ['band' => trans('hr-manager::corp-health.sc_band_' . $diag['band'])]) }}
                                        </div>
                                        <div class="mt-1" style="font-size: 0.78rem; color: var(--hr-text-muted);">
                                            {{ trans('hr-manager::corp-health.sc_diag_found') }}
                                            @foreach($diag['existing'] as $ex)
                                                <span class="badge badge-secondary mr-1">{{ $ex['name'] }} &middot; {{ $ex['scope_type'] === 'alliance' ? trans('hr-manager::corp-health.sc_scope_alliance') : trans('hr-manager::corp-health.sc_scope_corp') }} &middot; {{ trans('hr-manager::corp-health.sc_band_' . $ex['band']) }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @else
                                    <p class="mb-0 mt-2"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.sc_no_doctrine_hint') }}</small></p>
                                @endif
                            @elseif($st['status'] === 'no_data')
                                <p class="mb-0 mt-2"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.sc_no_data_hint') }}</small></p>
                            @endif

                            @php
                                $fits = [
                                    ['label' => trans('hr-manager::corp-health.sc_fit_current'),     'raw' => $st['current_raw']],
                                    ['label' => trans('hr-manager::corp-health.sc_fit_recommended'), 'raw' => $st['recommended_raw']],
                                    ['label' => trans('hr-manager::corp-health.sc_fit_missing'),     'raw' => $st['missing_raw']],
                                ];
                                $fits = array_values(array_filter($fits, fn ($f) => !empty($f['raw'])));
                            @endphp
                            @if(!empty($fits))
                                <div class="mt-2" style="display: flex; flex-direction: column; gap: 5px;">
                                    @foreach($fits as $fit)
                                        <div class="sc-copy-wrap d-flex flex-wrap align-items-center" style="gap: 6px;">
                                            <textarea class="sc-raw" readonly aria-hidden="true" tabindex="-1" style="position: absolute; left: -9999px; width: 1px; height: 1px;">{{ $fit['raw'] }}</textarea>
                                            <span style="font-size: 0.78rem; color: var(--hr-text-muted); min-width: 120px;">{{ $fit['label'] }}</span>
                                            <button type="button" class="btn btn-xs btn-hr-secondary sc-copy"><i class="fas fa-copy"></i> {{ trans('hr-manager::corp-health.sc_btn_copy') }}</button>
                                            @if(!empty($sc['buyback_available']))
                                                <form method="POST" action="{{ route('buyback.appraisal.create') }}" target="_blank" rel="noopener" class="d-inline m-0">
                                                    @csrf
                                                    <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                                                    <textarea name="items" style="display: none;">{{ $fit['raw'] }}</textarea>
                                                    <button type="submit" class="btn btn-xs btn-hr-primary"><i class="fas fa-balance-scale"></i> {{ trans('hr-manager::corp-health.sc_btn_appraise') }}</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </details>
                @endforeach
            @endif
        @endif

        <script>
        (function () {
            document.querySelectorAll('.sc-copy').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var holder = btn.closest('.sc-copy-wrap');
                    var ta = holder ? holder.querySelector('.sc-raw') : null;
                    if (!ta) return;
                    var done = function () {
                        var orig = btn.getAttribute('data-orig') || btn.innerHTML;
                        btn.setAttribute('data-orig', orig);
                        btn.innerHTML = '<i class="fas fa-check"></i> {{ trans('hr-manager::corp-health.sc_copied') }}';
                        setTimeout(function () { btn.innerHTML = orig; }, 1500);
                    };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(ta.value).then(done, function () { legacy(ta, done); });
                    } else {
                        legacy(ta, done);
                    }
                });
            });
            function legacy(ta, cb) {
                var prev = ta.style.cssText;
                ta.style.cssText = 'position:fixed;left:0;top:0;opacity:0;';
                ta.focus(); ta.select();
                try { document.execCommand('copy'); cb(); } catch (e) {}
                ta.style.cssText = prev;
            }
        })();
        </script>
        @endif
    @endif

        </div>{{-- /.card-body --}}
    </div>{{-- /.card.card-tabs --}}

</div>
@endsection

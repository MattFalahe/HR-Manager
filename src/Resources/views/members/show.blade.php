@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::members.member_profile'))
@section('page_header', trans('hr-manager::members.member_profile'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.7">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    <div class="row">
        {{-- Main Content --}}
        <div class="col-md-8">

            {{-- Unregistered warning banner — directs operators to either
                 chase the character to register OR consider kicking from
                 corp because we have no insight into them. Renders ONLY
                 when the character isn't registered with SeAT. --}}
            @if(!$isRegistered)
                <div class="unregistered-warning-banner mb-3">
                    <div style="display: flex; align-items: flex-start; gap: 14px;">
                        <div style="font-size: 2rem;"><i class="fas fa-user-slash"></i></div>
                        <div style="flex: 1;">
                            <strong style="font-size: 1.1rem;">
                                {{ trans('hr-manager::members.unregistered_warning_heading') }}
                            </strong>
                            <p class="mb-1 mt-1">{{ trans('hr-manager::members.unregistered_warning_body') }}</p>
                            <small style="opacity: 0.85;">{{ trans('hr-manager::members.unregistered_warning_action') }}</small>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Character Header --}}
            <div class="card card-dark mb-3">
                <div class="card-body">
                    <div class="character-header">
                        <img src="https://images.evetech.net/characters/{{ $affiliation->character_id }}/portrait?size=128"
                             class="character-avatar" alt="Portrait">
                        <div style="flex: 1;">
                            <div class="character-name">
                                {{ $displayName }}
                                @if(!$isRegistered)
                                    <span class="badge badge-hr badge-rejected ml-2" style="font-size: 0.7rem; vertical-align: middle;">
                                        <i class="fas fa-user-slash"></i> {{ trans('hr-manager::members.unregistered') }}
                                    </span>
                                @endif
                                {{-- Round-3 contribution percentile badge — a quick
                                     "is this a contributor?" tell next to the name. --}}
                                @php
                                    $pctData = $walletActivity['percentile']['data'] ?? null;
                                    $pctValue = $pctData->percentile ?? ($pctData['percentile'] ?? null);
                                @endphp
                                @if($pctValue !== null)
                                    @php
                                        $pctNum = (int) $pctValue;
                                        if ($pctNum >= 90)      { $pctClass = 'badge-accepted';  $pctLabel = 'Top 10%'; }
                                        elseif ($pctNum >= 75)  { $pctClass = 'badge-accepted';  $pctLabel = 'Top 25%'; }
                                        elseif ($pctNum >= 50)  { $pctClass = 'badge-applied';   $pctLabel = 'Above median'; }
                                        elseif ($pctNum >= 25)  { $pctClass = 'badge-under-review'; $pctLabel = 'Bottom 50%'; }
                                        else                    { $pctClass = 'badge-rejected';  $pctLabel = 'Bottom 25%'; }
                                    @endphp
                                    <span class="badge badge-hr {{ $pctClass }} ml-2" style="font-size: 0.75rem; vertical-align: middle;"
                                          title="Contribution rank vs corp (last 3 months)">
                                        <i class="fas fa-medal"></i> {{ $pctLabel }}
                                    </span>
                                @endif
                            </div>
                            <div class="character-corp">
                                {{ $corpName ?? '-' }}
                                @if($allianceId)
                                    | {{ $allianceName ?? ('Alliance #' . $allianceId) }}
                                @endif
                            </div>

                            {{-- Character role badges — what this character is
                                 USED FOR, inferred from observed activity
                                 (ratting / mining / PI / industry). Primary
                                 role gets a solid badge; secondary roles are
                                 outlined. These are observed activities, not
                                 guesses — having a PI colony means the
                                 character does PI. --}}
                            @if(!empty($roleProfile['has_data']))
                                <div class="mt-2" style="display: flex; flex-wrap: wrap; gap: 6px;">
                                    @foreach($roleProfile['roles'] as $role)
                                        @php
                                            $isPrimary = ($role['intensity'] ?? '') === 'primary';
                                        @endphp
                                        <span class="badge"
                                              title="{{ $role['detail'] }} (last 6 months)"
                                              style="font-size: 0.78rem; padding: 4px 9px;
                                                     {{ $isPrimary
                                                        ? 'background: linear-gradient(135deg, #667eea, #764ba2); color: #fff;'
                                                        : 'background: rgba(255,255,255,0.05); color: var(--hr-text-light); border: 1px solid rgba(255,255,255,0.15);' }}">
                                            <i class="fas {{ $role['icon'] }}"></i>
                                            {{ $role['label'] }}
                                            <small style="opacity: 0.75; margin-left: 3px;">{{ $role['detail'] }}</small>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                            {{-- Player Identity link — surfaces the persistent
                                 "human" this character maps to. Survives corp
                                 leaves, SeAT-account changes, even character
                                 transfers between players. Directors can
                                 reassign on the identity page. --}}
                            @if(!empty($playerIdentity))
                                <div class="mt-2">
                                    @can('hr-manager.director')
                                        {{-- The player profile keys on SeAT user_id, not identity.id.
                                             Only link when this identity is linked to a SeAT user. --}}
                                        @if(($playerIdentity->seat_user_id ?? 0) > 0)
                                        <a href="{{ route('hr-manager.players.show', ['id' => $playerIdentity->seat_user_id]) }}"
                                           class="btn btn-sm btn-hr-secondary btn-icon"
                                           title="{{ trans('hr-manager::members.player_identity_help') }}">
                                            <i class="fas fa-user-circle"></i>
                                            {{ trans('hr-manager::members.player_identity_link', ['name' => $playerIdentity->primary_name]) }}
                                        </a>
                                        @else
                                        <span class="badge" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.4);">
                                            <i class="fas fa-user-circle"></i>
                                            {{ trans('hr-manager::members.player_identity_label') }}: {{ $playerIdentity->primary_name }}
                                        </span>
                                        @endif
                                    @else
                                        <span class="badge" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.4);">
                                            <i class="fas fa-user-circle"></i>
                                            {{ trans('hr-manager::members.player_identity_label') }}: {{ $playerIdentity->primary_name }}
                                        </span>
                                    @endcan
                                </div>
                            @endif

                            {{-- "Open in Corp Wallet Manager" deep-link was dropped:
                                 CWM doesn't expose a per-character director view,
                                 so the button just opened the generic dashboard
                                 and confused users. If Matt later builds a
                                 per-character impersonation route on the CWM side
                                 (something like /corp-wallet-manager/director/character/{id}),
                                 the entry point should come back here as a button
                                 with a director-only badge. --}}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Assessment Stats --}}
            @if($assessment)
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-bar"></i> Assessment Data
                        </h3>
                        @if($assessment->cached_at)
                            <div class="card-tools">
                                <small style="color: var(--hr-text-muted);">
                                    {{ trans('hr-manager::members.data_cached_at') }}: @hrDate($assessment->cached_at)
                                </small>
                            </div>
                        @endif
                    </div>
                    <div class="card-body">
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-value">{{ number_format($assessment->total_mining_value, 0) }}</div>
                                <div class="stat-label">{{ trans('hr-manager::members.total_mining_value') }} (ISK)</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">{{ number_format($assessment->total_mining_tax, 0) }}</div>
                                <div class="stat-label">{{ trans('hr-manager::members.total_mining_tax') }} (ISK)</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">{{ number_format($assessment->tax_compliance_pct, 1) }}%</div>
                                <div class="stat-label">{{ trans('hr-manager::members.compliance_rate') }}</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">{{ number_format($assessment->total_ratting_income, 0) }}</div>
                                <div class="stat-label">{{ trans('hr-manager::members.total_ratting_income') }} (ISK)</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">{{ $assessment->active_months }}</div>
                                <div class="stat-label">{{ trans('hr-manager::members.active_months') }}</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">{{ $assessment->employment_count }}</div>
                                <div class="stat-label">{{ trans('hr-manager::members.employment_count') }}</div>
                            </div>
                            @if($assessment->security_status !== null)
                                <div class="stat-item">
                                    <div class="stat-value" style="color: {{ $assessment->security_status >= 0 ? 'var(--hr-success)' : 'var(--hr-danger)' }}">
                                        {{ number_format($assessment->security_status, 2) }}
                                    </div>
                                    <div class="stat-label">{{ trans('hr-manager::members.security_status') }}</div>
                                </div>
                            @endif
                            @if($assessment->total_sp)
                                <div class="stat-item">
                                    <div class="stat-value">{{ number_format($assessment->total_sp / 1000000, 1) }}M</div>
                                    <div class="stat-label">{{ trans('hr-manager::members.skill_points') }}</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <div class="card card-dark mb-3">
                    <div class="card-body text-center text-muted p-4">
                        <i class="fas fa-database fa-2x mb-2"></i>
                        <p>{{ trans('hr-manager::members.no_data') }}</p>
                        @can('hr-manager.director')
                            <form method="POST" action="{{ route('hr-manager.members.refresh', $affiliation->character_id) }}">
                                @csrf
                                <button type="submit" class="btn btn-hr-primary btn-icon">
                                    <i class="fas fa-sync-alt"></i> {{ trans('hr-manager::members.refresh_data') }}
                                </button>
                            </form>
                        @endcan
                    </div>
                </div>
            @endif

            {{-- Wallet Activity (CWM signals via MC PluginBridge) --}}
            @include('hr-manager::members.partials.wallet-activity', ['walletActivity' => $walletActivity])

            {{-- Wallet Audit (director-only fraud-detection view). The
                 partial gracefully renders nothing when neither CWM nor
                 MM is installed; the @can also stops it from being
                 included for recruiter viewers, even though the
                 controller already skipped the audit build for them. --}}
            @can('hr-manager.director')
                @include('hr-manager::members.partials.wallet-audit', ['walletAudit' => $walletAudit])
            @endcan

            {{-- Notes --}}
            @include('hr-manager::applications.partials.notes-panel', ['notes' => $notes, 'noteableType' => 'member', 'noteableId' => $affiliation->character_id])

        </div>

        {{-- Sidebar --}}
        <div class="col-md-4">
            {{-- Quick Info --}}
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-info-circle"></i> Quick Info</h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-6" style="color: var(--hr-text-muted);">Character ID</dt>
                        <dd class="col-sm-6">{{ $affiliation->character_id }}</dd>

                        @if($ownerUserId)
                            <dt class="col-sm-6" style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.seat_user') }}</dt>
                            <dd class="col-sm-6">#{{ $ownerUserId }}</dd>
                        @endif

                        @if(!empty($discord['available']))
                            @if($discord['discord_username'])
                                <dt class="col-sm-6" style="color: var(--hr-text-muted);"><i class="fab fa-discord"></i> {{ trans('hr-manager::members.discord_user') }}</dt>
                                <dd class="col-sm-6">{{ $discord['discord_username'] }}</dd>
                            @elseif($discord['connector_id'])
                                <dt class="col-sm-6" style="color: var(--hr-text-muted);"><i class="fab fa-discord"></i> {{ trans('hr-manager::members.discord_id') }}</dt>
                                <dd class="col-sm-6"><code style="font-size: 0.75rem;">{{ $discord['connector_id'] }}</code></dd>
                            @endif
                        @endif

                        @if($assessment && $assessment->last_mining_date)
                            <dt class="col-sm-6" style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.last_mining') }}</dt>
                            <dd class="col-sm-6">@hrDateShort($assessment->last_mining_date)</dd>
                        @endif

                        @if($assessment && $assessment->last_ratting_date)
                            <dt class="col-sm-6" style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.last_ratting') }}</dt>
                            <dd class="col-sm-6">@hrDateShort($assessment->last_ratting_date)</dd>
                        @endif

                        @if($assessment && $assessment->member_since)
                            <dt class="col-sm-6" style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.member_since') }}</dt>
                            <dd class="col-sm-6">@hrDateShort($assessment->member_since)</dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Mining detail — favourite ores + systems (via Mining
                 Manager). Recruiter+ visible. Hidden when MM isn't
                 installed or the character has no mining ledger. --}}
            @php
                $mdOres = $miningDetail['ores'] ?? ['available' => false];
                $mdSys  = $miningDetail['systems'] ?? ['available' => false];
                $mdAtt  = $miningDetail['attendance'] ?? ['available' => false];
                $mdHasOres = !empty($mdOres['available']) && !empty($mdOres['ores']);
                $mdHasSys  = !empty($mdSys['available']) && !empty($mdSys['systems']);
                $mdHasAtt  = !empty($mdAtt['available']) && (($mdAtt['total_events'] ?? 0) > 0 || ($mdAtt['attended'] ?? 0) > 0);
            @endphp
            @if($mdHasOres || $mdHasSys || $mdHasAtt)
                @php
                    $qtyFmt = function ($n) {
                        if ($n >= 1e9) return number_format($n / 1e9, 1) . 'B';
                        if ($n >= 1e6) return number_format($n / 1e6, 1) . 'M';
                        if ($n >= 1e3) return number_format($n / 1e3, 1) . 'K';
                        return number_format($n);
                    };
                @endphp
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-gem"></i> {{ trans('hr-manager::members.mining_detail_heading') }}</h3>
                        <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.mining_detail_window') }}</small></div>
                    </div>
                    <div class="card-body">
                        {{-- Corp ore-op attendance headline — the engagement
                             signal. "Showed up to N of the corp's M ops." --}}
                        @if($mdHasAtt)
                            @php
                                $attRate = $mdAtt['rate_pct'];
                                $attColor = $attRate === null ? 'var(--hr-text-muted)'
                                    : ($attRate >= 60 ? 'var(--hr-success, #28a745)'
                                        : ($attRate >= 25 ? 'var(--hr-warning, #ffc107)' : 'var(--hr-danger, #dc3545)'));
                            @endphp
                            <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid rgba(255,255,255,0.06);">
                                <div style="text-align: center; min-width: 90px;">
                                    <div style="font-size: 1.8rem; font-weight: 700; color: {{ $attColor }};">
                                        {{ $attRate !== null ? $attRate . '%' : '-' }}
                                    </div>
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.mining_attendance_rate') }}</small>
                                </div>
                                <div style="flex: 1;">
                                    <div style="color: var(--hr-text-white); font-size: 0.95rem;">
                                        {!! trans('hr-manager::members.mining_attendance_summary', [
                                            'attended' => '<strong>' . $mdAtt['attended'] . '</strong>',
                                            'total'    => $mdAtt['total_events'],
                                        ]) !!}
                                    </div>
                                    @if(!empty($mdAtt['recent']))
                                        <div style="margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px;">
                                            @foreach($mdAtt['recent'] as $ev)
                                                <span class="badge" style="background: rgba(23,162,184,0.15); color: #7fd4e0; border: 1px solid rgba(23,162,184,0.3); font-size: 0.7rem;"
                                                      title="{{ \Illuminate\Support\Carbon::parse($ev['date'])->format('M d, Y') }}">
                                                    <i class="fas fa-calendar-check"></i> {{ \Illuminate\Support\Str::limit($ev['name'], 24) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="row">
                            @if($mdHasOres)
                                <div class="col-md-6">
                                    <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.mining_top_ores') }}</small>
                                    @php $maxOre = max(array_map(fn($o) => $o['quantity'], $mdOres['ores'])) ?: 1; @endphp
                                    @foreach($mdOres['ores'] as $ore)
                                        <div style="display: flex; align-items: center; gap: 8px; padding: 3px 0;">
                                            <img src="https://images.evetech.net/types/{{ $ore['type_id'] }}/icon?size=32" style="width: 22px; height: 22px;" onerror="this.style.display='none'">
                                            <span style="flex: 1; color: var(--hr-text-white); font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $ore['name'] }}</span>
                                            <div style="width: 70px; background: rgba(255,255,255,0.05); height: 8px; border-radius: 3px; overflow: hidden;">
                                                <div style="width: {{ ($ore['quantity'] / $maxOre) * 100 }}%; background: #17a2b8; height: 100%;"></div>
                                            </div>
                                            <small style="color: var(--hr-text-muted); min-width: 48px; text-align: right;">{{ $qtyFmt($ore['quantity']) }}</small>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if($mdHasSys)
                                <div class="col-md-6">
                                    <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.mining_top_systems') }}</small>
                                    @php $maxSys = max(array_map(fn($s) => $s['quantity'], $mdSys['systems'])) ?: 1; @endphp
                                    @foreach($mdSys['systems'] as $sys)
                                        <div style="display: flex; align-items: center; gap: 8px; padding: 3px 0;">
                                            <i class="fas fa-map-marker-alt" style="color: #9b7ed5; font-size: 0.8rem;"></i>
                                            <span style="flex: 1; color: var(--hr-text-white); font-size: 0.85rem;">{{ $sys['name'] }}</span>
                                            <div style="width: 70px; background: rgba(255,255,255,0.05); height: 8px; border-radius: 3px; overflow: hidden;">
                                                <div style="width: {{ ($sys['quantity'] / $maxSys) * 100 }}%; background: #9b7ed5; height: 100%;"></div>
                                            </div>
                                            <small style="color: var(--hr-text-muted); min-width: 48px; text-align: right;">{{ $sys['entries'] }} ops</small>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;">
                            <i class="fas fa-info-circle"></i> {{ trans('hr-manager::members.mining_detail_footnote') }}
                        </small>
                    </div>
                </div>
            @endif

            {{-- In-game titles + roles (from SeAT's synced
                 corporation_member_titles / corporation_roles).
                 Director-token presence required for these to populate;
                 panel hidden when both lists are empty. --}}
            @if(!empty($titleSnapshot['has_anything']))
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-id-badge"></i> {{ trans('hr-manager::members.titles_heading') }}</h3>
                    </div>
                    <div class="card-body">
                        @if(!empty($titleSnapshot['titles']))
                            <div class="mb-2">
                                <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.titles_subheading') }}</small>
                            </div>
                            @foreach($titleSnapshot['titles'] as $title)
                                <span class="badge mr-1 mb-1" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.5); font-weight: 500;">
                                    <i class="fas fa-id-badge"></i> {{ $title['name'] }}
                                </span>
                            @endforeach
                        @endif

                        @if(!empty($titleSnapshot['roles']))
                            <div class="mt-3 mb-2">
                                <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.roles_subheading') }}</small>
                            </div>
                            @foreach($titleSnapshot['roles'] as $role)
                                @php
                                    // Director / Personnel_Manager / Accountant get danger
                                    // accent because they're the high-impact corp roles
                                    // most likely to need stripping in a purge.
                                    $highImpact = in_array($role, [
                                        'Director', 'Personnel_Manager', 'Accountant',
                                        'Junior_Accountant', 'Diplomat', 'Security_Officer',
                                    ], true);
                                    $roleBgColor = $highImpact ? 'rgba(220,53,69,0.18)' : 'rgba(255,255,255,0.05)';
                                    $roleBorderColor = $highImpact ? 'rgba(220,53,69,0.5)' : 'var(--hr-border)';
                                @endphp
                                <span class="badge mr-1 mb-1" style="background: {{ $roleBgColor }}; color: var(--hr-text-light); border: 1px solid {{ $roleBorderColor }}; font-weight: 500;"
                                      title="{{ $highImpact ? trans('hr-manager::members.role_high_impact') : '' }}">
                                    @if($highImpact)<i class="fas fa-exclamation-triangle"></i>@endif
                                    {{ str_replace('_', ' ', $role) }}
                                </span>
                            @endforeach
                        @endif
                    </div>
                </div>
            @endif

            {{-- Discord roles (via seat-connector) --}}
            @if(!empty($discord['available']) && !empty($discord['roles']))
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fab fa-discord"></i> {{ trans('hr-manager::members.discord_roles_heading') }}</h3>
                        <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ count($discord['roles']) }}</small></div>
                    </div>
                    <div class="card-body">
                        @foreach($discord['roles'] as $role)
                            <span class="badge mr-1 mb-1" style="background: rgba(88,101,242,0.18); color: var(--hr-text-light); border: 1px solid rgba(88,101,242,0.4);">
                                {{ $role['name'] }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Recent PvP (zKillboard) --}}
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-crosshairs"></i> {{ trans('hr-manager::members.pvp_heading') }}</h3>
                    <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.pvp_source') }}</small></div>
                </div>
                <div class="card-body">
                    @if(empty($pvp['available']))
                        <p class="text-muted mb-0"><small>{{ trans('hr-manager::members.pvp_unavailable') }}</small></p>
                    @elseif(empty($pvp['recent_active']) && ($pvp['ships_destroyed'] ?? 0) === 0 && ($pvp['ships_lost'] ?? 0) === 0)
                        <p class="text-muted mb-0"><small>{{ trans('hr-manager::members.pvp_no_history') }}</small></p>
                    @else
                        @php
                            $iskFormat = function ($v) {
                                if ($v === null || $v == 0) return '0';
                                $abs = abs((float) $v);
                                if ($abs >= 1.0e12) return number_format($v / 1.0e12, 2) . 'T';
                                if ($abs >= 1.0e9)  return number_format($v / 1.0e9, 2)  . 'B';
                                if ($abs >= 1.0e6)  return number_format($v / 1.0e6, 2)  . 'M';
                                if ($abs >= 1.0e3)  return number_format($v / 1.0e3, 2)  . 'k';
                                return number_format($v, 0);
                            };
                        @endphp
                        <div class="row text-center">
                            <div class="col">
                                <div style="font-size: 1.4rem; color: var(--hr-success);"><strong>{{ number_format($pvp['ships_destroyed']) }}</strong></div>
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.pvp_kills') }}</small>
                            </div>
                            <div class="col">
                                <div style="font-size: 1.4rem; color: var(--hr-danger);"><strong>{{ number_format($pvp['ships_lost']) }}</strong></div>
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.pvp_losses') }}</small>
                            </div>
                        </div>
                        <hr style="border-color: var(--hr-border); margin: 8px 0;">
                        <small class="d-block"><span class="text-success">{{ $iskFormat($pvp['isk_destroyed']) }}</span> {{ trans('hr-manager::members.pvp_isk_destroyed') }}</small>
                        <small class="d-block"><span class="text-danger">{{ $iskFormat($pvp['isk_lost']) }}</span> {{ trans('hr-manager::members.pvp_isk_lost') }}</small>
                        @if($pvp['danger_ratio'] !== null)
                            <small class="d-block"><span style="color: var(--hr-text-white);">{{ $pvp['danger_ratio'] }}%</span> {{ trans('hr-manager::members.pvp_danger') }}</small>
                        @endif
                        <div class="mt-2">
                            <a href="https://zkillboard.com/character/{{ $affiliation->character_id }}/" target="_blank" rel="noopener" style="color: var(--hr-text-muted);">
                                <small>{{ trans('hr-manager::members.pvp_open_zkill') }} <i class="fas fa-external-link-alt"></i></small>
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Actions --}}
            @can('hr-manager.director')
                <div class="card card-dark mb-3">
                    <div class="card-body">
                        <form method="POST" action="{{ route('hr-manager.members.refresh', $affiliation->character_id) }}">
                            @csrf
                            <button type="submit" class="btn btn-hr-primary btn-block btn-icon">
                                <i class="fas fa-sync-alt"></i> {{ trans('hr-manager::members.refresh_data') }}
                            </button>
                        </form>
                    </div>
                </div>
            @endcan

            <a href="{{ route('hr-manager.members.index') }}" class="btn btn-hr-secondary btn-block btn-icon">
                <i class="fas fa-arrow-left"></i> Back to Members
            </a>
        </div>
    </div>

</div>

{{-- Modals rendered OUTSIDE the .hr-manager-wrapper so they don't inherit
     its dark-on-dark cascade. See notes-panel partial for the @push side. --}}
@stack('hr-modals')
@endsection

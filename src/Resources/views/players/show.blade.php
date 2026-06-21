@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::players.player_profile'))
@section('page_header', trans('hr-manager::players.player_profile'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.3">
@endpush

@section('full')
@php
    $user        = $summary['user'];
    $characters  = $summary['characters'];
    $altSummaries = $summary['alt_summaries'];
    $tier        = $summary['tier'];
    $status      = $summary['status'];
    $totalDays   = $summary['total_days_in_corp'];
    $currentDays = $summary['current_stint_days'];
    $lastActivity = $summary['last_activity_at'];
    $main = $characters->firstWhere('character_id', $user->main_character_id) ?? $characters->first();
    $mainId   = $main->character_id ?? null;
    $mainName = $main->name ?? ('User #' . $user->id);
@endphp

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

    {{-- Purge role-strip warning. Renders only when this player is
         marked_for_purge. Severity tiers:
           - scheduled within 24h  -> CRITICAL blinking banner
           - scheduled in 24-72h   -> warning banner (still amber)
           - scheduled 72h+ away   -> info banner (gentle nag)
           - no schedule           -> info banner

         Mentions the EVE 24h cooldown explicitly so operators don't
         miss it, and (when title data is available) lists the exact
         titles + high-impact roles to strip across all alts. --}}
    @if($status && $status->status === 'marked_for_purge')
        @php
            $hoursToKick = null;
            if ($status->purge_scheduled_for) {
                $hoursToKick = max(0, now()->diffInHours($status->purge_scheduled_for, false));
            }
            $isCritical = $hoursToKick !== null && $hoursToKick <= 24;
            $isWarning  = $hoursToKick !== null && $hoursToKick > 24 && $hoursToKick <= 72;
            $bannerClass = $isCritical ? 'purge-banner-critical' : ($isWarning ? 'purge-banner-warning' : 'purge-banner-info');

            // High-impact roles bubble up to the banner. Titles get
            // their own line when present.
            $titlesAcrossAlts = $titleSnapshot['titles'] ?? [];
            $rolesAcrossAlts  = $titleSnapshot['roles']  ?? [];
            $highImpactRoles  = array_values(array_intersect($rolesAcrossAlts, [
                'Director', 'Personnel_Manager', 'Accountant',
                'Junior_Accountant', 'Diplomat', 'Security_Officer',
            ]));
        @endphp
        <div class="purge-warning-banner {{ $bannerClass }} {{ $isCritical ? 'purge-blink' : '' }} mb-3">
            <div style="display: flex; gap: 16px; align-items: flex-start;">
                <div style="font-size: 2rem; flex-shrink: 0;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div style="flex: 1;">
                    <strong style="font-size: 1.1rem;">
                        @if($isCritical)
                            {{ trans('hr-manager::players.purge_strip_now') }}
                        @elseif($isWarning)
                            {{ trans('hr-manager::players.purge_strip_soon') }}
                        @else
                            {{ trans('hr-manager::players.purge_strip_eventual') }}
                        @endif
                    </strong>
                    <p class="mb-2 mt-1">
                        {{ trans('hr-manager::players.purge_cooldown_explanation') }}
                        @if($status->purge_scheduled_for)
                            <strong>{{ trans('hr-manager::players.purge_scheduled_for') }} {{ $status->purge_scheduled_for->format('M d, Y') }}</strong>
                            @if($hoursToKick !== null)
                                ({{ $hoursToKick }}h)
                            @endif
                        @endif
                    </p>
                    @if(!empty($highImpactRoles))
                        <div class="mb-1">
                            <small style="text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.85;">{{ trans('hr-manager::players.purge_high_impact_roles') }}:</small>
                        </div>
                        @foreach($highImpactRoles as $role)
                            <span class="badge mr-1 mb-1" style="background: rgba(255,255,255,0.18); color: #fff; border: 1px solid rgba(255,255,255,0.3); font-weight: 500;">
                                <i class="fas fa-exclamation-triangle"></i> {{ str_replace('_', ' ', $role) }}
                            </span>
                        @endforeach
                    @endif
                    @if(!empty($titlesAcrossAlts))
                        <div class="mt-2 mb-1">
                            <small style="text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.85;">{{ trans('hr-manager::players.purge_titles_to_strip') }}:</small>
                        </div>
                        @foreach($titlesAcrossAlts as $title)
                            <span class="badge mr-1 mb-1" style="background: rgba(255,255,255,0.12); color: #fff; border: 1px solid rgba(255,255,255,0.25); font-weight: 500;">
                                <i class="fas fa-id-badge"></i> {{ $title['name'] }}
                            </span>
                        @endforeach
                    @endif
                    {{-- Per-character: each alt is kicked + stripped individually
                         in-game, so list what to strip on EACH (only when the
                         account has more than one character holding something). --}}
                    @php $bannerByChar = $accessDepth['ingame']['by_character'] ?? []; @endphp
                    @if(count($bannerByChar) > 1)
                        <div class="mt-2 mb-1">
                            <small style="text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.85;">{{ trans('hr-manager::players.purge_per_character') }}:</small>
                        </div>
                        @foreach($bannerByChar as $ch)
                            <div style="margin-bottom: 3px;">
                                <small style="font-weight: 600;"><i class="fas fa-user-astronaut"></i> {{ $ch['name'] }}:</small>
                                @forelse(array_merge($ch['roles'], array_map(fn($t) => $t['name'], $ch['titles'])) as $item)
                                    <span class="badge mr-1" style="background: rgba(255,255,255,0.12); color: #fff; font-size: 0.68rem;">{{ str_replace('_', ' ', $item) }}</span>
                                @empty
                                @endforelse
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Player Header --}}
    <div class="card card-dark mb-3">
        <div class="card-body">
            <div class="character-header" style="display: flex; align-items: center; gap: 16px;">
                @if($mainId)
                    <img src="https://images.evetech.net/characters/{{ $mainId }}/portrait?size=128"
                         class="character-avatar"
                         style="width: 96px; height: 96px; border-radius: 8px;"
                         alt="Portrait">
                @endif
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <h3 class="mb-0" style="color: var(--hr-text-white);">{{ $mainName }}</h3>
                        @if(!empty($identity))
                            <span class="badge"
                                  style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.4);"
                                  title="{{ trans('hr-manager::players.identity_badge_help') }}">
                                <i class="fas fa-user-circle"></i>
                                {{ trans('hr-manager::players.identity_badge', ['n' => $identity->id]) }}
                                @if(!$identity->seat_user_id)
                                    · <i class="fas fa-ghost"></i> {{ trans('hr-manager::identity.unlinked_ghost') }}
                                @endif
                            </span>
                        @endif
                        @if($tier)
                            <span class="badge badge-hr {{ \HrManager\Support\TierLevel::badgeClass($tier['level']) }}">
                                {{ \HrManager\Support\TierLevel::shortLabel($tier['level']) }}
                                {{ \HrManager\Support\TierLevel::label($tier['level']) }}
                            </span>
                        @else
                            <span class="badge" style="background: rgba(255,255,255,0.05); color: var(--hr-text-muted);">
                                {{ trans('hr-manager::tiers.unmapped') }}
                            </span>
                        @endif
                        @if($status && $status->status === 'loa')
                            <span class="badge badge-warning">
                                <i class="fas fa-umbrella-beach"></i>
                                {{ $status->loa_until ? trans('hr-manager::players.loa_until') . ' ' . $status->loa_until->format('M d, Y') : trans('hr-manager::players.loa_open_ended') }}
                            </span>
                        @elseif($status && $status->status === 'marked_for_purge')
                            <span class="badge badge-danger">
                                <i class="fas fa-user-times"></i>
                                {{ $status->purge_scheduled_for ? trans('hr-manager::players.purge_scheduled_for') . ' ' . $status->purge_scheduled_for->format('M d, Y') : trans('hr-manager::players.purge_unscheduled') }}
                            </span>
                        @else
                            <span class="badge badge-hr badge-accepted">{{ trans('hr-manager::players.status_active') }}</span>
                        @endif
                    </div>
                    <div style="margin-top: 6px; color: var(--hr-text-light);">
                        <small style="color: var(--hr-text-muted);">SeAT user</small> #{{ $user->id }}
                        @if($totalDays !== null)
                            &nbsp;|&nbsp; <strong>{{ $totalDays }}</strong>
                            {{ trans('hr-manager::players.days') }}
                            ({{ trans('hr-manager::players.total_in_corp') }})
                        @endif
                        @if($currentDays !== null)
                            &nbsp;|&nbsp; <strong>{{ $currentDays }}</strong>
                            {{ trans('hr-manager::players.days') }}
                            ({{ trans('hr-manager::players.current_stint') }})
                        @endif
                        @if($lastActivity)
                            &nbsp;|&nbsp; {{ trans('hr-manager::players.last_activity') }}:
                            <strong>{{ $lastActivity->diffForHumans() }}</strong>
                        @else
                            &nbsp;|&nbsp; <em>{{ trans('hr-manager::players.no_activity_signal') }}</em>
                        @endif
                    </div>
                    @if($status && $status->reason)
                        <div style="margin-top: 4px; color: var(--hr-text-light);">
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.status_reason') }}:</small>
                            {{ $status->reason }}
                        </div>
                    @endif

                    {{-- "Open in Corp Wallet Manager" deep-link was dropped:
                         CWM doesn't expose a per-character or per-player
                         director view, so the button just opened the generic
                         dashboard and confused users. If CWM later ships a
                         per-character impersonation route gated by director
                         permission, the entry point should come back here as
                         a button with a director-only badge. --}}
                </div>
            </div>
        </div>
    </div>

    {{-- Corp impact — the economic "what would we lose if we purge this
         player" summary, rolled up across every alt. Surfaces the wallet /
         ratting / mining / engagement signals CWM + HR assessments already
         compute, so a director can weigh real impact before marking for
         purge. Always rendered (shows a muted line when no data). --}}
    @php $impact = $summary['wallet_rollup'] ?? ['available' => false]; @endphp
    @push('head')
    <style>
        .impact-stat { flex: 1 1 130px; min-width: 110px; background: rgba(255,255,255,0.03); border: 1px solid var(--hr-border, #2c3138); border-radius: 6px; padding: 10px 12px; text-align: center; }
        .impact-val { font-size: 1.25rem; font-weight: 700; line-height: 1.2; }
        .impact-lbl { font-size: 0.7rem; color: var(--hr-text-muted, #9ca3af); text-transform: uppercase; letter-spacing: 0.4px; margin-top: 3px; }
    </style>
    @endpush
    <div class="card card-dark mb-3" style="border-left: 4px solid {{ !empty($impact['available']) && !empty($impact['is_net_positive']) ? 'var(--hr-success, #28a745)' : 'var(--hr-info, #17a2b8)' }};">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-hand-holding-usd"></i> {{ trans('hr-manager::players.impact_heading') }}</h3>
            @if(!empty($impact['available']))
                <div class="card-tools">
                    @if(!empty($impact['is_net_positive']))
                        <span class="badge badge-hr badge-accepted"><i class="fas fa-award"></i> {{ trans('hr-manager::players.impact_net_giver') }}</span>
                    @else
                        <span class="badge badge-hr badge-rejected"><i class="fas fa-arrow-down"></i> {{ trans('hr-manager::players.impact_net_taker') }}</span>
                    @endif
                </div>
            @endif
        </div>
        <div class="card-body">
            @if(empty($impact['available']))
                <p class="text-muted mb-0"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.impact_unavailable') }}</p>
            @else
                @php
                    $iskImpact = function ($v) {
                        if ($v === null) return '-';
                        $a = abs((float) $v);
                        if ($a >= 1e12) return number_format($v / 1e12, 2) . ' T';
                        if ($a >= 1e9)  return number_format($v / 1e9, 2)  . ' B';
                        if ($a >= 1e6)  return number_format($v / 1e6, 2)  . ' M';
                        if ($a >= 1e3)  return number_format($v / 1e3, 1)  . ' k';
                        return number_format($v, 0);
                    };
                @endphp
                <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.impact_intro') }}</p>
                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <div class="impact-stat">
                        <div class="impact-val" style="color: var(--hr-text-white);">{{ $iskImpact($impact['lifetime_contributed'] ?? 0) }}</div>
                        <div class="impact-lbl">{{ trans('hr-manager::players.impact_lifetime') }}</div>
                    </div>
                    <div class="impact-stat">
                        <div class="impact-val" style="color: {{ !empty($impact['is_net_positive']) ? 'var(--hr-success, #28a745)' : 'var(--hr-danger, #dc3545)' }};">{{ ($impact['net_position_6mo'] ?? 0) >= 0 ? '+' : '' }}{{ $iskImpact($impact['net_position_6mo'] ?? 0) }}</div>
                        <div class="impact-lbl">{{ trans('hr-manager::players.impact_net_6mo') }}</div>
                    </div>
                    @if(($impact['contribution_percentile'] ?? null) !== null)
                        <div class="impact-stat">
                            <div class="impact-val" style="color: var(--hr-text-white);">{{ number_format((float) $impact['contribution_percentile'], 0) }}%</div>
                            <div class="impact-lbl">{{ trans('hr-manager::players.impact_rank') }}@if(($impact['total_contributors'] ?? null)) <span style="opacity:0.65;">· {{ $impact['total_contributors'] }}</span>@endif</div>
                        </div>
                    @endif
                    @if(($impact['ratting_income'] ?? 0) > 0)
                        <div class="impact-stat">
                            <div class="impact-val" style="color: var(--hr-text-white);">{{ $iskImpact($impact['ratting_income']) }}</div>
                            <div class="impact-lbl">{{ trans('hr-manager::players.impact_ratting') }}</div>
                        </div>
                    @endif
                    @if(($impact['mining_value'] ?? 0) > 0)
                        <div class="impact-stat">
                            <div class="impact-val" style="color: var(--hr-text-white);">{{ $iskImpact($impact['mining_value']) }}</div>
                            <div class="impact-lbl">{{ trans('hr-manager::players.impact_mining') }}</div>
                        </div>
                    @endif
                    @if(($impact['active_months'] ?? 0) > 0)
                        <div class="impact-stat">
                            <div class="impact-val" style="color: var(--hr-text-white);">{{ $impact['active_months'] }}</div>
                            <div class="impact-lbl">{{ trans('hr-manager::players.impact_active_months') }}</div>
                        </div>
                    @endif
                    @if(($impact['worst_compliance_pct'] ?? null) !== null)
                        <div class="impact-stat">
                            <div class="impact-val" style="color: {{ $impact['worst_compliance_pct'] >= 50 ? 'var(--hr-success, #28a745)' : 'var(--hr-warning, #ffc107)' }};">{{ number_format((float) $impact['worst_compliance_pct'], 0) }}%</div>
                            <div class="impact-lbl">{{ trans('hr-manager::players.impact_compliance') }}</div>
                        </div>
                    @endif
                </div>
                @if(!empty($impact['last_contribution_at']))
                    <small class="d-block mt-2" style="color: var(--hr-text-muted);"><i class="fas fa-clock"></i> {{ trans('hr-manager::players.impact_last_contribution') }} {{ \Illuminate\Support\Carbon::parse($impact['last_contribution_at'])->diffForHumans() }}</small>
                @endif
            @endif
        </div>
    </div>

    {{-- FC activity — human-level fleet-command profile from SeAT
         Broadcast's pings.broadcast.sent events (EventBus-accumulated).
         Only shows when the player has led at least a few broadcasts. --}}
    @if(!empty($fcActivity['available']) && !empty($fcActivity['is_fc']))
        @php
            $fcFirst = $fcActivity['first_at'] ? \Illuminate\Support\Carbon::parse($fcActivity['first_at']) : null;
            $fcLast  = $fcActivity['last_at'] ? \Illuminate\Support\Carbon::parse($fcActivity['last_at']) : null;
        @endphp
        <div class="card card-dark mb-3" style="border-left: 4px solid #f59e0b;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-broadcast-tower" style="color: #f59e0b;"></i> {{ trans('hr-manager::players.fc_heading') }}</h3>
                <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.fc_window') }}</small></div>
            </div>
            <div class="card-body">
                @if(($fcActivity['total'] ?? 0) > 0)
                    <div class="row text-center">
                        <div class="col">
                            <div style="font-size: 1.9rem; font-weight: 700; color: #fbbf24;">{{ $fcActivity['total'] }}</div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.fc_total') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.9rem; font-weight: 700; color: var(--hr-text-white);">{{ $fcActivity['per_week'] }}</div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.fc_per_week') }}</small>
                        </div>
                        <div class="col">
                            <div style="font-size: 1.9rem; font-weight: 700; color: var(--hr-text-white);">{{ $fcActivity['window_days'] }}d</div>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.fc_active_window') }}</small>
                        </div>
                    </div>
                    @if($fcFirst && $fcLast)
                        <div class="text-center mt-2" style="color: var(--hr-text-muted); font-size: 0.82rem;">
                            {{ trans('hr-manager::players.fc_span', ['from' => $fcFirst->format('M d, Y'), 'to' => $fcLast->diffForHumans()]) }}
                        </div>
                    @endif
                    @if(!empty($fcActivity['by_type']))
                        <div class="mt-2" style="display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;">
                            @foreach($fcActivity['by_type'] as $bt)
                                <span class="badge" style="background: rgba(245,158,11,0.15); color: #fcd34d; border: 1px solid rgba(245,158,11,0.3); font-size: 0.72rem;">
                                    {{ $bt['type'] }}: {{ $bt['count'] }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                @endif

                {{-- Planning / organizer (pings.formup.scheduled) — proactive
                     leadership: scheduling fleets for tactical events. --}}
                @if(!empty($fcActivity['formups_total']))
                    <div class="mt-3 pt-2" style="border-top: 1px solid rgba(255,255,255,0.06);">
                        <small class="d-block mb-1" style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fas fa-calendar-check" style="color: #6ee7b7;"></i> {{ trans('hr-manager::players.fc_planning_heading') }}
                        </small>
                        <div class="d-flex align-items-center" style="gap: 10px; flex-wrap: wrap;">
                            <span style="font-size: 1.3rem; font-weight: 700; color: #6ee7b7;">{{ $fcActivity['formups_total'] }}</span>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.fc_formups_scheduled') }}</small>
                            @foreach($fcActivity['formups_by_category'] ?? [] as $cat)
                                <span class="badge" style="background: rgba(40,167,69,0.12); color: #6ee7b7; border: 1px solid rgba(40,167,69,0.3); font-size: 0.72rem;">{{ ucfirst($cat['category']) }}: {{ $cat['count'] }}</span>
                            @endforeach
                        </div>
                        @if(!empty($fcActivity['upcoming_formups']))
                            <div class="mt-2">
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.fc_upcoming') }}:</small>
                                @foreach($fcActivity['upcoming_formups'] as $up)
                                    @php $upAt = !empty($up['scheduled_for']) ? \Illuminate\Support\Carbon::parse($up['scheduled_for']) : null; @endphp
                                    <div style="font-size: 0.82rem; color: var(--hr-text-light); padding: 2px 0;">
                                        <i class="fas fa-clock" style="color: #6ee7b7;"></i>
                                        {{ $up['structure'] ?? ($up['system'] ?? trans('hr-manager::players.fc_op')) }}
                                        @if(!empty($up['category']))<span style="color: var(--hr-text-muted);">({{ ucfirst($up['category']) }})</span>@endif
                                        @if($upAt)<span style="color: var(--hr-text-muted);">— {{ $upAt->diffForHumans() }}</span>@endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
                <small class="d-block mt-2 text-center" style="color: var(--hr-text-muted); font-size: 0.75rem;">
                    <i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.fc_footnote') }}
                </small>
            </div>
        </div>
    @endif

    {{-- Blueprint activity — request engagement from Blueprint Manager
         (optional; renders only when Manager Core + Blueprint Manager are
         installed and this player has requests). Part of the "what does this
         person do in the corp" picture: an active, fulfilled requester is an
         engaged industrialist; a high rejection rate is a behaviour flag. --}}
    @if(!empty($blueprintActivity['available']) && !empty($blueprintActivity['has_data']))
        @php
            $bp = $blueprintActivity;
            $bpRej = (float) ($bp['rejection_rate'] ?? 0);
            $bpRejColor = $bpRej > 50 ? 'var(--hr-danger, #dc3545)' : ($bpRej > 25 ? 'var(--hr-warning, #ffc107)' : 'var(--hr-success, #28a745)');
            $bpLast = !empty($bp['last_request']) ? \Illuminate\Support\Carbon::parse($bp['last_request']) : null;
        @endphp
        <div class="card card-dark mb-3" style="border-left: 4px solid #8b5cf6;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-drafting-compass"></i> {{ trans('hr-manager::players.bp_heading') }}</h3>
                <div class="card-tools">
                    <span class="badge" style="background: {{ $bpRejColor }}22; color: {{ $bpRejColor }};">{{ $bpRej }}% {{ trans('hr-manager::players.bp_rejection_rate') }}</span>
                </div>
            </div>
            <div class="card-body">
                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <div class="impact-stat"><div class="impact-val" style="color: var(--hr-text-white);">{{ $bp['total_requests'] }}</div><div class="impact-lbl">{{ trans('hr-manager::players.bp_total') }}</div></div>
                    <div class="impact-stat"><div class="impact-val" style="color: var(--hr-success, #28a745);">{{ $bp['fulfilled'] }}</div><div class="impact-lbl">{{ trans('hr-manager::players.bp_fulfilled') }}</div></div>
                    <div class="impact-stat"><div class="impact-val" style="color: var(--hr-danger, #dc3545);">{{ $bp['rejected'] }}</div><div class="impact-lbl">{{ trans('hr-manager::players.bp_rejected') }}</div></div>
                    <div class="impact-stat"><div class="impact-val" style="color: var(--hr-warning, #ffc107);">{{ $bp['pending'] }}</div><div class="impact-lbl">{{ trans('hr-manager::players.bp_pending') }}</div></div>
                    @if(($bp['approved'] ?? 0) > 0)
                        <div class="impact-stat"><div class="impact-val" style="color: var(--hr-info, #17a2b8);">{{ $bp['approved'] }}</div><div class="impact-lbl">{{ trans('hr-manager::players.bp_approved') }}</div></div>
                    @endif
                </div>
                @if(!empty($bp['favourite_types']))
                    <div class="mt-3">
                        <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.7rem;">{{ trans('hr-manager::players.bp_favourites') }}</small>
                        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                            @foreach($bp['favourite_types'] as $ft)
                                <span class="badge" style="background: rgba(139,92,246,0.18); color: #c4b5fd; border: 1px solid rgba(139,92,246,0.4);">{{ $ft['type_name'] }} <span style="opacity: 0.7;">&times;{{ $ft['count'] }}</span></span>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if($bpLast)
                    <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;">{{ trans('hr-manager::players.bp_last_request') }}: {{ $bpLast->diffForHumans() }}</small>
                @endif
                <small class="d-block mt-2 text-center" style="color: var(--hr-text-muted); font-size: 0.75rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.bp_footnote') }}</small>
            </div>
        </div>
    @endif

    {{-- Access depth — how deep this person reaches, in-game and in SeAT.
         In-game corp roles/titles (critical roles flagged) + SeAT account
         access (superuser, roles, permission depth), with off-balance
         indicators where the two don't line up or deep access sits on a
         risky account. --}}
    @if(!empty($accessDepth['has_anything']))
        @php $ad = $accessDepth; $adI = $ad['ingame']; $adS = $ad['seat']; @endphp
        <div class="card card-dark mb-3" style="border-left: 4px solid #f59e0b;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-key"></i> {{ trans('hr-manager::players.ad_heading') }}</h3>
                <div class="card-tools">
                    @if($adS['is_superuser'])
                        <span class="badge" style="background: rgba(220,53,69,0.9); color: #fff;"><i class="fas fa-crown"></i> {{ trans('hr-manager::players.ad_superuser') }}</span>
                    @endif
                    @if($adI['is_director'])
                        <span class="badge" style="background: rgba(220,53,69,0.2); color: #fca5a5; border: 1px solid rgba(220,53,69,0.4);"><i class="fas fa-star"></i> {{ trans('hr-manager::players.ad_director') }}</span>
                    @endif
                </div>
            </div>
            <div class="card-body">
                @if(!empty($ad['flags']))
                    <div class="mb-3">
                        @foreach($ad['flags'] as $flag)
                            @php $fc = $flag['severity'] === 'high' ? '#dc3545' : ($flag['severity'] === 'medium' ? '#ffc107' : '#17a2b8'); @endphp
                            <div style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; margin-bottom: 4px; background: {{ $fc }}1a; border-left: 3px solid {{ $fc }}; border-radius: 4px;">
                                <i class="fas fa-exclamation-triangle" style="color: {{ $fc }};"></i>
                                <span style="color: var(--hr-text-light); font-size: 0.85rem;">{{ trans('hr-manager::players.ad_flag_' . $flag['key']) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="row">
                    <div class="col-md-6">
                        <h6 style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.72rem;"><i class="fas fa-gamepad"></i> {{ trans('hr-manager::players.ad_ingame') }}</h6>
                        @if(!empty($adI['critical_roles']))
                            <div style="margin-bottom: 6px;">
                                @foreach($adI['critical_roles'] as $role)
                                    <span class="badge" style="background: rgba(220,53,69,0.2); color: #fca5a5; border: 1px solid rgba(220,53,69,0.5);"><i class="fas fa-exclamation-circle"></i> {{ str_replace('_', ' ', $role) }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if(!empty($adI['normal_roles']))
                            <div style="margin-bottom: 6px;">
                                @foreach($adI['normal_roles'] as $role)
                                    <span class="badge" style="background: rgba(255,255,255,0.06); color: var(--hr-text-light);">{{ str_replace('_', ' ', $role) }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if(!empty($adI['titles']))
                            <div>
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.ad_titles') }}:</small>
                                @foreach($adI['titles'] as $title)
                                    <span class="badge" style="background: rgba(102,126,234,0.18); color: #c7d2fe;">{{ $title['name'] }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if(empty($adI['roles']) && empty($adI['titles']))
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.ad_no_ingame') }}</small>
                        @endif

                        {{-- Per-character breakdown. In-game roles/titles are held
                             PER CHARACTER (you kick + strip each one individually),
                             so the account aggregate above isn't the whole story.
                             Shown when the player has more than one character that
                             holds something in-game. --}}
                        @if(!empty($adI['by_character']) && count($adI['by_character']) > 1)
                            <div class="mt-2" style="border-top: 1px solid rgba(255,255,255,0.06); padding-top: 6px;">
                                <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.7rem;"><i class="fas fa-sitemap"></i> {{ trans('hr-manager::players.ad_per_character') }}</small>
                                @foreach($adI['by_character'] as $ch)
                                    <div style="margin-top: 4px;">
                                        <small style="color: var(--hr-text-light); font-weight: 600;">{{ $ch['name'] }}</small>
                                        @if($ch['is_director'])<span class="badge" style="background: rgba(220,53,69,0.2); color: #fca5a5; font-size: 0.62rem;">Director</span>@endif
                                        <div>
                                            @foreach($ch['critical_roles'] as $role)
                                                <span class="badge" style="background: rgba(220,53,69,0.18); color: #fca5a5; font-size: 0.66rem;">{{ str_replace('_', ' ', $role) }}</span>
                                            @endforeach
                                            @foreach(array_diff($ch['roles'], $ch['critical_roles']) as $role)
                                                <span class="badge" style="background: rgba(255,255,255,0.05); color: var(--hr-text-muted); font-size: 0.66rem;">{{ str_replace('_', ' ', $role) }}</span>
                                            @endforeach
                                            @foreach($ch['titles'] as $title)
                                                <span class="badge" style="background: rgba(102,126,234,0.15); color: #c7d2fe; font-size: 0.66rem;">{{ $title['name'] }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="col-md-6">
                        <h6 style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.4px; font-size: 0.72rem;"><i class="fas fa-user-shield"></i> {{ trans('hr-manager::players.ad_seat') }}</h6>
                        @if(empty($adS['has_account']))
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.ad_no_seat') }}</small>
                        @else
                            @if($adS['is_superuser'])
                                <p style="color: #fca5a5; font-size: 0.85rem; margin-bottom: 6px;"><i class="fas fa-crown"></i> {{ trans('hr-manager::players.ad_superuser_full') }}</p>
                            @else
                                <div style="margin-bottom: 6px;">
                                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--hr-text-light);">
                                        <span>{{ trans('hr-manager::players.ad_permissions') }}</span>
                                        <span><strong>{{ $adS['permission_count'] }}</strong> / {{ $adS['install_total'] }} ({{ $adS['depth_pct'] }}%)</span>
                                    </div>
                                    @php $dp = (int) $adS['depth_pct']; $dpColor = $dp >= 66 ? '#dc3545' : ($dp >= 33 ? '#ffc107' : '#28a745'); @endphp
                                    <div style="height: 6px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden; margin-top: 3px;">
                                        <div style="width: {{ max(2, $dp) }}%; height: 100%; background: {{ $dpColor }};"></div>
                                    </div>
                                </div>
                            @endif
                            @if(!empty($adS['roles']))
                                <div style="margin-bottom: 6px;">
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.ad_seat_roles') }}:</small>
                                    @foreach($adS['roles'] as $role)
                                        <span class="badge" style="background: rgba(245,158,11,0.18); color: #fcd34d;">{{ $role }}</span>
                                    @endforeach
                                </div>
                            @endif
                            @if(!empty($adS['by_scope']))
                                <div style="font-size: 0.78rem; color: var(--hr-text-muted);">
                                    @foreach($adS['by_scope'] as $scope => $cnt)
                                        <span style="margin-right: 8px;">{{ $scope }}: <strong style="color: var(--hr-text-light);">{{ $cnt }}</strong></span>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
                @if(($ad['wallet']['attributed_isk'] ?? 0) > 0)
                    <div class="mt-2" style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: rgba(245,158,11,0.1); border-left: 3px solid #f59e0b; border-radius: 4px;">
                        <i class="fas fa-coins" style="color: #f59e0b;"></i>
                        <span style="color: var(--hr-text-light); font-size: 0.82rem;">
                            {{ trans('hr-manager::players.ad_wallet_exercise') }}:
                            <strong>{{ number_format($ad['wallet']['attributed_isk']) }} ISK</strong>
                            <span style="color: var(--hr-text-muted);">({{ $ad['wallet']['action_count'] }} {{ trans('hr-manager::players.ad_wallet_actions') }})</span>
                        </span>
                    </div>
                @endif
                <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.75rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.ad_footnote') }}</small>
            </div>
        </div>
    @endif

    {{-- Discord roles (via seat-connector): account-level identity, shown on the player view --}}
    @if(!empty($discord['available']) && !empty($discord['roles']))
        <div class="card card-dark mb-3" style="border-left: 4px solid #5865f2;">
            <div class="card-header">
                <h3 class="card-title"><i class="fab fa-discord"></i> {{ trans('hr-manager::members.discord_roles_heading') }}</h3>
                <div class="card-tools">
                    @if(!empty($discord['discord_username']))<small style="color: var(--hr-text-muted);">{{ $discord['discord_username'] }} &middot; </small>@endif
                    <small style="color: var(--hr-text-muted);">{{ count($discord['roles']) }}</small>
                </div>
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

    <div class="row">
        {{-- LEFT: Alt status grid + Notes --}}
        <div class="col-md-8">

            {{-- All Characters status grid --}}
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i> {{ trans('hr-manager::players.all_characters') }}
                        <small style="color: var(--hr-text-muted);">({{ count($altSummaries) }})</small>
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach($altSummaries as $alt)
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div style="background: var(--hr-dark-card); border: 1px solid {{ $alt['in_corp_now'] ? 'rgba(40,167,69,0.4)' : 'var(--hr-border)' }}; border-radius: 6px; padding: 10px;">
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                                        <img src="https://images.evetech.net/characters/{{ $alt['character_id'] }}/portrait?size=32"
                                             style="width: 32px; height: 32px; border-radius: 50%;" alt="">
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="color: var(--hr-text-white); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                {{-- Name links to the per-character member detail
                                                     when the viewer holds the member page's
                                                     permission. Defensive gate: if the two pages'
                                                     permissions ever diverge, the link only shows
                                                     to someone who can actually open it. --}}
                                                @can('hr-manager.director')
                                                    <a href="{{ route('hr-manager.members.show', ['characterId' => $alt['character_id'], 'corporation_id' => $corporationId]) }}"
                                                       style="color: var(--hr-text-white); text-decoration: none;"
                                                       title="{{ trans('hr-manager::players.view_member_help') }}">
                                                        {{ $alt['name'] }}
                                                    </a>
                                                @else
                                                    {{ $alt['name'] }}
                                                @endcan
                                            </div>
                                            <small style="color: var(--hr-text-muted);">#{{ $alt['character_id'] }}</small>
                                        </div>
                                        @if($alt['in_corp_now'])
                                            <span class="badge badge-hr badge-accepted" title="{{ trans('hr-manager::players.character_in_corp') }}">
                                                <i class="fas fa-check"></i>
                                            </span>
                                        @else
                                            <span class="badge badge-hr badge-withdrawn" title="{{ trans('hr-manager::players.character_out_corp') }}">
                                                <i class="fas fa-times"></i>
                                            </span>
                                        @endif
                                    </div>
                                    <dl class="row mb-0" style="font-size: 0.82rem;">
                                        @if($alt['current_stint_days'] !== null)
                                            <dt class="col-7" style="color: var(--hr-text-muted); font-weight: normal;">{{ trans('hr-manager::players.current_stint') }}</dt>
                                            <dd class="col-5 mb-0 text-right" style="color: var(--hr-text-white);">{{ $alt['current_stint_days'] }} {{ trans('hr-manager::players.days') }}</dd>
                                        @endif

                                        @if($alt['total_days_in_corp'] > 0)
                                            <dt class="col-7" style="color: var(--hr-text-muted); font-weight: normal;">{{ trans('hr-manager::players.total_in_corp') }}</dt>
                                            <dd class="col-5 mb-0 text-right" style="color: var(--hr-text-white);">{{ $alt['total_days_in_corp'] }} {{ trans('hr-manager::players.days') }}</dd>
                                            @if($alt['stint_count'] > 1)
                                                <dt class="col-7" style="color: var(--hr-text-muted); font-weight: normal;">{{ trans('hr-manager::players.previous_stints') }}</dt>
                                                <dd class="col-5 mb-0 text-right" style="color: var(--hr-text-white);">{{ $alt['stint_count'] - ($alt['in_corp_now'] ? 1 : 0) }}</dd>
                                            @endif
                                        @else
                                            <dt class="col-12 mb-0" style="color: var(--hr-text-muted); font-weight: normal; font-style: italic;">{{ trans('hr-manager::players.never_in_corp') }}</dt>
                                        @endif

                                        @if($alt['last_activity_at'])
                                            <dt class="col-7" style="color: var(--hr-text-muted); font-weight: normal;">{{ trans('hr-manager::players.last_activity') }}</dt>
                                            <dd class="col-5 mb-0 text-right" style="color: var(--hr-text-white);">{{ $alt['last_activity_at']->diffForHumans(null, true) }}</dd>
                                        @endif
                                    </dl>

                                    {{-- Per-alt role badges — what this character is
                                         used for. Lets a director see the account's
                                         division of labour (ratting main + mining
                                         alt + PI farm) at a glance. --}}
                                    @php $altRoles = $roleProfiles[$alt['character_id']] ?? null; @endphp
                                    @if(!empty($altRoles['has_data']))
                                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.06);">
                                            @foreach($altRoles['roles'] as $role)
                                                @php $isPrimary = ($role['intensity'] ?? '') === 'primary'; @endphp
                                                <span class="badge" title="{{ $role['detail'] }} (6mo)"
                                                      style="font-size: 0.68rem; padding: 2px 6px;
                                                             {{ $isPrimary
                                                                ? 'background: linear-gradient(135deg, #667eea, #764ba2); color: #fff;'
                                                                : 'background: rgba(255,255,255,0.05); color: var(--hr-text-light); border: 1px solid rgba(255,255,255,0.12);' }}">
                                                    <i class="fas {{ $role['icon'] }}"></i> {{ $role['label'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- View member detail — explicit button, gated on
                                         the member page's permission so it never appears
                                         to someone who'd 403 on click. --}}
                                    @can('hr-manager.director')
                                        <a href="{{ route('hr-manager.members.show', ['characterId' => $alt['character_id'], 'corporation_id' => $corporationId]) }}"
                                           class="btn btn-sm btn-hr-secondary btn-block mt-2"
                                           style="font-size: 0.78rem;">
                                            <i class="fas fa-id-card"></i> {{ trans('hr-manager::players.view_member') }}
                                        </a>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- History timeline (Phase 5 — player + character + corp events
                 from HistoryEventService, including CWM wallet signals
                 routed via WalletEventHandler). Each event_type gets a
                 dedicated icon + color so wallet flags / classifier
                 transitions / purge milestones are visually distinct. --}}
            @php
                // event_type -> [icon, color css var]
                $eventIcons = [
                    // CWM wallet events
                    'wallet_stalled'                     => ['fa-pause-circle',     'var(--hr-warning, #ffc107)'],
                    'wallet_milestone'                   => ['fa-trophy',           'var(--hr-success, #28a745)'],
                    'wallet_compliance_dropped'          => ['fa-exclamation-triangle', 'var(--hr-danger, #dc3545)'],
                    'wallet_contribution_drop'           => ['fa-chart-line',       'var(--hr-warning, #ffc107)'],
                    'wallet_unusual_recipient'           => ['fa-user-secret',      'var(--hr-warning, #ffc107)'],
                    // Security policy events
                    'player.token_revoked'               => ['fa-key',              'var(--hr-danger, #dc3545)'],
                    'hr.player.flagged_wallet_stalled'   => ['fa-pause-circle',     'var(--hr-warning, #ffc107)'],
                    'hr.player.flagged_wallet_compliance_low' => ['fa-exclamation-triangle', 'var(--hr-warning, #ffc107)'],
                    'hr.player.flagged_negative_contribution' => ['fa-arrow-down',  'var(--hr-warning, #ffc107)'],
                    'hr.player.silent_wallet_director'   => ['fa-skull-crossbones', 'var(--hr-danger, #dc3545)'],
                    'hr.player.milestone_reached'        => ['fa-trophy',           'var(--hr-success, #28a745)'],
                    // Classifier transitions
                    'hr.player.classification_changed'   => ['fa-exchange-alt',     'var(--hr-text-muted, #9ca3af)'],
                    'hr.player.inactive_director'        => ['fa-user-times',       'var(--hr-danger, #dc3545)'],
                    // Purge workflow
                    'hr.purge.scheduled'                 => ['fa-calendar-times',   'var(--hr-danger, #dc3545)'],
                    'hr.purge.cancelled'                 => ['fa-undo',             'var(--hr-success, #28a745)'],
                    'hr.purge.executed'                  => ['fa-user-slash',       'var(--hr-danger, #dc3545)'],
                    'hr.purge.reminder_t7'               => ['fa-bell',             'var(--hr-text-muted, #9ca3af)'],
                    'hr.purge.reminder_t3'               => ['fa-bell',             'var(--hr-warning, #ffc107)'],
                    'hr.purge.reminder_t48'              => ['fa-bell',             'var(--hr-danger, #dc3545)'],
                    'hr.purge.reminder_t0'               => ['fa-bell',             'var(--hr-danger, #dc3545)'],
                    // LOA / status
                    'hr.player.loa_marked'               => ['fa-umbrella-beach',   'var(--hr-warning, #ffc107)'],
                    'hr.player.status_cleared'           => ['fa-check-circle',     'var(--hr-success, #28a745)'],
                    // Squad removals
                    'hr.squad.removed'                   => ['fa-user-minus',       'var(--hr-text-muted, #9ca3af)'],
                ];

                // event_type -> human-readable label. Unknown keys fall back to
                // a humanized form (strip the plugin prefix, dots/underscores ->
                // spaces, sentence case) so mining.* / blueprint.request.* and
                // any future event still read cleanly instead of as a raw key.
                $eventLabels = [
                    'player.token_revoked'                    => 'ESI token revoked',
                    'hr.player.flagged_wallet_stalled'        => 'Wallet contribution stalled',
                    'hr.player.flagged_wallet_compliance_low' => 'Tax compliance dropped',
                    'hr.player.flagged_negative_contribution' => 'Net contribution went negative',
                    'hr.player.silent_wallet_director'        => 'Silent wallet director',
                    'hr.player.milestone_reached'             => 'Contribution milestone reached',
                    'hr.player.classification_changed'        => 'Classification changed',
                    'hr.player.inactive_director'             => 'Inactive director flagged',
                    'hr.purge.scheduled'                      => 'Marked for purge',
                    'hr.purge.cancelled'                      => 'Purge cancelled',
                    'hr.purge.executed'                       => 'Purge executed',
                    'hr.purge.reminder_t7'                    => 'Purge reminder — 7 days out',
                    'hr.purge.reminder_t3'                    => 'Purge reminder — 3 days out',
                    'hr.purge.reminder_t48'                   => 'Purge reminder — 48 hours out',
                    'hr.purge.reminder_t0'                    => 'Purge reminder — final',
                    'hr.player.loa_marked'                    => 'Marked on leave (LOA)',
                    'hr.player.status_cleared'                => 'Status cleared',
                    'hr.squad.removed'                        => 'Removed from a squad',
                ];

                $humanizeEvent = function (string $type) {
                    $t = preg_replace('/^(hr|member|player)\./', '', $type);
                    $t = str_replace(['.', '_'], ' ', $t);
                    return ucfirst(trim($t));
                };
            @endphp
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i> {{ trans('hr-manager::players.history_heading') }}
                        <small style="color: var(--hr-text-muted);">({{ $history->count() }})</small>
                    </h3>
                </div>
                <div class="card-body">
                    @forelse($history as $event)
                        @php
                            [$icon, $color] = $eventIcons[$event->event_type] ?? ['fa-circle', 'var(--hr-text-muted, #9ca3af)'];
                            $payloadReason = $event->payload['reason'] ?? null;
                            $eventLabel = $eventLabels[$event->event_type] ?? $humanizeEvent($event->event_type);
                        @endphp
                        <div class="d-flex align-items-start mb-2" style="gap: 8px;">
                            <i class="fas {{ $icon }} mt-1" style="color: {{ $color }}; width: 18px; text-align: center;"></i>
                            <div style="flex: 1; min-width: 0;">
                                <div style="color: var(--hr-text-white); font-size: 0.92rem;">
                                    <span style="font-weight: 500;" title="{{ $event->event_type }}">{{ $eventLabel }}</span>
                                    @if($payloadReason)
                                        <span style="color: var(--hr-text-light); margin-left: 6px;">{{ $payloadReason }}</span>
                                    @endif
                                </div>
                                <small style="color: var(--hr-text-muted);">
                                    @hrDate($event->occurred_at)
                                    @if($event->source_plugin && $event->source_plugin !== 'hr-manager')
                                        &middot; via <em>{{ $event->source_plugin }}</em>
                                    @endif
                                </small>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted text-center mb-0">{{ trans('hr-manager::players.no_history') }}</p>
                    @endforelse
                </div>
            </div>

            {{-- Notes (all surfaces) --}}
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-sticky-note"></i> {{ trans('hr-manager::players.all_notes') }}
                        <small style="color: var(--hr-text-muted);">({{ $notes->count() }})</small>
                    </h3>
                    <div class="card-tools">
                        <button class="btn btn-sm btn-hr-primary btn-icon" data-toggle="modal" data-target="#addPlayerNoteModal">
                            <i class="fas fa-plus"></i> {{ trans('hr-manager::players.add_player_note') }}
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    @forelse($notes as $note)
                        <div class="note-item {{ $note->is_private ? 'note-private' : 'note-public' }}">
                            <div class="note-meta">
                                <strong>{{ $noteAuthorNames[$note->author_id] ?? ('User #' . $note->author_id) }}</strong>
                                @if(in_array((int) $note->author_id, $noteAuthorAdmins ?? [], true))
                                    <span class="badge ml-1" style="background: var(--hr-warning, #ffc107); color: #1a1a1a; font-weight: 600;" title="{{ trans('hr-manager::notes.admin_title') }}">{{ trans('hr-manager::notes.admin_badge') }}</span>
                                @endif
                                <span class="badge badge-hr {{ $note->is_private ? 'badge-private' : 'badge-public' }} ml-1">
                                    {{ $note->is_private ? trans('hr-manager::notes.private') : trans('hr-manager::notes.public') }}
                                </span>
                                <span class="badge badge-secondary ml-1" title="Note scope">{{ ucfirst($note->noteable_type) }}</span>
                                <span class="float-right">@hrDate($note->created_at)</span>
                            </div>
                            <div class="note-content">{{ $note->content }}</div>
                            @if($note->author_id === auth()->user()->id)
                                <div class="mt-2">
                                    <form method="POST" action="{{ route('hr-manager.notes.destroy', $note->id) }}" class="d-inline"
                                          onsubmit="return confirm(@js(trans('hr-manager::notes.confirm_delete')))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger btn-icon">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-muted text-center">{{ trans('hr-manager::notes.no_notes') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- RIGHT: Status actions sidebar --}}
        <div class="col-md-4">

            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-tools"></i> {{ trans('hr-manager::players.actions') }}</h3>
                </div>
                <div class="card-body">
                    {{-- LOA form --}}
                    <details class="mb-3">
                        <summary style="cursor: pointer; color: var(--hr-text-white); font-weight: 600;">
                            <i class="fas fa-umbrella-beach text-warning"></i> {{ trans('hr-manager::players.mark_loa') }}
                        </summary>
                        <div style="padding: 10px 0;">
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.mark_loa_help') }}</small>
                            <form method="POST" action="{{ route('hr-manager.players.loa', $user->id) }}" class="mt-2">
                                @csrf
                                <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                                <div class="form-group">
                                    <label style="font-size: 0.85rem;">{{ trans('hr-manager::players.until_optional') }}</label>
                                    <input type="date" name="loa_until" class="form-control form-control-sm">
                                </div>
                                <div class="form-group">
                                    <label style="font-size: 0.85rem;">{{ trans('hr-manager::players.reason_optional') }}</label>
                                    <input type="text" name="reason" class="form-control form-control-sm" maxlength="500">
                                </div>
                                <button type="submit" class="btn btn-sm btn-hr-primary btn-block">
                                    <i class="fas fa-umbrella-beach"></i> {{ trans('hr-manager::players.mark_loa') }}
                                </button>
                            </form>
                        </div>
                    </details>

                    {{-- Mark for Purge form (director-only at controller level) --}}
                    @can('hr-manager.director')
                        <details class="mb-3">
                            <summary style="cursor: pointer; color: var(--hr-text-white); font-weight: 600;">
                                <i class="fas fa-user-times text-danger"></i> {{ trans('hr-manager::players.mark_for_purge') }}
                            </summary>
                            <div style="padding: 10px 0;">
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.mark_for_purge_help') }}</small>
                                <form method="POST" action="{{ route('hr-manager.players.mark-for-purge', $user->id) }}" class="mt-2">
                                    @csrf
                                    <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                                    <div class="form-group">
                                        <label style="font-size: 0.85rem;">{{ trans('hr-manager::players.scheduled_for') }}</label>
                                        <input type="date" name="purge_scheduled_for" class="form-control form-control-sm">
                                    </div>
                                    <div class="form-group">
                                        <label style="font-size: 0.85rem;">{{ trans('hr-manager::players.reason_optional') }}</label>
                                        <input type="text" name="reason" class="form-control form-control-sm" maxlength="500">
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-block">
                                        <i class="fas fa-user-times"></i> {{ trans('hr-manager::players.mark_for_purge') }}
                                    </button>
                                </form>
                            </div>
                        </details>
                    @endcan

                    {{-- Clear status --}}
                    @if($status && $status->status !== 'active')
                        <form method="POST" action="{{ route('hr-manager.players.clear-status', $user->id) }}" class="mb-2">
                            @csrf
                            <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                            <button type="submit" class="btn btn-sm btn-hr-secondary btn-block">
                                <i class="fas fa-undo"></i>
                                {{ $status->status === 'marked_for_purge' ? trans('hr-manager::players.cancel_purge') : trans('hr-manager::players.clear_status') }}
                            </button>
                        </form>
                    @endif

                    {{-- Refresh assessments --}}
                    <form method="POST" action="{{ route('hr-manager.players.refresh', $user->id) }}">
                        @csrf
                        <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                        <button type="submit" class="btn btn-sm btn-hr-secondary btn-block">
                            <i class="fas fa-sync-alt"></i> {{ trans('hr-manager::players.refresh_assessments') }}
                        </button>
                    </form>
                </div>
            </div>

            <a href="{{ route('hr-manager.players.index', ['corporation_id' => $corporationId]) }}" class="btn btn-hr-secondary btn-block btn-icon">
                <i class="fas fa-arrow-left"></i> {{ trans('hr-manager::players.players') }}
            </a>
        </div>
    </div>

    {{-- =================================================================
         SeAT squad memberships (account-level). Director-only. Lets a
         director see which squads the player carries and clear them as
         part of a purge. Removal mirrors SeAT's native kick, so any
         Connector-managed Discord roles cascade off.
         ================================================================= --}}
    @can('hr-manager.director')
        @php
            $removableSquads = array_values(array_filter($squads, fn ($s) => $s['removable']));
            $keptSquads      = array_values(array_filter($squads, fn ($s) => $s['type_removable'] && $s['excluded']));
            $autoSquads      = array_values(array_filter($squads, fn ($s) => ! $s['type_removable']));
        @endphp
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-user-friends"></i> {{ trans('hr-manager::players.squads_heading') }}
                </h3>
            </div>
            <div class="card-body">
                @if(empty($squads))
                    <p style="color: var(--hr-text-muted); margin: 0;">{{ trans('hr-manager::players.squads_empty') }}</p>
                @else
                    <p style="color: var(--hr-text-muted); font-size: 0.85rem;">{{ trans('hr-manager::players.squads_intro') }}</p>

                    {{-- Manual / hidden squads: explicit membership, safe to remove. --}}
                    @if(!empty($removableSquads))
                        <h6 style="color: var(--hr-text-light); margin-bottom: 8px;">{{ trans('hr-manager::players.squads_manual_heading') }}</h6>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                            @foreach($removableSquads as $squad)
                                <span class="badge" style="background: rgba(255,255,255,0.05); color: var(--hr-text-light); border: 1px solid rgba(255,255,255,0.12); padding: 6px 10px; font-size: 0.8rem; font-weight: normal;">
                                    <i class="fas fa-user-friends"></i> {{ $squad['name'] }}
                                    <small style="color: var(--hr-text-muted);">({{ ucfirst($squad['type']) }}@if($squad['member_since']), {{ $squad['member_since'] }}@endif)</small>
                                </span>
                            @endforeach
                        </div>
                        <form method="POST" action="{{ route('hr-manager.players.remove-squads', $user->id) }}"
                              onsubmit="return confirm('{{ trans('hr-manager::players.squads_remove_confirm', ['count' => count($removableSquads)]) }}');">
                            @csrf
                            <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-user-minus"></i> {{ trans('hr-manager::players.squads_remove_all', ['count' => count($removableSquads)]) }}
                            </button>
                        </form>
                        <small style="color: var(--hr-text-muted); display: block; margin-top: 10px;">{{ trans('hr-manager::players.squads_remove_note') }}</small>
                    @else
                        <p style="color: var(--hr-text-muted); font-size: 0.85rem;">{{ trans('hr-manager::players.squads_none_manual') }}</p>
                    @endif

                    {{-- Excluded manual/hidden squads: the operator put these on the
                         never-touch list (Settings -> Squad cleanup; e.g. Former
                         Member / Alliance keep-in-touch), so HR leaves them even
                         during a purge. Shown so the director knows they were kept
                         on purpose. --}}
                    @if(!empty($keptSquads))
                        <hr style="border-color: rgba(255,255,255,0.08); margin: 14px 0 12px;">
                        <h6 style="color: var(--hr-text-light); margin-bottom: 8px;">
                            <i class="fas fa-lock"></i> {{ trans('hr-manager::players.squads_kept_heading') }}
                        </h6>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                            @foreach($keptSquads as $squad)
                                <span class="badge" style="background: rgba(255,255,255,0.03); color: var(--hr-text-muted); border: 1px dashed rgba(255,255,255,0.18); padding: 6px 10px; font-size: 0.8rem; font-weight: normal;">
                                    <i class="fas fa-lock"></i> {{ $squad['name'] }}
                                    <small>({{ ucfirst($squad['type']) }})</small>
                                </span>
                            @endforeach
                        </div>
                        <small style="color: var(--hr-text-muted); display: block;">{{ trans('hr-manager::players.squads_kept_note') }}</small>
                    @endif

                    {{-- Auto squads: SeAT manages membership from filters; HR must
                         NOT detach (it would just be re-added on the next sync).
                         Shown as information so the operator knows they resolve
                         themselves once the player no longer matches. --}}
                    @if(!empty($autoSquads))
                        <hr style="border-color: rgba(255,255,255,0.08); margin: 14px 0 12px;">
                        <h6 style="color: var(--hr-text-light); margin-bottom: 8px;">
                            <i class="fas fa-robot"></i> {{ trans('hr-manager::players.squads_auto_heading') }}
                        </h6>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                            @foreach($autoSquads as $squad)
                                <span class="badge" style="background: rgba(102,126,234,0.12); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.35); padding: 6px 10px; font-size: 0.8rem; font-weight: normal;">
                                    <i class="fas fa-robot"></i> {{ $squad['name'] }}
                                    @if($squad['member_since'])<small style="color: var(--hr-text-muted);">({{ $squad['member_since'] }})</small>@endif
                                </span>
                            @endforeach
                        </div>
                        <small style="color: var(--hr-text-muted); display: block;">{{ trans('hr-manager::players.squads_auto_note') }}</small>
                    @endif
                @endif
            </div>
        </div>
    @endcan

    {{-- =================================================================
         Identity admin: ownership audit trail (every mapping current +
         historical), reassign action per current mapping, merge action.
         Director-only. Surfaces the data that lived on the standalone
         /identity/{id} page before the surface merge.
         ================================================================= --}}
    @can('hr-manager.director')
        @if(!empty($identity))
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-user-circle"></i>
                        {{ trans('hr-manager::players.identity_admin_heading', ['n' => $identity->id]) }}
                    </h3>
                    <div class="card-tools">
                        <small style="color: var(--hr-text-muted);">
                            {{ trans('hr-manager::players.identity_admin_subtitle') }}
                        </small>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Clarify the auto-linked nature so directors don't
                         think they need to reassign correctly-linked
                         characters. Reassign is a RARE account-takeover
                         action, not routine maintenance. --}}
                    <div class="alert" style="background: rgba(23, 162, 184, 0.1); border: 1px solid rgba(23, 162, 184, 0.3); color: var(--hr-text-light); font-size: 0.85rem;">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::players.identity_admin_note') !!}
                    </div>

                    @if($identity->notes_summary)
                        <div class="mb-3" style="padding: 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--hr-border, rgba(255,255,255,0.08)); border-radius: 4px; color: var(--hr-text-light); white-space: pre-wrap;">{{ $identity->notes_summary }}</div>
                    @endif

                    @if($identity->mappings->isEmpty())
                        <p class="text-muted mb-0">{{ trans('hr-manager::identity.no_characters') }}</p>
                    @else
                        @foreach($identity->mappings as $mapping)
                            @php $cid = $mapping->character_id; $cname = $identityCharNames[$cid] ?? ('Character #' . $cid); @endphp
                            <div class="d-flex align-items-center mb-2" style="gap: 12px; padding: 10px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 4px;">
                                <img src="https://images.evetech.net/characters/{{ $cid }}/portrait?size=48" width="48" height="48" style="border-radius: 50%;" alt="" onerror="this.style.display='none'">
                                <div style="flex: 1;">
                                    <a href="{{ route('hr-manager.members.show', $cid) }}" style="color: var(--hr-text-white); font-weight: 600; text-decoration: none;">{{ $cname }}</a>
                                    <small class="ml-2" style="color: var(--hr-text-muted);">#{{ $cid }}</small>
                                    <div style="font-size: 0.85rem; color: var(--hr-text-muted);">
                                        @php
                                            // Humanize the raw reason code so directors
                                            // see "Auto-linked from SeAT" not "auto_seat".
                                            $reasonLabel = trans('hr-manager::identity.reason_' . $mapping->reason);
                                            if (str_starts_with($reasonLabel, 'hr-manager::')) {
                                                $reasonLabel = $mapping->reason; // unknown code → raw
                                            }
                                        @endphp
                                        {{ $reasonLabel }}
                                        · @hrDate($mapping->effective_from)
                                        @if($mapping->effective_to)
                                            &rarr; @hrDate($mapping->effective_to)
                                            <span class="badge badge-secondary ml-1">{{ trans('hr-manager::identity.historical') }}</span>
                                        @else
                                            <span class="badge badge-hr badge-accepted ml-1">{{ trans('hr-manager::identity.current') }}</span>
                                        @endif
                                    </div>
                                </div>
                                @if(!$mapping->effective_to)
                                    {{-- Reassign is intentionally low-emphasis: it's
                                         a rare account-takeover action, not routine.
                                         Link-style button + tooltip make that clear. --}}
                                    <button type="button" class="btn btn-sm btn-link"
                                            style="color: var(--hr-text-muted); padding: 2px 6px;"
                                            data-toggle="modal" data-target="#reassignModal-{{ $cid }}"
                                            title="{{ trans('hr-manager::identity.reassign_btn_help') }}">
                                        <i class="fas fa-exchange-alt"></i> {{ trans('hr-manager::identity.reassign_btn') }}
                                    </button>
                                @endif
                            </div>

                            @if(!$mapping->effective_to)
                                {{-- Reassign modal per active mapping --}}
                                <div class="modal fade" id="reassignModal-{{ $cid }}" tabindex="-1" role="dialog">
                                    <div class="modal-dialog" role="document">
                                        <div class="modal-content" style="background: var(--hr-dark-card, #1e222b); color: var(--hr-text-light);">
                                            <form method="POST" action="{{ route('hr-manager.players.reassign-character', ['id' => $user->id, 'characterId' => $cid]) }}">
                                                @csrf
                                                <div class="modal-header">
                                                    <h5 class="modal-title">{{ trans('hr-manager::identity.reassign_title', ['name' => $cname]) }}</h5>
                                                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::identity.reassign_help') }}</p>
                                                    <div class="form-group">
                                                        <label>{{ trans('hr-manager::identity.target_label') }}</label>
                                                        <input type="text" name="target" class="form-control" required maxlength="96" placeholder="{{ trans('hr-manager::identity.target_placeholder') }}">
                                                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::identity.target_help') }}</small>
                                                    </div>
                                                    <div class="form-group">
                                                        <label>{{ trans('hr-manager::identity.reason_input_label') }}</label>
                                                        <textarea name="reason" class="form-control" rows="2" maxlength="1000" placeholder="{{ trans('hr-manager::identity.reason_input_placeholder') }}"></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-hr-secondary" data-dismiss="modal">{{ trans('hr-manager::identity.cancel') }}</button>
                                                    <button type="submit" class="btn btn-hr-primary"><i class="fas fa-exchange-alt"></i> {{ trans('hr-manager::identity.reassign_confirm') }}</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    @endif

                    <hr style="border-color: rgba(255,255,255,0.06); margin: 16px 0;">

                    {{-- Merge identity action --}}
                    <h5 style="color: var(--hr-text-white);"><i class="fas fa-object-group"></i> {{ trans('hr-manager::identity.merge_heading') }}</h5>
                    <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::identity.merge_help') }}</p>
                    <form method="POST" action="{{ route('hr-manager.players.merge-identity', ['id' => $user->id]) }}">
                        @csrf
                        <div class="form-group">
                            <label>{{ trans('hr-manager::identity.merge_from_label') }}</label>
                            <input type="number" name="from" class="form-control" required min="1" placeholder="{{ trans('hr-manager::identity.merge_from_placeholder') }}">
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::identity.merge_from_help') }}</small>
                        </div>
                        <div class="form-group">
                            <label>{{ trans('hr-manager::identity.merge_notes_label') }}</label>
                            <textarea name="notes" class="form-control" rows="2" maxlength="1000"></textarea>
                        </div>
                        <button type="submit" class="btn btn-hr-primary btn-icon" onclick="return confirm(@js(trans('hr-manager::identity.merge_confirm')))">
                            <i class="fas fa-object-group"></i> {{ trans('hr-manager::identity.merge_btn') }}
                        </button>
                    </form>
                </div>
            </div>
        @endif
    @endcan

</div>

{{-- Add player note modal (hoisted outside .hr-manager-wrapper) --}}
@push('hr-modals')
@include('hr-manager::partials._hr_modal_button_styles')
<div class="modal fade" id="addPlayerNoteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="{{ route('hr-manager.players.notes', $user->id) }}">
                @csrf
                <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('hr-manager::players.add_player_note') }}</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted" style="font-size: 0.85rem;">{{ trans('hr-manager::players.note_player_help') }}</p>
                    <div class="form-group">
                        <label>{{ trans('hr-manager::notes.note_content') }}</label>
                        <textarea name="content" class="form-control" rows="4" required maxlength="5000"></textarea>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_private" value="1" class="form-check-input" id="playerNotePrivate">
                        <label class="form-check-label" for="playerNotePrivate">
                            {{ trans('hr-manager::notes.make_private') }}
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-hr-secondary" data-dismiss="modal">{{ trans('hr-manager::settings.cancel') }}</button>
                    <button type="submit" class="btn btn-hr-primary btn-icon">
                        <i class="fas fa-save"></i> {{ trans('hr-manager::notes.save_note') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endpush

@stack('hr-modals')
@endsection

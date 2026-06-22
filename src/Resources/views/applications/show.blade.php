@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::applications.application_detail'))
@section('page_header', trans('hr-manager::applications.application_detail'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.9">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    {{-- Alert Messages --}}
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

    {{-- Watchlist match banner — full-width above the columns so it's
         the FIRST thing a recruiter sees on this page. Blacklist gets
         a red gradient; whitelist gets a green gradient. --}}
    @if(!empty($watchlistMatch))
        @php
            $isBlack = $watchlistMatch->list_type === 'blacklist';
            $bannerClass = $isBlack ? 'watchlist-banner-blacklist' : 'watchlist-banner-whitelist';
            $sevBadge = ['low' => 'badge-secondary', 'medium' => 'badge-warning', 'high' => 'badge-danger'][$watchlistMatch->severity] ?? 'badge-secondary';
        @endphp
        <div class="watchlist-match-banner {{ $bannerClass }}">
            <div style="display: flex; align-items: flex-start; gap: 18px;">
                <div style="font-size: 2.4rem; flex-shrink: 0;">
                    @if($isBlack)
                        <i class="fas fa-ban"></i>
                    @else
                        <i class="fas fa-check-circle"></i>
                    @endif
                </div>
                <div style="flex: 1;">
                    <strong style="font-size: 1.2rem;">
                        @if($isBlack)
                            {{ trans('hr-manager::watchlist.app_match_blacklist_heading') }}
                        @else
                            {{ trans('hr-manager::watchlist.app_match_whitelist_heading') }}
                        @endif
                    </strong>
                    @if($isBlack)
                        <span class="badge {{ $sevBadge }} ml-2">{{ strtoupper($watchlistMatch->severity) }}</span>
                    @endif
                    {{-- Which character tripped it. The match runs across the
                         applicant's main + linked alts, so name it explicitly and
                         flag whether it's the applying character or an alt. --}}
                    <p class="mb-1 mt-2" style="font-size: 1.05rem;">
                        <i class="fas fa-user"></i>
                        <strong>{{ $watchlistMatch->character_name ?: ('Character #' . $watchlistMatch->character_id) }}</strong>
                        @if((int) $watchlistMatch->character_id === (int) $application->character_id)
                            <span class="badge badge-light ml-1">{{ trans('hr-manager::watchlist.app_match_applying_char') }}</span>
                        @else
                            <span class="badge badge-warning ml-1"><i class="fas fa-link"></i> {{ trans('hr-manager::watchlist.app_match_alt') }}</span>
                        @endif
                    </p>
                    {{-- Additional flagged characters on this account beyond the
                         primary match (each named with its list type). --}}
                    @php $otherMatches = collect($activeMatches ?? [])->filter(fn($m) => (int) $m->id !== (int) $watchlistMatch->id); @endphp
                    @if($otherMatches->count() > 0)
                        <p class="mb-1 mt-1" style="font-size: 0.9rem; opacity: 0.92;">
                            <i class="fas fa-users"></i> {{ trans('hr-manager::watchlist.app_match_also_flagged') }}:
                            @foreach($otherMatches as $om)<strong>{{ $om->character_name ?: ('Character #' . $om->character_id) }}</strong> <span class="badge badge-secondary" style="font-size: 0.65rem;">{{ strtoupper($om->list_type) }}</span>@if(!$loop->last) · @endif @endforeach
                        </p>
                    @endif
                    @if($watchlistMatch->reason)
                        <p class="mb-1 mt-2">{{ $watchlistMatch->reason }}</p>
                    @endif
                    <small style="opacity: 0.85;">
                        {{ trans('hr-manager::watchlist.app_match_added_by') }}
                        <strong>{{ $watchlistMatch->addedByUser->name ?? 'User #' . $watchlistMatch->added_by }}</strong>
                        {{ trans('hr-manager::watchlist.app_match_added_at') }}
                        @hrDate($watchlistMatch->added_at)
                    </small>
                </div>
            </div>
        </div>
    @endif

    {{-- Cleared watchlist history — audit context. Surfaces in a
         muted card under any active match banner so recruiters see
         "this person was on the list, was cleared by X on Y for
         reason Z" even when there's no active entry today. --}}
    @if(isset($clearedHistory) && $clearedHistory->isNotEmpty())
        <div class="cleared-watchlist-banner mb-3">
            <div style="display: flex; align-items: flex-start; gap: 14px;">
                <div style="font-size: 1.6rem; flex-shrink: 0; color: var(--hr-text-muted);">
                    <i class="fas fa-history"></i>
                </div>
                <div style="flex: 1;">
                    <strong style="color: var(--hr-text-light);">
                        {{ trans_choice('hr-manager::watchlist.cleared_history_heading', $clearedHistory->count(), ['n' => $clearedHistory->count()]) }}
                    </strong>
                    <div class="mt-2">
                        @foreach($clearedHistory as $h)
                            <div class="mb-2" style="padding: 8px 12px; background: rgba(0,0,0,0.2); border-left: 3px solid var(--hr-text-muted); border-radius: 4px; font-size: 0.9rem;">
                                <div>
                                    <span class="badge badge-secondary">{{ strtoupper($h->list_type) }}</span>
                                    <strong style="color: var(--hr-text-light);">{{ $h->character_name ?: ('Character #' . $h->character_id) }}</strong>
                                    @if((int) $h->character_id !== (int) $application->character_id)
                                        <span class="badge badge-warning ml-1" style="font-size: 0.7rem;"><i class="fas fa-link"></i> {{ trans('hr-manager::watchlist.app_match_alt') }}</span>
                                    @endif
                                    <span style="color: var(--hr-text-muted);">{{ trans('hr-manager::watchlist.was_listed') }} @hrDate($h->added_at)</span>
                                    @if($h->scope_alliance_id)
                                        <span class="badge ml-1" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.4);"><i class="fas fa-link"></i> {{ trans('hr-manager::watchlist.alliance_scope') }}</span>
                                    @endif
                                </div>
                                @if($h->reason)<div class="mt-1" style="color: var(--hr-text-light);">{{ trans('hr-manager::watchlist.original_reason') }}: {{ $h->reason }}</div>@endif
                                <div class="mt-1" style="color: var(--hr-text-muted); font-size: 0.85rem;">
                                    {{ trans('hr-manager::watchlist.cleared_by') }} <strong>{{ $h->addedByUser->name ?? 'User #' . ($h->cleared_by ?? 0) }}</strong>
                                    · @hrDate($h->cleared_at)
                                    @if($h->cleared_reason) — {{ $h->cleared_reason }} @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Intel notes about this applicant. Surfaces ABOVE the
         columns so recruiters see them before reading answers.
         Director-only notes don't render for recruiters when the
         install-level setting is off — IntelService::notesForCharacter
         enforces this. --}}
    @if(isset($intelNotes) && $intelNotes->isNotEmpty())
        <div class="intel-banner mb-3">
            <div style="display: flex; align-items: flex-start; gap: 14px;">
                <div style="font-size: 1.8rem; flex-shrink: 0; color: var(--hr-primary-start);">
                    <i class="fas fa-user-secret"></i>
                </div>
                <div style="flex: 1;">
                    <strong style="font-size: 1.05rem;">
                        {{ trans_choice('hr-manager::intel.app_intel_body', $intelNotes->count(), ['n' => $intelNotes->count()]) }}
                    </strong>
                    <div class="mt-2">
                        @foreach($intelNotes->take(3) as $note)
                            <div class="mb-2" style="padding: 8px 12px; background: rgba(0,0,0,0.25); border-left: 3px solid var(--hr-primary-start); border-radius: 4px;">
                                <div class="mb-1">
                                    @foreach(($note->tags ?? []) as $tag)
                                        <span class="badge mr-1" style="background: rgba(255,255,255,0.05); color: var(--hr-text-light); border: 1px solid var(--hr-border); font-size: 0.7rem;">{{ $tag }}</span>
                                    @endforeach
                                </div>
                                <div style="color: var(--hr-text-light); white-space: pre-wrap; font-size: 0.92rem;">{{ Str::limit($note->body, 360) }}</div>
                                <small style="color: var(--hr-text-muted);">
                                    {{ trans('hr-manager::intel.added_by') }} <strong>{{ $note->author->name ?? 'User #' . $note->author_id }}</strong>
                                    · @hrDate($note->created_at)
                                </small>
                            </div>
                        @endforeach
                        @if($intelNotes->count() > 3)
                            <div>
                                <a href="{{ route('hr-manager.intel.show', $application->character_id) }}" style="color: var(--hr-primary-start);">
                                    <i class="fas fa-external-link-alt"></i> {{ trans('hr-manager::intel.back_to_index') }} — view all {{ $intelNotes->count() }}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Automated applicant assessment. Full-width, above the columns: the
         composed risk/quality readout (corp-hopping, NPC parking, age, sec,
         watchlist, PvP, SP) with a green/amber/red verdict. Intel for the
         recruiter, NOT a gate. --}}
    @if(!empty($assessment) && !empty($assessment['available']))
        @php
            $a   = $assessment;
            $ch  = $a['signals']['corp_history'] ?? ['available' => false];
            $age = $a['signals']['age'] ?? ['available' => false];
            $sec = $a['signals']['security'] ?? ['available' => false];
            $pvp = $a['signals']['pvp'] ?? ['available' => false];
            $sp  = $a['signals']['skill_points'] ?? ['available' => false];
            $implants = $a['signals']['implants'] ?? ['available' => false];
            $roles    = $a['signals']['corp_roles'] ?? ['available' => false];
            $std      = $a['signals']['standings'] ?? ['available' => false];
            // Unavailable progressive signal: distinguish "scope not granted" from
            // "scope granted but SeAT has not synced the data yet" (refreshable),
            // so the recruiter is never told a present scope is missing.
            $progLabel = fn ($sig) => ($sig['reason'] ?? 'scope') === 'pending'
                ? trans('hr-manager::applications.assess_pending')
                : trans('hr-manager::applications.assess_scope_ungranted');
            $vMeta = [
                'green' => ['#28a745', 'fa-shield-alt', trans('hr-manager::applications.assess_verdict_green')],
                'amber' => ['#ffc107', 'fa-exclamation-triangle', trans('hr-manager::applications.assess_verdict_amber')],
                'red'   => ['#dc3545', 'fa-ban', trans('hr-manager::applications.assess_verdict_red')],
            ][$a['verdict']] ?? ['#6c757d', 'fa-question-circle', '—'];
        @endphp
        <div class="card card-dark mb-3" style="border-left: 4px solid {{ $vMeta[0] }};">
            <div class="card-body">
                <div style="display:flex; align-items:center; gap:14px;">
                    <span style="font-size:2rem; color:{{ $vMeta[0] }}; flex-shrink:0;"><i class="fas {{ $vMeta[1] }}"></i></span>
                    <div>
                        <strong style="font-size:1.1rem; color:var(--hr-text-white);">{{ trans('hr-manager::applications.assess_heading') }}: {{ $vMeta[2] }}</strong>
                        <div style="color:var(--hr-text-muted); font-size:0.82rem;">{{ trans('hr-manager::applications.assess_subtitle') }}</div>
                    </div>
                    <form method="POST" action="{{ route('hr-manager.applications.refresh-assessment', $application->id) }}" style="margin-left:auto;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-hr-secondary" title="{{ trans('hr-manager::applications.assess_refresh_help') }}">
                            <i class="fas fa-sync-alt"></i> {{ trans('hr-manager::applications.assess_refresh') }}
                        </button>
                    </form>
                </div>

                @if(!empty($a['flags']))
                    <div class="mt-3">
                        @foreach($a['flags'] as $f)
                            @php $fc = ['danger'=>'#dc3545','warn'=>'#ffc107','info'=>'#36c6e0','ok'=>'#28a745'][$f['severity']] ?? '#9aa1ad'; @endphp
                            <div style="padding:8px 12px; margin-bottom:6px; background:rgba(0,0,0,0.22); border-left:3px solid {{ $fc }}; border-radius:4px;">
                                <strong style="color:{{ $fc }};">{{ $f['label'] }}</strong>
                                <span style="color:var(--hr-text-light);"> {{ $f['detail'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-2 mb-0" style="color:var(--hr-text-muted);"><i class="fas fa-check"></i> {{ trans('hr-manager::applications.assess_no_flags') }}</p>
                @endif

                {{-- Signal summary --}}
                <div style="display:flex; flex-wrap:wrap; gap:22px; margin-top:14px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.06); font-size:0.86rem;">
                    @if(!empty($ch['available']) && !empty($ch['current_corp_id']))
                        <div>
                            <div style="color:var(--hr-text-muted); font-size:0.74rem; text-transform:uppercase; letter-spacing:0.04em;">{{ trans('hr-manager::applications.assess_current_corp') }}</div>
                            <div style="color:var(--hr-text-light);">
                                <strong>{{ $ch['current_corp_name'] ?? ('#' . $ch['current_corp_id']) }}</strong>
                                @if($ch['current_is_npc'])<span class="badge badge-warning ml-1">{{ trans('hr-manager::applications.assess_npc_corp') }}</span>@else<span class="badge badge-secondary ml-1">{{ trans('hr-manager::applications.assess_player_corp') }}</span>@endif
                            </div>
                        </div>
                    @endif
                    @if(!empty($ch['available']))
                        <div>
                            <div style="color:var(--hr-text-muted); font-size:0.74rem; text-transform:uppercase; letter-spacing:0.04em;">{{ trans('hr-manager::applications.assess_corp_history') }}</div>
                            <div style="color:var(--hr-text-light);">
                                <strong>{{ $ch['corp_count'] }}</strong> corps · avg <strong>{{ $ch['avg_tenure_days'] }}d</strong> · <strong>{{ $ch['corps_last_12mo'] }}</strong> in 12mo
                                @if($ch['current_is_npc'])<span class="badge badge-warning ml-1">NPC corp now</span>@endif
                            </div>
                        </div>
                    @endif
                    <div>
                        <div style="color:var(--hr-text-muted); font-size:0.74rem; text-transform:uppercase; letter-spacing:0.04em;">{{ trans('hr-manager::applications.assess_age') }}</div>
                        <div style="color:var(--hr-text-light);"><strong>{{ $age['age_human'] ?? 'n/a' }}</strong></div>
                    </div>
                    <div>
                        <div style="color:var(--hr-text-muted); font-size:0.74rem; text-transform:uppercase; letter-spacing:0.04em;">{{ trans('hr-manager::applications.assess_sec') }}</div>
                        <div style="color:var(--hr-text-light);"><strong>{{ isset($sec['value']) ? number_format((float) $sec['value'], 2) : 'n/a' }}</strong></div>
                    </div>
                    <div>
                        <div style="color:var(--hr-text-muted); font-size:0.74rem; text-transform:uppercase; letter-spacing:0.04em;">zKillboard</div>
                        <div style="color:var(--hr-text-light);">
                            @if(!empty($pvp['available']) && (($pvp['ships_destroyed'] ?? 0) > 0 || ($pvp['ships_lost'] ?? 0) > 0))
                                <strong>{{ number_format($pvp['ships_destroyed'] ?? 0) }}</strong> kills / <strong>{{ number_format($pvp['ships_lost'] ?? 0) }}</strong> losses@if(isset($pvp['danger_ratio'])) · {{ $pvp['danger_ratio'] }}% danger@endif
                            @elseif(!empty($pvp['available']))
                                <span style="color:var(--hr-text-muted);">no PvP record</span>
                            @else
                                <span style="color:var(--hr-text-muted);">unavailable</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <div style="color:var(--hr-text-muted); font-size:0.74rem; text-transform:uppercase; letter-spacing:0.04em;">{{ trans('hr-manager::applications.assess_sp') }}</div>
                        <div style="color:var(--hr-text-light);">
                            @if(!empty($sp['available']))
                                <strong>{{ number_format($sp['total_sp']) }}</strong> SP
                            @else
                                <span style="color:var(--hr-text-muted);">{{ $progLabel($sp) }}</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <div style="color:var(--hr-text-muted); font-size:0.74rem; text-transform:uppercase; letter-spacing:0.04em;">{{ trans('hr-manager::applications.assess_implants') }}</div>
                        <div style="color:var(--hr-text-light);">
                            @if(!empty($implants['available']))
                                @if(($implants['count'] ?? 0) > 0)<strong>{{ $implants['count'] }}</strong> fitted@else <span style="color:var(--hr-text-muted);">clean clone</span>@endif
                            @else
                                <span style="color:var(--hr-text-muted);">{{ $progLabel($implants) }}</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <div style="color:var(--hr-text-muted); font-size:0.74rem; text-transform:uppercase; letter-spacing:0.04em;">{{ trans('hr-manager::applications.assess_corp_roles') }}</div>
                        <div style="color:var(--hr-text-light);">
                            @if(!empty($roles['available']))
                                @if(!empty($roles['is_director']))<span class="badge badge-warning">Director</span>@elseif(!empty($roles['elevated']))<strong>{{ implode(', ', $roles['elevated']) }}</strong>@elseif(($roles['count'] ?? 0) > 0)<strong>{{ $roles['count'] }}</strong> roles@else <span style="color:var(--hr-text-muted);">none</span>@endif
                            @else
                                <span style="color:var(--hr-text-muted);">{{ $progLabel($roles) }}</span>
                            @endif
                        </div>
                    </div>
                    @if(!empty($std['available']) || ($std['reason'] ?? '') === 'scope')
                        <div>
                            <div style="color:var(--hr-text-muted); font-size:0.74rem; text-transform:uppercase; letter-spacing:0.04em;">{{ trans('hr-manager::applications.assess_standings') }}</div>
                            <div style="color:var(--hr-text-light);">
                                @if(!empty($std['available']))
                                    @if(($std['hostile_count'] ?? 0) > 0)<span class="badge badge-warning">{{ $std['hostile_count'] }} hostile blue(s)</span>@else <span style="color:var(--hr-success, #28a745);">clean</span>@endif
                                @else
                                    <span style="color:var(--hr-text-muted);">{{ $progLabel($std) }}</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="row">
        {{-- Main Content --}}
        <div class="col-md-8">

            {{-- Character Info Header --}}
            <div class="card card-dark mb-3">
                <div class="card-body">
                    <div class="character-header">
                        <img src="https://images.evetech.net/characters/{{ $application->character_id }}/portrait?size=128"
                             class="character-avatar" alt="Portrait">
                        <div>
                            <div class="character-name">{{ $application->character->name ?? ('Character #' . $application->character_id) }}</div>
                            <div class="character-corp">
                                <span class="badge badge-hr {{ $application->status_badge_class }}">
                                    {{ $application->status_label }}
                                </span>
                                @if(!empty($priorHistory))
                                    {{-- Was accepted to this corp before (then left/re-applied). --}}
                                    <span class="badge ml-2" style="background: rgba(23,162,184,0.2); color: #36c6e0; border: 1px solid rgba(23,162,184,0.55); font-weight: 600;"
                                          title="{{ trans('hr-manager::applications.returning_player_title', ['n' => $priorHistory['count']]) }}">
                                        <i class="fas fa-history"></i> {{ trans('hr-manager::applications.returning_player') }}
                                    </span>
                                @endif
                                <span class="ml-2">Submitted @hrDate($application->submitted_at)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Re-applicant prior history — visible only when this
                 character has a previous accepted application to this
                 same corp. First-time applicants see nothing extra. --}}
            @if(!empty($priorHistory))
                @php
                    $lifetimeData = $priorHistory['lifetime']['data'] ?? null;
                    $lifetimeContributed = $lifetimeData->lifetime_total_contributed ?? ($lifetimeData['lifetime_total_contributed'] ?? null);
                    $pctData = $priorHistory['percentile']['data'] ?? null;
                    $pctValue = $pctData->percentile ?? ($pctData['percentile'] ?? null);

                    $iskFormatPrior = function ($v) {
                        if ($v === null) return '-';
                        $abs = abs((float) $v);
                        if ($abs >= 1.0e12) return number_format($v / 1.0e12, 2) . ' T';
                        if ($abs >= 1.0e9)  return number_format($v / 1.0e9, 2)  . ' B';
                        if ($abs >= 1.0e6)  return number_format($v / 1.0e6, 2)  . ' M';
                        if ($abs >= 1.0e3)  return number_format($v / 1.0e3, 2)  . ' k';
                        return number_format($v, 0);
                    };
                @endphp
                <div class="card card-dark mb-3" style="border-left: 4px solid var(--hr-info, #17a2b8);">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-history" style="color: var(--hr-info, #17a2b8);"></i>
                            {{ trans('hr-manager::applications.prior_history_heading') }}
                            <span class="badge badge-hr badge-applied ml-2">{{ $priorHistory['count'] }}</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <p style="color: var(--hr-text-light);">{{ trans('hr-manager::applications.prior_history_body', ['n' => $priorHistory['count']]) }}</p>

                        {{-- Prior accepted apps timeline --}}
                        <div class="mb-3">
                            @foreach($priorHistory['prior_apps'] as $prior)
                                <div style="padding: 6px 10px; background: var(--hr-dark-card); border: 1px solid var(--hr-border); border-radius: 4px; margin-bottom: 6px; font-size: 0.9rem;">
                                    <span class="badge badge-hr badge-accepted">{{ trans('hr-manager::applications.status_' . $prior->status) }}</span>
                                    <span style="color: var(--hr-text-light); margin-left: 8px;">
                                        Decided @hrDate($prior->decided_at)
                                    </span>
                                    @if($prior->joined_corp_at)
                                        <span style="color: var(--hr-text-muted); margin-left: 8px;">
                                            · Joined @hrDate($prior->joined_corp_at)
                                        </span>
                                    @else
                                        <span style="color: var(--hr-warning); margin-left: 8px;">
                                            · {{ trans('hr-manager::applications.never_joined') }}
                                        </span>
                                    @endif
                                    <a href="{{ route('hr-manager.applications.show', $prior->id) }}"
                                       style="color: var(--hr-info, #17a2b8); margin-left: 8px; white-space: nowrap;">
                                        <i class="fas fa-external-link-alt"></i> {{ trans('hr-manager::applications.view_application') }}
                                    </a>
                                </div>
                            @endforeach
                        </div>

                        {{-- Contribution summary (if CWM data available) --}}
                        @if($lifetimeContributed !== null || $pctValue !== null)
                            <div style="background: rgba(23, 162, 184, 0.1); border: 1px solid rgba(23, 162, 184, 0.3); border-radius: 4px; padding: 12px;">
                                <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                                    {{ trans('hr-manager::applications.prior_contribution_label') }}
                                </small>
                                <div class="mt-1" style="display: flex; gap: 20px; flex-wrap: wrap; align-items: baseline;">
                                    @if($lifetimeContributed !== null)
                                        <div>
                                            <strong style="font-size: 1.3rem; color: var(--hr-text-white);">{{ $iskFormatPrior($lifetimeContributed) }}</strong>
                                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::applications.lifetime_contributed') }}</small>
                                        </div>
                                    @endif
                                    @if($pctValue !== null)
                                        <div>
                                            <strong style="font-size: 1.3rem; color: var(--hr-text-white);">{{ (int) $pctValue }}th</strong>
                                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::applications.percentile_last_3_months') }}</small>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Answers --}}
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-clipboard-check"></i> {{ trans('hr-manager::applications.answers') }}
                    </h3>
                </div>
                <div class="card-body">
                    @forelse($application->answers as $answer)
                        <div class="mb-3">
                            <label class="d-block" style="color: var(--hr-text-white); font-weight: 600;">
                                {{ $answer->question_text }}
                            </label>
                            {{-- renderedHtml() escapes everything and auto-linkifies
                                 http(s) URLs into target=_blank anchors. Safe to
                                 echo with {!! !!} because the escape pass lives
                                 inside the model method. --}}
                            <div style="color: var(--hr-text-light); background: var(--hr-dark-card); padding: 10px; border-radius: 5px; border: 1px solid var(--hr-border); word-break: break-word;">
                                {!! $answer->renderedHtml() !!}
                            </div>
                        </div>
                    @empty
                        <p class="text-muted">No answers recorded.</p>
                    @endforelse
                </div>
            </div>

            {{-- Recruiter Access Panel — shows when the viewer has an
                 active temporary-SeAT-role grant for this applicant's
                 characters. Self-contained; no controller wiring. --}}
            @include('hr-manager::applications.partials.recruiter-access-panel')

            {{-- Notes Panel --}}
            @include('hr-manager::applications.partials.notes-panel', ['notes' => $notes, 'noteableType' => 'application', 'noteableId' => $application->id])

            {{-- Status History --}}
            @include('hr-manager::applications.partials.status-history', ['history' => $application->statusHistory, 'userNames' => $userNames ?? []])

        </div>

        {{-- Sidebar --}}
        <div class="col-md-4">

            {{-- Status Change --}}
            @if(!$application->isTerminal())
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-exchange-alt"></i> {{ trans('hr-manager::applications.change_status') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('hr-manager.applications.status', $application->id) }}">
                            @csrf
                            <div class="form-group">
                                <label>{{ trans('hr-manager::applications.select_status') }}</label>
                                <select name="status" class="form-control">
                                    @foreach($availableStatuses as $status)
                                        <option value="{{ $status }}">
                                            {{ trans('hr-manager::applications.status_' . $status) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>{{ trans('hr-manager::applications.comment') }}</label>
                                <textarea name="comment" class="form-control" rows="2" maxlength="1000"></textarea>
                            </div>
                            <button type="submit" class="btn btn-hr-primary btn-block btn-icon">
                                <i class="fas fa-check"></i> {{ trans('hr-manager::applications.update_status') }}
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            {{-- Handlers: roster of recruiters working this application.
                 Auto-populated when someone changes status; recruiters
                 can also explicitly Join / Leave / set a role label. --}}
            @php
                $viewerUserId = (int) auth()->user()->id;
                $viewerIsHandler = $application->handlers->contains('user_id', $viewerUserId);
                $viewerIsDirector = auth()->user()->can('hr-manager.director');
            @endphp
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i> {{ trans('hr-manager::applications.handlers_heading') }}
                        @if($application->handlers->count() > 0)
                            <span class="badge badge-hr badge-applied ml-1">{{ $application->handlers->count() }}</span>
                        @endif
                    </h3>
                </div>
                <div class="card-body">
                    @forelse($application->handlers as $handler)
                        <div class="d-flex align-items-center mb-2" style="gap: 10px;">
                            @if($handler->character_id)
                                <img src="https://images.evetech.net/characters/{{ $handler->character_id }}/portrait?size=32"
                                     alt=""
                                     onerror="this.style.display='none'"
                                     style="width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;">
                            @else
                                <div style="width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; background: var(--hr-border); display: flex; align-items: center; justify-content: center; color: var(--hr-text-muted);">
                                    <i class="fas fa-user"></i>
                                </div>
                            @endif
                            <div style="flex-grow: 1; min-width: 0;">
                                <div style="color: var(--hr-text-white); font-weight: 600; font-size: 0.9rem;">
                                    {{ $handler->mainCharacter->name ?? ('User #' . $handler->user_id) }}
                                </div>
                                @if($handler->role_label)
                                    <div style="color: var(--hr-text-muted); font-size: 0.8rem;">
                                        {{ $handler->role_label }}
                                    </div>
                                @endif
                            </div>
                            @if($handler->user_id === $viewerUserId || $viewerIsDirector)
                                <form method="POST" action="{{ route('hr-manager.applications.handlers.leave', $application->id) }}" class="m-0">
                                    @csrf
                                    <input type="hidden" name="user_id" value="{{ $handler->user_id }}">
                                    <button type="submit" class="btn btn-sm btn-link p-0" title="{{ trans('hr-manager::applications.handler_remove') }}"
                                            style="color: var(--hr-text-muted);"
                                            onclick="return confirm(@js(trans('hr-manager::applications.handler_remove_confirm')))">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-muted mb-2" style="font-size: 0.9rem;">{{ trans('hr-manager::applications.no_handlers') }}</p>
                    @endforelse

                    @if(!$viewerIsHandler)
                        <form method="POST" action="{{ route('hr-manager.applications.handlers.join', $application->id) }}" class="mt-3">
                            @csrf
                            <div class="form-group mb-2">
                                <input type="text" name="role_label" class="form-control form-control-sm"
                                       maxlength="64"
                                       placeholder="{{ trans('hr-manager::applications.handler_role_placeholder') }}">
                            </div>
                            <button type="submit" class="btn btn-hr-secondary btn-sm btn-block btn-icon">
                                <i class="fas fa-user-plus"></i> {{ trans('hr-manager::applications.join_as_handler') }}
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('hr-manager.applications.handlers.role', $application->id) }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $viewerUserId }}">
                            <div class="form-group mb-2">
                                <input type="text" name="role_label" class="form-control form-control-sm"
                                       maxlength="64"
                                       value="{{ $application->handlers->firstWhere('user_id', $viewerUserId)->role_label ?? '' }}"
                                       placeholder="{{ trans('hr-manager::applications.handler_role_placeholder') }}">
                            </div>
                            <button type="submit" class="btn btn-hr-secondary btn-sm btn-block btn-icon">
                                <i class="fas fa-save"></i> {{ trans('hr-manager::applications.update_my_role') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Application Info --}}
            <div class="card card-dark mb-3">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i> Info
                    </h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5" style="color: var(--hr-text-muted);">Template</dt>
                        <dd class="col-sm-7">{{ $application->template->name ?? 'Deleted' }}</dd>

                        @if($application->landing)
                            <dt class="col-sm-5" style="color: var(--hr-text-muted);">Source</dt>
                            <dd class="col-sm-7">
                                <a href="{{ $application->landing->public_url }}" target="_blank">{{ $application->landing->title }}</a>
                            </dd>
                        @endif

                        @if(!$application->eligibility_passed)
                            <dt class="col-sm-5" style="color: var(--hr-text-muted);">Eligibility</dt>
                            <dd class="col-sm-7">
                                <span class="badge badge-warning" title="Submitted via manual review escape hatch">Failed checks</span>
                            </dd>
                        @endif

                        @if($application->decided_at)
                            <dt class="col-sm-5" style="color: var(--hr-text-muted);">Decided</dt>
                            <dd class="col-sm-7">@hrDate($application->decided_at)</dd>
                        @endif

                        {{-- "Did they actually join?" outcome. Only meaningful
                             once accepted; before that it's not_applicable. --}}
                        @if($application->status === 'accepted')
                            @php $outcome = $application->join_outcome; @endphp
                            <dt class="col-sm-5" style="color: var(--hr-text-muted);">{{ trans('hr-manager::applications.outcome_label') }}</dt>
                            <dd class="col-sm-7">
                                @if($outcome === 'joined')
                                    <span class="badge badge-hr badge-accepted" title="@hrDate($application->joined_corp_at)">
                                        <i class="fas fa-check"></i> {{ trans('hr-manager::applications.outcome_joined') }}
                                    </span>
                                    <br><small class="text-muted">@hrDate($application->joined_corp_at)</small>
                                @elseif($outcome === 'pending')
                                    <span class="badge badge-hr badge-under-review">
                                        <i class="fas fa-clock"></i> {{ trans('hr-manager::applications.outcome_pending') }}
                                    </span>
                                @elseif($outcome === 'late')
                                    <span class="badge badge-warning">
                                        <i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::applications.outcome_late') }}
                                    </span>
                                @elseif($outcome === 'ghosted')
                                    <span class="badge badge-danger">
                                        <i class="fas fa-ghost"></i> {{ trans('hr-manager::applications.outcome_ghosted') }}
                                    </span>
                                @endif
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Public tracking link — share with the applicant so they
                 can check status without logging into SeAT. Token-based,
                 no auth required, no notes / handler list / internals. --}}
            @if($application->tracking_token)
                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-share-alt"></i> {{ trans('hr-manager::applications.public_tracking_link') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <p style="color: var(--hr-text-muted); font-size: 0.85rem;">
                            {{ trans('hr-manager::applications.public_tracking_link_help') }}
                        </p>
                        <div class="input-group input-group-sm">
                            <input type="text"
                                   id="applicantTrackingUrl"
                                   class="form-control"
                                   value="{{ route('hr-manager.recruit.track', ['token' => $application->tracking_token]) }}"
                                   readonly
                                   onclick="this.select();">
                            <div class="input-group-append">
                                <a href="{{ route('hr-manager.recruit.track', ['token' => $application->tracking_token]) }}"
                                   target="_blank" rel="noopener" class="btn btn-hr-secondary btn-icon"
                                   title="{{ trans('hr-manager::applications.open_in_new_tab') }}">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Back Button --}}
            <a href="{{ route('hr-manager.applications.index') }}" class="btn btn-hr-secondary btn-block btn-icon">
                <i class="fas fa-arrow-left"></i> Back to Applications
            </a>
        </div>
    </div>

</div>

{{-- Modals rendered OUTSIDE the .hr-manager-wrapper so they don't inherit
     its dark-on-dark cascade. See notes-panel partial for the @push side. --}}
@stack('hr-modals')
@endsection

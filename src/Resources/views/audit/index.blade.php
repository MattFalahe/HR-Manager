@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::audit.title'))
@section('page_header', trans('hr-manager::audit.title'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.8">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif

    @if(empty($enabled))
        {{-- Opt-in discovery / enable state --}}
        <div class="card card-dark">
            <div class="card-body text-center p-5">
                <i class="fas fa-history" style="font-size: 2.5rem; color: var(--hr-text-muted);"></i>
                <h3 class="mt-3" style="color: var(--hr-text-light);">{{ trans('hr-manager::audit.disabled_title') }}</h3>
                <p style="color: var(--hr-text-muted); max-width: 640px; margin: 0.75rem auto;">
                    {{ trans('hr-manager::audit.disabled_body') }}
                </p>
                @can('hr-manager.admin')
                    <a href="{{ route('hr-manager.settings.index') }}#features" class="btn btn-hr-primary btn-icon mt-2">
                        <i class="fas fa-cog"></i> {{ trans('hr-manager::audit.disabled_cta') }}
                    </a>
                @else
                    <p class="mt-2"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::audit.disabled_admin_note') }}</small></p>
                @endcan
            </div>
        </div>
    @else

    @php
        // Presentation-only category palette (bg / border / icon).
        $catStyle = [
            'view'         => ['bg' => 'rgba(148,163,184,0.15)', 'bd' => 'rgba(148,163,184,0.40)', 'icon' => 'fa-eye'],
            'privilege'    => ['bg' => 'rgba(102,126,234,0.18)', 'bd' => 'rgba(102,126,234,0.45)', 'icon' => 'fa-key'],
            'decision'     => ['bg' => 'rgba(20,184,166,0.18)',  'bd' => 'rgba(20,184,166,0.45)',  'icon' => 'fa-gavel'],
            'security'     => ['bg' => 'rgba(239,68,68,0.18)',   'bd' => 'rgba(239,68,68,0.50)',   'icon' => 'fa-shield-alt'],
            'notification' => ['bg' => 'rgba(234,179,8,0.18)',   'bd' => 'rgba(234,179,8,0.50)',   'icon' => 'fa-bell'],
            'management'   => ['bg' => 'rgba(56,189,248,0.16)',  'bd' => 'rgba(56,189,248,0.45)',  'icon' => 'fa-sliders-h'],
            'system'       => ['bg' => 'rgba(168,85,247,0.18)',  'bd' => 'rgba(168,85,247,0.50)',  'icon' => 'fa-robot'],
        ];
    @endphp

    {{-- Page intro banner --}}
    <div class="page-intro-banner">
        <div class="page-intro-header">
            <div class="page-intro-body">
                <h4><i class="fas fa-info-circle"></i> {{ trans('hr-manager::audit.intro_what_label') }}</h4>
                <p>{!! trans('hr-manager::audit.intro_what_body') !!}</p>
            </div>
            <span class="page-intro-visibility">
                <i class="fas fa-user-shield"></i> {{ trans('hr-manager::audit.intro_visibility') }}
            </span>
        </div>
        <div class="page-intro-when">
            <h4><i class="fas fa-database"></i> {{ trans('hr-manager::audit.intro_retention_label') }}</h4>
            <ul>
                <li>{{ trans('hr-manager::audit.intro_retention_1', ['days' => $retention]) }}</li>
                <li>{{ trans('hr-manager::audit.intro_retention_2') }}</li>
            </ul>
        </div>
    </div>

    {{-- Filters + export --}}
    <div class="card card-dark mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('hr-manager.audit.index') }}">
                <div class="row">
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small" style="color: var(--hr-text-muted);">{{ trans('hr-manager::audit.filter_category') }}</label>
                        <select name="category" class="form-control form-control-sm">
                            <option value="">{{ trans('hr-manager::audit.filter_all') }}</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat }}" {{ ($filters['category'] ?? '') === $cat ? 'selected' : '' }}>
                                    {{ trans('hr-manager::audit.cat_' . $cat) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small" style="color: var(--hr-text-muted);">{{ trans('hr-manager::audit.filter_action') }}</label>
                        <select name="action" class="form-control form-control-sm">
                            <option value="">{{ trans('hr-manager::audit.filter_all') }}</option>
                            @foreach($actions as $act)
                                <option value="{{ $act }}" {{ ($filters['action'] ?? '') === $act ? 'selected' : '' }}>{{ $act }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 col-12 mb-2">
                        <label class="small" style="color: var(--hr-text-muted);">{{ trans('hr-manager::audit.filter_actor') }}</label>
                        <select name="actor" class="form-control form-control-sm">
                            <option value="">{{ trans('hr-manager::audit.filter_all') }}</option>
                            @foreach($actors as $uid => $name)
                                <option value="{{ $uid }}" {{ (string) ($filters['actor_user_id'] ?? '') === (string) $uid ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small" style="color: var(--hr-text-muted);">{{ trans('hr-manager::audit.filter_from') }}</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="{{ $filters['from'] ?? '' }}">
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small" style="color: var(--hr-text-muted);">{{ trans('hr-manager::audit.filter_to') }}</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="{{ $filters['to'] ?? '' }}">
                    </div>
                    <div class="col-md-1 col-6 mb-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-hr-primary btn-sm btn-block"><i class="fas fa-filter"></i></button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-5 col-12 mb-2">
                        <div class="input-group input-group-sm">
                            <input type="text" name="q" class="form-control" placeholder="{{ trans('hr-manager::audit.filter_search') }}" value="{{ $filters['q'] ?? '' }}">
                            <div class="input-group-append">
                                <button class="btn btn-hr-primary" type="submit"><i class="fas fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-7 col-12 mb-2 d-flex align-items-center justify-content-end">
                        <a href="{{ route('hr-manager.audit.index') }}" class="btn btn-sm btn-outline-secondary mr-2">
                            <i class="fas fa-times"></i> {{ trans('hr-manager::audit.filter_reset') }}
                        </a>
                        <a href="{{ route('hr-manager.audit.export', request()->query()) }}" class="btn btn-sm btn-outline-info">
                            <i class="fas fa-download"></i> {{ trans('hr-manager::audit.export_csv') }}
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Log table --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-history"></i> {{ trans('hr-manager::audit.log_title') }}</h3>
            <div class="card-tools">
                <span class="badge" style="background: rgba(255,255,255,0.05); color: var(--hr-text-muted); border: 1px solid var(--hr-border);">
                    {{ trans('hr-manager::audit.total_count', ['count' => $logs->total()]) }}
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            @if($logs->isEmpty())
                <div class="p-4 text-center" style="color: var(--hr-text-muted);">{{ trans('hr-manager::audit.empty') }}</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width: 150px;">{{ trans('hr-manager::audit.col_when') }}</th>
                                <th style="width: 200px;">{{ trans('hr-manager::audit.col_actor') }}</th>
                                <th style="width: 130px;">{{ trans('hr-manager::audit.col_category') }}</th>
                                <th>{{ trans('hr-manager::audit.col_activity') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($logs as $log)
                                @php $cs = $catStyle[$log->category] ?? $catStyle['system']; @endphp
                                <tr>
                                    <td>
                                        <span title="{{ optional($log->occurred_at)->toDateTimeString() }}">@hrDate($log->occurred_at)</span><br>
                                        <small style="color: var(--hr-text-muted);">{{ optional($log->occurred_at)->diffForHumans() }}</small>
                                    </td>
                                    <td>
                                        @if($log->actor_character_id)
                                            <img src="https://images.evetech.net/characters/{{ $log->actor_character_id }}/portrait?size=32" class="rounded-circle mr-2" width="28" height="28" alt="" onerror="this.style.display='none'">
                                        @elseif(is_null($log->actor_user_id))
                                            <i class="fas fa-robot mr-2" style="color: var(--hr-text-muted);"></i>
                                        @endif
                                        <span style="color: var(--hr-text-light);">{{ $log->display_actor }}</span>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: {{ $cs['bg'] }}; color: var(--hr-text-light); border: 1px solid {{ $cs['bd'] }};">
                                            <i class="fas {{ $cs['icon'] }}"></i> {{ trans('hr-manager::audit.cat_' . $log->category) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color: var(--hr-text-light);">{{ $log->summary ?: $log->action }}</span>
                                        @if($log->display_target)
                                            <span style="color: var(--hr-text-muted);">&middot;</span>
                                            @if($log->display_target_url)
                                                <a href="{{ $log->display_target_url }}" style="color: var(--hr-accent, #667eea);">{{ $log->display_target }}</a>
                                            @else
                                                <span style="color: var(--hr-text-light);">{{ $log->display_target }}</span>
                                            @endif
                                        @endif
                                        @php
                                            $ctx = is_array($log->context) ? $log->context : [];
                                            $chips = [];
                                            if (!empty($ctx['tab']))    { $chips[] = 'tab: ' . $ctx['tab']; }
                                            if (!empty($ctx['corp']))   { $chips[] = 'corp: ' . ($corpNames[(int) $ctx['corp']] ?? $ctx['corp']); }
                                            if (!empty($ctx['corporation_id'])) { $chips[] = 'corp: ' . ($corpNames[(int) $ctx['corporation_id']] ?? $ctx['corporation_id']); }
                                            if (!empty($ctx['status'])) { $chips[] = 'status: ' . $ctx['status']; }
                                            if (!empty($ctx['q']))      { $chips[] = 'search: ' . $ctx['q']; }
                                            if (!empty($ctx['reason'])) { $chips[] = $ctx['reason']; }
                                            if (isset($ctx['from'], $ctx['to'])) { $chips[] = $ctx['from'] . ' → ' . $ctx['to']; }
                                        @endphp
                                        @foreach($chips as $chip)
                                            <span class="badge ml-1" style="background: rgba(255,255,255,0.04); color: var(--hr-text-muted); border: 1px solid var(--hr-border); font-weight: 400;">{{ $chip }}</span>
                                        @endforeach
                                        <span class="badge ml-1" style="background: transparent; color: var(--hr-text-muted); border: 1px dashed var(--hr-border); font-weight: 400;">{{ $log->action }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($logs->hasPages())
            <div class="card-footer">{{ $logs->appends(request()->query())->links() }}</div>
        @endif
    </div>

    @endif
</div>
@endsection

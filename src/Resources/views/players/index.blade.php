@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::players.players'))
@section('page_header', trans('hr-manager::players.players'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.5">
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

    {{-- Page intro banner — "What this page does / When to use" so
         users instantly know the page's scope. See also the Members
         page for the per-character view. --}}
    <div class="page-intro-banner">
        <div class="page-intro-header">
            <div class="page-intro-body">
                <h4><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.intro_what_label') }}</h4>
                <p>{!! trans('hr-manager::players.intro_what_body') !!}</p>
            </div>
            <span class="page-intro-visibility"><i class="fas fa-users"></i> {{ trans('hr-manager::players.intro_visibility') }}</span>
        </div>
        <div class="page-intro-when">
            <h4><i class="fas fa-list-ul"></i> {{ trans('hr-manager::players.intro_when_label') }}</h4>
            <ul>
                <li>{{ trans('hr-manager::players.intro_when_1') }}</li>
                <li>{{ trans('hr-manager::players.intro_when_2') }}</li>
                <li>{{ trans('hr-manager::players.intro_when_3') }}</li>
            </ul>
        </div>
    </div>

    @if(!$tierAuto)
        <div class="alert" style="background: rgba(255,193,7,0.08); border: 1px solid rgba(255,193,7,0.3); color: var(--hr-text-light);">
            <i class="fas fa-info-circle text-warning"></i>
            {{ trans('hr-manager::players.tier_auto_unavailable') }}
        </div>
    @endif

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-user-friends"></i> {{ trans('hr-manager::players.players') }}
            </h3>
            <div class="card-tools">
                <form method="GET" action="{{ route('hr-manager.players.index') }}" class="d-inline-flex" style="gap: 6px;">
                    <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="{{ trans('hr-manager::players.search_placeholder') }}"
                           value="{{ request('search') }}">
                    <button type="submit" class="btn btn-sm btn-hr-secondary"><i class="fas fa-search"></i></button>
                </form>
            </div>
        </div>

        {{-- Corp switcher (dropdown — admin contexts on large SeAT installs can
             have hundreds of corps; the old grid of ticker buttons was unusable
             at that scale). --}}
        @if($corporations->count() > 1)
            <div class="card-body p-2" style="background: var(--hr-dark-card); border-bottom: 1px solid var(--hr-border);">
                <form method="GET" action="{{ route('hr-manager.players.index') }}" class="form-inline" style="gap: 8px;">
                    <label class="mb-0 mr-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.corporation_context') }}:</label>
                    <select name="corporation_id" class="form-control form-control-sm" style="min-width: 280px;" onchange="this.form.submit()">
                        @foreach($corporations as $corp)
                            <option value="{{ $corp->corporation_id }}" {{ (int) $corp->corporation_id === (int) $corporationId ? 'selected' : '' }}>
                                @if(!empty($corp->ticker))[{{ $corp->ticker }}] @endif{{ $corp->name }}
                            </option>
                        @endforeach
                    </select>
                    @if(request('search'))
                        <input type="hidden" name="search" value="{{ request('search') }}">
                    @endif
                    <noscript><button type="submit" class="btn btn-sm btn-hr-primary ml-2">Go</button></noscript>
                </form>
            </div>
        @endif

        <div class="card-body p-0">
            @if($players->isEmpty())
                <div class="p-4 text-center text-muted">{{ trans('hr-manager::players.no_players') }}</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::players.main_character') }}</th>
                                <th>{{ trans('hr-manager::players.alts') }}</th>
                                <th>{{ trans('hr-manager::players.in_corp') }}</th>
                                <th>{{ trans('hr-manager::players.tier') }}</th>
                                <th>{{ trans('hr-manager::players.status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($players as $user)
                                @php
                                    $hr = $user->hr_summary;
                                    $main = $hr['main_character'];
                                    $mainId = $main->character_id ?? null;
                                    $mainName = $main->name ?? ('User #' . $user->id);
                                    $tier = $hr['tier'];
                                    $status = $hr['status'];
                                @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('hr-manager.players.show', ['id' => $user->id, 'corporation_id' => $corporationId]) }}"
                                           style="color: var(--hr-text-white); text-decoration: none;">
                                            @if($mainId)
                                                <img src="https://images.evetech.net/characters/{{ $mainId }}/portrait?size=32"
                                                     style="width: 28px; height: 28px; border-radius: 50%; vertical-align: middle; margin-right: 8px;"
                                                     alt="">
                                            @endif
                                            <strong>{{ $mainName }}</strong>
                                        </a>
                                    </td>
                                    <td>{{ $hr['alt_count'] }}</td>
                                    <td>
                                        <span class="badge badge-hr badge-accepted">{{ $hr['in_corp_count'] }}</span>
                                        @if($hr['alt_count'] > $hr['in_corp_count'])
                                            <span class="badge badge-hr badge-withdrawn" title="{{ trans('hr-manager::players.out_of_corp') }}">
                                                {{ $hr['alt_count'] - $hr['in_corp_count'] }}
                                            </span>
                                        @endif
                                    </td>
                                    <td>
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
                                    </td>
                                    <td>
                                        @if($status && $status->status === 'loa')
                                            <span class="badge badge-warning">
                                                <i class="fas fa-umbrella-beach"></i> {{ trans('hr-manager::players.status_loa') }}
                                            </span>
                                        @elseif($status && $status->status === 'marked_for_purge')
                                            <span class="badge badge-danger">
                                                <i class="fas fa-user-times"></i> {{ trans('hr-manager::players.status_marked_for_purge') }}
                                            </span>
                                        @else
                                            <span class="badge badge-hr badge-accepted">{{ trans('hr-manager::players.status_active') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('hr-manager.players.show', ['id' => $user->id, 'corporation_id' => $corporationId]) }}"
                                           class="btn btn-sm btn-hr-secondary btn-icon">
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="card-footer">
                    {{ $players->appends(request()->query())->links() }}
                </div>
            @endif
        </div>
    </div>

</div>
@endsection

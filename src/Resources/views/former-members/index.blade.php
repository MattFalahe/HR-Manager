@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::former.title'))
@section('page_header', trans('hr-manager::former.title'))

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

    <div class="page-intro-banner">
        <div class="page-intro-header">
            <div class="page-intro-body">
                <h4><i class="fas fa-info-circle"></i> {{ trans('hr-manager::former.intro_what_label') }}</h4>
                <p>{!! trans('hr-manager::former.intro_what_body') !!}</p>
            </div>
        </div>
    </div>

    <div class="card card-dark">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="card-title mb-0">
                <i class="fas fa-user-clock"></i> {{ trans('hr-manager::former.list_heading') }}
                <span class="badge badge-hr badge-applied ml-2">{{ count($people) }}</span>
            </h3>
            <form method="GET" action="{{ route('hr-manager.former-members.index') }}" class="form-inline mb-0">
                <input type="text" name="search" class="form-control form-control-sm mr-2"
                       value="{{ $search }}" placeholder="{{ trans('hr-manager::former.search_placeholder') }}">
                <button type="submit" class="btn btn-sm btn-hr-secondary btn-icon">
                    <i class="fas fa-search"></i> {{ trans('hr-manager::former.search') }}
                </button>
            </form>
        </div>
        <div class="card-body">

            {{-- A reconstructed row is only as complete as whatever survived,
                 so say it once at the top as well as per row. --}}
            @if($reconstructed > 0)
                <div class="alert" style="background: rgba(255,193,7,0.08); border: 1px solid rgba(255,193,7,0.3); color: var(--hr-text-light);">
                    <i class="fas fa-exclamation-triangle text-warning"></i>
                    {{ trans_choice('hr-manager::former.reconstructed_notice', $reconstructed, ['count' => $reconstructed]) }}
                </div>
            @endif

            @if(empty($people))
                <p style="color: var(--hr-text-muted);" class="mb-2">
                    <i class="fas fa-inbox"></i> {{ trans('hr-manager::former.empty') }}
                </p>
                <small style="color: var(--hr-text-muted);">{!! trans('hr-manager::former.empty_help') !!}</small>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover" style="color: var(--hr-text-light);">
                        <thead>
                            <tr style="color: var(--hr-text-muted);">
                                <th>{{ trans('hr-manager::former.col_person') }}</th>
                                <th>{{ trans('hr-manager::former.col_corps') }}</th>
                                <th style="width: 120px;">{{ trans('hr-manager::former.col_tenure') }}</th>
                                <th style="width: 150px;">{{ trans('hr-manager::former.col_left') }}</th>
                                <th style="width: 130px;">{{ trans('hr-manager::former.col_how') }}</th>
                                <th style="width: 1%;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($people as $person)
                            <tr>
                                <td>
                                    <img src="https://images.evetech.net/characters/{{ $person['lead_character'] }}/portrait?size=32"
                                         class="rounded-circle mr-2" width="32" height="32" alt=""
                                         onerror="this.style.display='none'">
                                    <a href="{{ route('hr-manager.former-members.show', $person['key']) }}" style="color: var(--hr-text-white); text-decoration: none;">
                                        <strong>{{ $person['display_name'] }}</strong>
                                    </a>
                                    @if(count($person['characters']) > 1)
                                        <span class="badge ml-1" style="background: rgba(255,255,255,0.06); color: var(--hr-text-muted); font-size: 0.62rem;">
                                            {{ trans_choice('hr-manager::former.char_count', count($person['characters']), ['count' => count($person['characters'])]) }}
                                        </span>
                                    @endif
                                    @if($person['was_blacklisted'])
                                        <span class="badge ml-1" style="background: rgba(220,53,69,0.25); color: #f5a3ac; font-size: 0.62rem;">
                                            <i class="fas fa-ban"></i> {{ trans('hr-manager::former.badge_blacklisted') }}
                                        </span>
                                    @endif
                                    @if($person['reconstructed'])
                                        <span class="badge ml-1" style="background: rgba(255,193,7,0.2); color: #ffe08a; font-size: 0.62rem;"
                                              title="{{ trans('hr-manager::former.badge_reconstructed_help') }}">
                                            {{ trans('hr-manager::former.badge_reconstructed') }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @foreach($person['corporation_ids'] as $cid)
                                        <span class="badge mr-1" style="background: rgba(255,255,255,0.05); color: var(--hr-text-light); border: 1px solid var(--hr-border); font-size: 0.68rem;">
                                            {{ $corpNames[$cid] ?? ('#' . $cid) }}
                                        </span>
                                    @endforeach
                                </td>
                                <td>
                                    @if($person['days_in_corp'])
                                        {{ number_format($person['days_in_corp']) }} {{ trans('hr-manager::former.days') }}
                                    @else
                                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::former.unknown') }}</small>
                                    @endif
                                </td>
                                <td>
                                    @if($person['left_at'])
                                        @hrDateShort($person['left_at'])
                                    @else
                                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::former.unknown') }}</small>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $howStyle = [
                                            'purged'   => ['bg' => 'rgba(220,53,69,0.2)',  'fg' => '#f5a3ac'],
                                            'kicked'   => ['bg' => 'rgba(220,53,69,0.2)',  'fg' => '#f5a3ac'],
                                            'resigned' => ['bg' => 'rgba(255,255,255,0.06)', 'fg' => 'var(--hr-text-muted)'],
                                        ][$person['departure_type']] ?? ['bg' => 'rgba(255,255,255,0.06)', 'fg' => 'var(--hr-text-muted)'];
                                    @endphp
                                    <span class="badge" style="background: {{ $howStyle['bg'] }}; color: {{ $howStyle['fg'] }}; font-size: 0.68rem;">
                                        {{ trans('hr-manager::former.departure_' . $person['departure_type']) }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('hr-manager.former-members.show', $person['key']) }}"
                                       class="btn btn-sm btn-hr-secondary btn-icon" style="white-space: nowrap;">
                                        <i class="fas fa-folder-open"></i> {{ trans('hr-manager::former.open') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <small class="d-block mt-2" style="color: var(--hr-text-muted);">
                    <i class="fas fa-info-circle"></i> {{ trans('hr-manager::former.rejoin_note') }}
                </small>
            @endif
        </div>
    </div>
</div>
@endsection

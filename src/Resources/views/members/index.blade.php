@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::members.members'))
@section('page_header', trans('hr-manager::members.members'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.7">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    {{-- Page intro banner — "What this page does / When to use" so
         users instantly know the page's scope. See also the Players
         page for the human-level alternative. --}}
    <div class="page-intro-banner">
        <div class="page-intro-header">
            <div class="page-intro-body">
                <h4><i class="fas fa-info-circle"></i> {{ trans('hr-manager::members.intro_what_label') }}</h4>
                <p>{!! trans('hr-manager::members.intro_what_body') !!}</p>
            </div>
            <span class="page-intro-visibility"><i class="fas fa-users"></i> {{ trans('hr-manager::members.intro_visibility') }}</span>
        </div>
        <div class="page-intro-when">
            <h4><i class="fas fa-list-ul"></i> {{ trans('hr-manager::members.intro_when_label') }}</h4>
            <ul>
                <li>{{ trans('hr-manager::members.intro_when_1') }}</li>
                <li>{{ trans('hr-manager::members.intro_when_2') }}</li>
                <li>{{ trans('hr-manager::members.intro_when_3') }}</li>
            </ul>
        </div>
    </div>

    {{-- Roster-source hint. Renders only when the page is reading from
         the sparse character_affiliations fallback instead of SeAT's
         authoritative corporation_members table. Tells the admin
         exactly what to wire up to see the full roster. --}}
    @if(!empty($rosterStatus) && $rosterStatus['needs_director_token'])
        <div class="warning-box mb-3">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>{{ trans('hr-manager::members.roster_partial_heading') }}</strong>
                <p class="mb-0">{{ trans('hr-manager::members.roster_partial_body') }}</p>
            </div>
        </div>
    @endif

    {{-- Corp selector + headline counts --}}
    <div class="card card-dark mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center" style="gap: 16px;">
                @if($corporations->count() > 1)
                    <form method="GET" action="{{ route('hr-manager.members.index') }}" class="form-inline" style="gap: 8px;">
                        <label class="mb-0 mr-2" style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.corporation_context') }}:</label>
                        <select name="corporation_id" class="form-control form-control-sm" style="min-width: 280px;" onchange="this.form.submit()">
                            @foreach($corporations as $corp)
                                <option value="{{ $corp->corporation_id }}" {{ (int) $corp->corporation_id === (int) $corporationId ? 'selected' : '' }}>
                                    @if(!empty($corp->ticker))[{{ $corp->ticker }}] @endif{{ $corp->name }}
                                </option>
                            @endforeach
                        </select>
                        @if(request('search'))<input type="hidden" name="search" value="{{ request('search') }}">@endif
                        <noscript><button type="submit" class="btn btn-sm btn-hr-primary ml-2">Go</button></noscript>
                    </form>
                @endif

                <div class="ml-auto">
                    <small style="color: var(--hr-text-muted);">
                        <strong style="color: var(--hr-text-white);">{{ number_format($totalMembers) }}</strong> {{ trans('hr-manager::members.total_chars') }} ·
                        <strong style="color: var(--hr-success, #28a745);">{{ number_format($registeredCount) }}</strong> {{ trans('hr-manager::members.registered_chars') }} ·
                        <strong style="color: var(--hr-warning, #ffc107);">{{ number_format($unregisteredCount) }}</strong> {{ trans('hr-manager::members.unregistered_chars') }}
                    </small>
                </div>
            </div>
        </div>
    </div>

    {{-- Search --}}
    <div class="card card-dark mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('hr-manager.members.index') }}" class="form-inline">
                <input type="hidden" name="corporation_id" value="{{ $corporationId }}">
                <div class="input-group">
                    <input type="text" name="search" class="form-control"
                           placeholder="{{ trans('hr-manager::members.search_placeholder') }}" value="{{ request('search') }}">
                    <div class="input-group-append">
                        <button class="btn btn-hr-primary" type="submit">
                            <i class="fas fa-search"></i> {{ trans('hr-manager::members.search') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Members Table --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-users"></i> {{ trans('hr-manager::members.members') }}
            </h3>
        </div>
        <div class="card-body p-0">
            @if($members->isEmpty())
                <div class="p-4 text-center text-muted">
                    {{ trans('hr-manager::members.no_members') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::members.character') }}</th>
                                <th>{{ trans('hr-manager::members.registration') }}</th>
                                <th>{{ trans('hr-manager::members.corporation') }}</th>
                                <th>{{ trans('hr-manager::members.alliance') }}</th>
                                <th>{{ trans('hr-manager::applications.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($members as $member)
                                <tr>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $member->character_id }}/portrait?size=32"
                                             class="rounded-circle mr-2" width="32" height="32" alt="">
                                        <strong>{{ $member->display_name }}</strong>
                                        <small class="ml-2" style="color: var(--hr-text-muted);">#{{ $member->character_id }}</small>
                                    </td>
                                    <td>
                                        @php $ts = $member->token_status ?? ($member->is_registered ? 'valid' : 'never_linked'); @endphp
                                        @if($ts === 'valid')
                                            <span class="badge badge-hr badge-accepted" title="{{ trans('hr-manager::members.token_valid_help') }}">
                                                <i class="fas fa-check-circle"></i> {{ trans('hr-manager::members.token_valid') }}
                                            </span>
                                        @elseif($ts === 'insufficient')
                                            <span class="badge badge-hr badge-review" title="{{ trans('hr-manager::members.token_insufficient_help') }}: {{ implode(', ', $member->token_missing ?? []) }}">
                                                <i class="fas fa-user-lock"></i> {{ trans('hr-manager::members.token_insufficient') }}
                                            </span>
                                        @elseif($ts === 'lost')
                                            <span class="badge badge-hr badge-rejected" title="{{ trans('hr-manager::members.token_lost_help') }}">
                                                <i class="fas fa-unlink"></i> {{ trans('hr-manager::members.token_lost') }}
                                            </span>
                                        @else
                                            <span class="badge badge-hr badge-withdrawn" title="{{ trans('hr-manager::members.unregistered_help') }}">
                                                <i class="fas fa-user-slash"></i> {{ trans('hr-manager::members.token_never') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ $member->corporation->name ?? '-' }}</td>
                                    <td>{{ $member->alliance_id ? ($member->alliance->name ?? 'Alliance #' . $member->alliance_id) : '-' }}</td>
                                    <td>
                                        <a href="{{ route('hr-manager.members.show', $member->character_id) }}"
                                           class="btn btn-sm btn-hr-secondary btn-icon">
                                            <i class="fas fa-user"></i> {{ trans('hr-manager::applications.view') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($members->hasPages())
            <div class="card-footer">
                {{ $members->appends(request()->query())->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

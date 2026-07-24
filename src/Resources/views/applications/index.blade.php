@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::applications.applications'))
@section('page_header', trans('hr-manager::applications.applications'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    {{-- Status Filter Pills --}}
    <div class="card card-dark mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('hr-manager.applications.index') }}"
                   class="btn btn-sm {{ !request('status') ? 'btn-hr-primary' : 'btn-hr-secondary' }}">
                    All ({{ array_sum($statusCounts) }})
                </a>
                @foreach($statusCounts as $status => $count)
                    <a href="{{ route('hr-manager.applications.index', ['status' => $status]) }}"
                       class="btn btn-sm {{ request('status') === $status ? 'btn-hr-primary' : 'btn-hr-secondary' }}">
                        <span class="badge badge-hr badge-{{ str_replace('_', '-', $status) }} mr-1">{{ $count }}</span>
                        {{ trans('hr-manager::applications.status_' . $status) }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Applications Table --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-file-alt"></i> {{ trans('hr-manager::applications.all_applications') }}
            </h3>
            <div class="card-tools">
                <form method="GET" action="{{ route('hr-manager.applications.index') }}" class="form-inline">
                    @if(request('status'))
                        <input type="hidden" name="status" value="{{ request('status') }}">
                    @endif
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" class="form-control"
                               placeholder="Search..." value="{{ request('search') }}">
                        <div class="input-group-append">
                            <button class="btn btn-hr-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            @if($applications->isEmpty())
                <div class="p-4 text-center text-muted">
                    {{ trans('hr-manager::applications.no_applications') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:1%; white-space:nowrap;">#</th>
                                <th>{{ trans('hr-manager::applications.applicant') }}</th>
                                <th>{{ trans('hr-manager::applications.status') }}</th>
                                <th>{{ trans('hr-manager::applications.handlers_heading') }}</th>
                                <th>{{ trans('hr-manager::applications.submitted') }}</th>
                                <th>{{ trans('hr-manager::applications.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($applications as $app)
                                <tr>
                                    <td style="color: var(--hr-text-muted); white-space:nowrap;"><code>#{{ $app->id }}</code></td>
                                    <td>
                                        <img src="https://images.evetech.net/characters/{{ $app->character_id }}/portrait?size=32"
                                             class="rounded-circle mr-2" width="32" height="32">
                                        {{ $app->character->name ?? 'Unknown Character' }}
                                    </td>
                                    <td>
                                        <span class="badge badge-hr {{ $app->status_badge_class }}">
                                            {{ $app->status_label }}
                                        </span>
                                        @if($app->isStale($staleDays))
                                            <span class="badge ml-1" style="background: var(--hr-warning, #ffc107); color: #1a1a1a; font-weight: 600;"
                                                  title="{{ trans('hr-manager::applications.stale_badge_title', ['days' => $staleDays]) }}">
                                                <i class="fas fa-hourglass-half"></i> {{ trans('hr-manager::applications.stale_badge') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $handlers = $app->handlers;
                                            $viewerIsHandler = $handlers->contains('user_id', auth()->user()->id);
                                            $shown = $handlers->take(3);
                                            $overflow = $handlers->count() - $shown->count();
                                        @endphp
                                        @if($handlers->isEmpty())
                                            <span class="text-muted" style="font-size: 0.85rem;">&mdash;</span>
                                        @else
                                            <div class="d-flex align-items-center">
                                                @foreach($shown as $h)
                                                    @if($h->character_id)
                                                        <img src="https://images.evetech.net/characters/{{ $h->character_id }}/portrait?size=32"
                                                             class="rounded-circle" width="24" height="24"
                                                             style="margin-left: -6px; border: 2px solid var(--hr-dark-card);"
                                                             title="{{ $h->mainCharacter->name ?? ('User #' . $h->user_id) }}{{ $h->role_label ? ' · ' . $h->role_label : '' }}"
                                                             onerror="this.style.display='none'">
                                                    @else
                                                        <span class="rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 24px; height: 24px; margin-left: -6px; border: 2px solid var(--hr-dark-card); background: var(--hr-border); color: var(--hr-text-muted);"
                                                              title="{{ $h->mainCharacter->name ?? ('User #' . $h->user_id) }}">
                                                            <i class="fas fa-user fa-xs"></i>
                                                        </span>
                                                    @endif
                                                @endforeach
                                                @if($overflow > 0)
                                                    <span class="ml-2 text-muted" style="font-size: 0.85rem;">+{{ $overflow }}</span>
                                                @endif
                                                @if($viewerIsHandler)
                                                    <i class="fas fa-user-check ml-2" style="color: var(--hr-success);"
                                                       title="{{ trans('hr-manager::applications.you_are_a_handler') }}"></i>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td>@hrDate($app->submitted_at)</td>
                                    <td>
                                        <a href="{{ route('hr-manager.applications.show', $app->id) }}"
                                           class="btn btn-sm btn-hr-secondary btn-icon">
                                            <i class="fas fa-eye"></i> {{ trans('hr-manager::applications.view') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($applications->hasPages())
            <div class="card-footer">
                {{ $applications->appends(request()->query())->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

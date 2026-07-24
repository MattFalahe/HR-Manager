@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::landings.landings'))
@section('page_header', trans('hr-manager::landings.landings'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif

    {{-- Page-level warning: count of published landings with no usable
         template bound. Each such landing routes applicants to the
         "not accepting applications" error after they auth — silent
         dead-end. Banner only renders when there's at least one. --}}
    @if(($needsTemplateCount ?? 0) > 0)
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>{{ trans_choice('hr-manager::landings.warning_published_no_template', $needsTemplateCount, ['count' => $needsTemplateCount]) }}</strong>
                <p class="mb-0">{{ trans('hr-manager::landings.warning_published_no_template_body') }}</p>
            </div>
        </div>
    @endif

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-bullhorn"></i> {{ trans('hr-manager::landings.landings') }}</h3>
            <div class="card-tools">
                <a href="{{ route('hr-manager.landings.create') }}" class="btn btn-sm btn-hr-primary btn-icon">
                    <i class="fas fa-plus"></i> {{ trans('hr-manager::landings.create_landing') }}
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            @if($landings->isEmpty())
                <div class="p-4 text-center text-muted">{{ trans('hr-manager::landings.no_landings') }}</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::landings.title') }}</th>
                                <th>{{ trans('hr-manager::landings.corporation') }}</th>
                                <th>{{ trans('hr-manager::landings.public_url') }}</th>
                                <th>{{ trans('hr-manager::landings.views') }}</th>
                                <th>{{ trans('hr-manager::landings.applications') }}</th>
                                <th>{{ trans('hr-manager::landings.status') }}</th>
                                <th>{{ trans('hr-manager::applications.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($landings as $landing)
                                <tr>
                                    <td>
                                        <strong>{{ $landing->title }}</strong>
                                        @if($landing->headline)
                                            <br><small style="color: var(--hr-text-muted);">{{ Str::limit($landing->headline, 80) }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $corpNames[$landing->corporation_id] ?? '#' . $landing->corporation_id }}</td>
                                    <td>
                                        <code style="color: var(--hr-text-light);">/recruit/{{ $landing->corp_ticker }}/{{ $landing->slug }}</code>
                                        <a href="{{ $landing->public_url }}" target="_blank" class="ml-1" title="{{ trans('hr-manager::landings.open_public') }}">
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                    </td>
                                    <td>{{ number_format($landing->view_count) }}</td>
                                    <td>{{ number_format($landing->application_count) }}</td>
                                    <td>
                                        @if($landing->is_published)
                                            <span class="badge badge-hr badge-accepted">{{ trans('hr-manager::landings.status_published') }}</span>
                                        @else
                                            <span class="badge badge-hr badge-withdrawn">{{ trans('hr-manager::landings.status_draft') }}</span>
                                        @endif
                                        @if($landing->needs_template_binding ?? false)
                                            <i class="fas fa-exclamation-triangle ml-1"
                                               style="color: var(--hr-warning); cursor: help;"
                                               title="{{ trans('hr-manager::landings.no_template_bound') }}"></i>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('hr-manager.landings.edit', $landing->id) }}" class="btn btn-sm btn-hr-secondary btn-icon"><i class="fas fa-edit"></i></a>
                                        <a href="{{ route('hr-manager.landings.analytics', $landing->id) }}" class="btn btn-sm btn-hr-secondary btn-icon" title="{{ trans('hr-manager::landings.view_analytics') }}"><i class="fas fa-chart-line"></i></a>
                                        <form method="POST" action="{{ route('hr-manager.landings.toggle-publish', $landing->id) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-hr-secondary btn-icon">
                                                @if($landing->is_published)<i class="fas fa-eye-slash"></i>@else<i class="fas fa-eye"></i>@endif
                                            </button>
                                        </form>
                                        @can('hr-manager.admin')
                                            <form method="POST" action="{{ route('hr-manager.landings.destroy', $landing->id) }}" class="d-inline"
                                                  onsubmit="return confirm(@js(trans('hr-manager::landings.confirm_delete')))">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger btn-icon"><i class="fas fa-trash"></i></button>
                                            </form>
                                        @endcan
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
@endsection

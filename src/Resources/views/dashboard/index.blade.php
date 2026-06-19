@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::dashboard.dashboard'))
@section('page_header', trans('hr-manager::dashboard.dashboard'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    {{-- Stats Row --}}
    <div class="row">
        <div class="col-md-3">
            <div class="info-box bg-gradient-info">
                <span class="info-box-icon"><i class="fas fa-file-alt"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('hr-manager::dashboard.pending_applications') }}</span>
                    <span class="info-box-number">{{ $stats['pending'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-gradient-warning">
                <span class="info-box-icon"><i class="fas fa-search"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('hr-manager::dashboard.under_review') }}</span>
                    <span class="info-box-number">{{ $stats['under_review'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-gradient-primary">
                <span class="info-box-icon"><i class="fas fa-comments"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('hr-manager::dashboard.interviews') }}</span>
                    <span class="info-box-number">{{ $stats['interview'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-gradient-success">
                <span class="info-box-icon"><i class="fas fa-clipboard-list"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ trans('hr-manager::menu.templates') }}</span>
                    <span class="info-box-number">{{ $templateCount }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- Recent Applications --}}
        <div class="col-md-8">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-clock"></i> {{ trans('hr-manager::dashboard.recent_activity') }}
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('hr-manager.applications.index') }}" class="btn btn-sm btn-hr-primary btn-icon">
                            <i class="fas fa-list"></i> {{ trans('hr-manager::dashboard.view_all_applications') }}
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if($recentApplications->isEmpty())
                        <div class="p-4 text-center text-muted">
                            {{ trans('hr-manager::dashboard.no_recent_activity') }}
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ trans('hr-manager::applications.applicant') }}</th>
                                        <th>{{ trans('hr-manager::applications.status') }}</th>
                                        <th>{{ trans('hr-manager::applications.submitted') }}</th>
                                        <th>{{ trans('hr-manager::applications.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentApplications as $app)
                                        <tr>
                                            <td>
                                                <img src="https://images.evetech.net/characters/{{ $app->character_id }}/portrait?size=32"
                                                     class="rounded-circle mr-2" width="32" height="32">
                                                {{ $app->character->name ?? ('Character #' . $app->character_id) }}
                                            </td>
                                            <td>
                                                <span class="badge badge-hr {{ $app->status_badge_class }}">
                                                    {{ $app->status_label }}
                                                </span>
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
            </div>
        </div>

        {{-- Quick Actions --}}
        <div class="col-md-4">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-bolt"></i> {{ trans('hr-manager::dashboard.quick_actions') }}
                    </h3>
                </div>
                <div class="card-body">
                    <a href="{{ route('hr-manager.applications.index') }}" class="btn btn-hr-secondary btn-block btn-icon mb-2">
                        <i class="fas fa-file-alt"></i> {{ trans('hr-manager::dashboard.view_all_applications') }}
                    </a>
                    @can('hr-manager.director')
                        <a href="{{ route('hr-manager.members.index') }}" class="btn btn-hr-secondary btn-block btn-icon mb-2">
                            <i class="fas fa-users"></i> {{ trans('hr-manager::dashboard.view_members') }}
                        </a>
                        <a href="{{ route('hr-manager.templates.create') }}" class="btn btn-hr-primary btn-block btn-icon mb-2">
                            <i class="fas fa-plus"></i> {{ trans('hr-manager::dashboard.create_template') }}
                        </a>
                    @endcan
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

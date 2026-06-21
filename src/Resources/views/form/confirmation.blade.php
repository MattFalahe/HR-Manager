@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::applications.apply_confirmation'))
@section('page_header', trans('hr-manager::applications.apply_confirmation'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card card-dark">
                <div class="card-body text-center p-5">
                    <i class="fas fa-check-circle fa-4x mb-3" style="color: var(--hr-success);"></i>
                    <h3 style="color: var(--hr-text-white);">{{ trans('hr-manager::applications.apply_confirmation') }}</h3>
                    <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::applications.apply_confirmation_text') }}</p>
                    <p style="color: var(--hr-text-muted);">
                        Application #{{ $application->id }} -
                        <span class="badge badge-hr badge-applied">{{ trans('hr-manager::applications.status_applied') }}</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

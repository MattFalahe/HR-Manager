@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::recruit.no_character_heading'))
@section('page_header', trans('hr-manager::recruit.no_character_heading'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">
    <div class="card card-dark">
        <div class="card-body text-center p-5">
            <i class="fas fa-user-plus" style="font-size: 3rem; color: var(--hr-warning);"></i>
            <h3 class="mt-3" style="color: var(--hr-text-white);">{{ trans('hr-manager::recruit.no_character_heading') }}</h3>
            <p style="color: var(--hr-text-light);">{{ trans('hr-manager::recruit.no_character_body') }}</p>
        </div>
    </div>
</div>
@endsection

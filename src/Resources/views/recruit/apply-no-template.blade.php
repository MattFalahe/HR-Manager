@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::recruit.no_template_heading'))
@section('page_header', trans('hr-manager::recruit.no_template_heading'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">
    <div class="card card-dark">
        <div class="card-body text-center p-5">
            <i class="fas fa-tools" style="font-size: 3rem; color: var(--hr-warning);"></i>
            <h3 class="mt-3" style="color: var(--hr-text-white);">{{ trans('hr-manager::recruit.no_template_heading') }}</h3>
            <p style="color: var(--hr-text-light);">{{ trans('hr-manager::recruit.no_template_body') }}</p>
            <a href="{{ route('hr-manager.recruit.show', ['ticker' => $landing->corp_ticker, 'slug' => $landing->slug]) }}" class="btn btn-hr-secondary mt-3">
                {{ trans('hr-manager::recruit.back_to_landing') }}
            </a>

            {{-- Director-only inline shortcut. The public messaging above
                 stays generic ("try again later") for applicants; this
                 block adds a fix-it link visible ONLY to users with
                 director permission on this install, so an admin who
                 lands on their own broken page has a one-click path to
                 the landing edit screen. --}}
            @can('hr-manager.director')
            <div class="warning-box text-left mt-4" style="max-width: 720px; margin-left: auto; margin-right: auto;">
                <i class="fas fa-user-shield"></i>
                <div>
                    <strong>{{ trans('hr-manager::recruit.director_hint_title') }}</strong>
                    <p class="mb-2">{{ trans('hr-manager::recruit.director_hint_body') }}</p>
                    <a href="{{ route('hr-manager.landings.edit', $landing->id) }}" class="btn btn-hr-primary btn-sm">
                        <i class="fas fa-edit"></i> {{ trans('hr-manager::recruit.bind_template_btn') }}
                    </a>
                    <a href="{{ route('hr-manager.templates.create') }}" class="btn btn-hr-secondary btn-sm">
                        <i class="fas fa-plus"></i> {{ trans('hr-manager::recruit.create_template_btn') }}
                    </a>
                </div>
            </div>
            @endcan
        </div>
    </div>
</div>
@endsection

@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::recruit.submitted_heading'))
@section('page_header', trans('hr-manager::recruit.submitted_heading'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    <div class="card card-dark mb-3">
        <div class="card-body text-center p-5">
            <div style="font-size: 4rem; color: var(--hr-success, #28a745); margin-bottom: 1rem;">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2 style="color: var(--hr-text-white);">{{ trans('hr-manager::recruit.submitted_heading') }}</h2>
            <p style="color: var(--hr-text-light);">{{ trans('hr-manager::recruit.submitted_body') }}</p>
            <p style="color: var(--hr-text-muted);">
                {{ trans('hr-manager::recruit.application_id') }}: <code>#{{ $application->id }}</code>
            </p>

            @if($application->tracking_token)
                <div class="info-box mt-3 text-left" style="max-width: 640px; margin-left: auto; margin-right: auto;">
                    <i class="fas fa-link"></i>
                    <div style="flex-grow: 1;">
                        <strong>{{ trans('hr-manager::recruit.track_your_application') }}</strong>
                        <p class="mb-2">{{ trans('hr-manager::recruit.track_your_application_help') }}</p>
                        <input type="text"
                               id="trackingUrlInput"
                               class="form-control form-control-sm mb-2"
                               value="{{ route('hr-manager.recruit.track', ['token' => $application->tracking_token]) }}"
                               readonly
                               onclick="this.select();">
                        <a href="{{ route('hr-manager.recruit.track', ['token' => $application->tracking_token]) }}"
                           target="_blank" rel="noopener" class="btn btn-hr-primary btn-sm btn-icon">
                            <i class="fas fa-external-link-alt"></i> {{ trans('hr-manager::recruit.open_tracking_page') }}
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @php
        // Pre-compute whether the Next steps card has anything to show
        // so we can skip rendering the whole card (header + empty body)
        // when neither the mode-specific CTA nor the director notes are
        // populated. Operators who haven't configured anything yet get
        // a friendly fallback message instead of a confusing empty box.
        $hasModeCta = match ($landing->post_submission_mode) {
            'discord_invite' => !empty($landing->discord_invite_url),
            'seat_connector' => !empty($connectorAvailable) && !empty($seatConnectorUrl),
            'custom'         => !empty($landing->custom_confirmation_markdown),
            default          => false, // 'none' (and anything unknown) shows no CTA
        };
        $hasNotes = !empty($landing->next_steps_markdown);
    @endphp

    <div class="card card-dark mb-3">
        <div class="card-header">
            <h3 class="card-title">{{ trans('hr-manager::recruit.next_steps') }}</h3>
        </div>
        <div class="card-body">
            {{-- Always-visible director notes. Renders independently of
                 the mode-specific CTA below, so a director can pair the
                 Discord button with their own "what happens now" copy. --}}
            @if($hasNotes)
                <div class="next-steps-notes mb-3">
                    {!! \Illuminate\Support\Str::markdown($landing->next_steps_markdown) !!}
                </div>
            @endif

            {{-- Mode-specific post-submission CTA --}}
            @switch($landing->post_submission_mode)
                @case('discord_invite')
                    @if($landing->discord_invite_url)
                        <p>{{ trans('hr-manager::recruit.join_discord_invite') }}</p>
                        <a href="{{ $landing->discord_invite_url }}" target="_blank" rel="noopener" class="btn btn-hr-primary btn-lg btn-icon">
                            <i class="fab fa-discord"></i> {{ trans('hr-manager::recruit.open_discord') }}
                        </a>
                    @endif
                    @break
                @case('seat_connector')
                    @if($connectorAvailable && $seatConnectorUrl)
                        <p>{{ trans('hr-manager::recruit.connect_discord_seat') }}</p>
                        <a href="{{ $seatConnectorUrl }}" target="_blank" rel="noopener" class="btn btn-hr-primary btn-lg btn-icon">
                            <i class="fas fa-link"></i> {{ trans('hr-manager::recruit.open_seat_identities') }}
                        </a>
                    @endif
                    @break
                @case('none')
                    {{-- Operator deliberately runs no Discord onboarding step. --}}
                    @break
                @case('custom')
                    @if($landing->custom_confirmation_markdown)
                        {!! \Illuminate\Support\Str::markdown($landing->custom_confirmation_markdown) !!}
                    @endif
                    @break
            @endswitch

            {{-- Fallback when the director hasn't configured anything.
                 Better than an empty card under the "Next steps" header. --}}
            @if(!$hasNotes && !$hasModeCta)
                <p style="color: var(--hr-text-muted); margin-bottom: 0;">
                    <i class="fas fa-clock"></i>
                    {{ trans('hr-manager::recruit.next_steps_fallback') }}
                </p>
            @endif
        </div>
    </div>

</div>
@endsection

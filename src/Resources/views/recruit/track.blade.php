@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::recruit.track_title'))
@section('page_header', trans('hr-manager::recruit.track_title'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.1">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    {{-- Header: character portrait + name + applied-to + status badge --}}
    <div class="card card-dark mb-3">
        <div class="card-body">
            <div class="character-header">
                <img src="https://images.evetech.net/characters/{{ $application->character_id }}/portrait?size=128"
                     class="character-avatar" alt="Portrait">
                <div>
                    <div class="character-name">{{ $application->character->name ?? ('Character #' . $application->character_id) }}</div>
                    <div class="character-corp">
                        @if($corporationTicker)<span style="color: var(--hr-text-muted);">[{{ $corporationTicker }}]</span>@endif
                        {{ $corporationName ?? ('Corporation #' . $application->corporation_id) }}
                    </div>
                    <div class="mt-2">
                        <span class="badge badge-hr {{ $application->status_badge_class }}">
                            {{ $application->status_label }}
                        </span>
                        <span class="ml-2" style="color: var(--hr-text-muted); font-size: 0.9rem;">
                            {{ trans('hr-manager::recruit.track_submitted_label') }}: @hrDate($application->submitted_at)
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Outcome panel — only meaningful once accepted --}}
    @if($application->status === 'accepted')
        @php $outcome = $application->join_outcome; @endphp
        @if($outcome === 'joined')
            <div class="success-box mb-3">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>{{ trans('hr-manager::recruit.track_joined_heading') }}</strong>
                    <p class="mb-0">
                        {{ trans('hr-manager::recruit.track_joined_body', ['date' => $application->joined_corp_at->format('M d, Y')]) }}
                    </p>
                </div>
            </div>
        @elseif($outcome === 'pending')
            <div class="info-box mb-3">
                <i class="fas fa-clock"></i>
                <div>
                    <strong>{{ trans('hr-manager::recruit.track_accepted_heading') }}</strong>
                    <p class="mb-0">{{ trans('hr-manager::recruit.track_accepted_body') }}</p>
                </div>
            </div>
        @elseif($outcome === 'late' || $outcome === 'ghosted')
            <div class="warning-box mb-3">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>{{ trans('hr-manager::recruit.track_not_joined_heading') }}</strong>
                    <p class="mb-0">{{ trans('hr-manager::recruit.track_not_joined_body') }}</p>
                </div>
            </div>
        @endif
    @endif

    {{-- Status Timeline (transitions + timestamps only — no comments) --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-history"></i> {{ trans('hr-manager::recruit.track_timeline_heading') }}
            </h3>
        </div>
        <div class="card-body">
            @forelse($application->statusHistory as $entry)
                <div class="d-flex align-items-center mb-2" style="gap: 12px;">
                    <span class="badge badge-hr {{ \HrManager\Models\Application::badgeForStatus($entry->new_status ?? 'applied') }}">
                        {{ trans('hr-manager::applications.status_' . ($entry->new_status ?? 'applied')) }}
                    </span>
                    <span style="color: var(--hr-text-muted); font-size: 0.9rem;">@hrDate($entry->created_at)</span>
                </div>
            @empty
                <p class="text-muted mb-0">{{ trans('hr-manager::recruit.track_no_history') }}</p>
            @endforelse
        </div>
    </div>

    {{-- Applicant's own answers — so they can verify what they sent --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-clipboard-check"></i> {{ trans('hr-manager::recruit.track_answers_heading') }}
            </h3>
        </div>
        <div class="card-body">
            @forelse($application->answers as $answer)
                <div class="mb-3">
                    <label class="d-block" style="color: var(--hr-text-white); font-weight: 600;">
                        {{ $answer->question_text }}
                    </label>
                    <div style="color: var(--hr-text-light); background: var(--hr-dark-card); padding: 10px; border-radius: 5px; border: 1px solid var(--hr-border); word-break: break-word;">
                        {!! $answer->renderedHtml() !!}
                    </div>
                </div>
            @empty
                <p class="text-muted mb-0">{{ trans('hr-manager::recruit.track_no_answers') }}</p>
            @endforelse
        </div>
    </div>

    {{-- Footer credit (same as the public landing) --}}
    <footer class="footer text-center" style="color: var(--hr-text-muted); font-size: 0.85rem; padding: 1rem 0;">
        {!! trans('hr-manager::recruit.footer_credit') !!}
    </footer>

</div>
@endsection

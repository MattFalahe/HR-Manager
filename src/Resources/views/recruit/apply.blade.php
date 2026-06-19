@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::recruit.apply_title') . ': ' . $landing->title)
@section('page_header', trans('hr-manager::recruit.apply_title') . ': ' . $landing->title)

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif

    {{-- Header --}}
    <div class="card card-dark mb-3">
        <div class="card-body">
            <p style="color: var(--hr-text-muted); margin-bottom: 4px;">
                {{ trans('hr-manager::recruit.applying_to') }}
                <strong style="color: var(--hr-text-white);">{{ $landing->corp_name ?? '#' . $landing->corporation_id }}</strong>
                @if($landing->corp_ticker)<code>[{{ $landing->corp_ticker }}]</code>@endif
            </p>
            <p style="color: var(--hr-text-muted); margin: 0;">
                {{ trans('hr-manager::recruit.as_character') }}
                <strong style="color: var(--hr-text-white);">{{ auth()->user()->name ?? '#' . auth()->user()->id }}</strong>
            </p>
        </div>
    </div>

    {{-- Linked characters — what recruiters will see + an option to link
         more alts for a complete assessment. Purely optional; the
         applicant can submit with just their main. --}}
    <div class="card card-dark mb-3">
        <div class="card-body">
            <h5 style="color: var(--hr-text-white); margin-bottom: 6px;">
                <i class="fas fa-users"></i> {{ trans('hr-manager::recruit.linked_chars_heading') }}
            </h5>
            <p style="color: var(--hr-text-muted); margin-bottom: 10px;">
                {{ trans('hr-manager::recruit.linked_chars_body') }}
            </p>
            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                @foreach($linkedCharacters as $lc)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 5px 10px; background: rgba(255,255,255,0.04); border-radius: 4px;">
                        <img src="https://images.evetech.net/characters/{{ $lc['character_id'] }}/portrait?size=32"
                             style="width: 28px; height: 28px; border-radius: 50%;" alt="">
                        <span style="color: var(--hr-text-white);">{{ $lc['name'] }}</span>
                        @if($lc['is_main'])
                            <span class="badge badge-primary">{{ trans('hr-manager::recruit.linked_chars_main') }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
            <a href="{{ route('hr-manager.recruit.link-character', ['ticker' => $landing->corp_ticker, 'slug' => $landing->slug]) }}"
               class="btn btn-hr-secondary btn-icon">
                <i class="fas fa-plus"></i> {{ trans('hr-manager::recruit.linked_chars_add_btn') }}
            </a>
            @if(!empty($connectorAvailable) && !empty($connectorLinkUrl))
                <a href="{{ $connectorLinkUrl }}" target="_blank" rel="noopener" class="btn btn-hr-secondary btn-icon">
                    <i class="fab fa-discord"></i> {{ trans('hr-manager::recruit.link_discord_now_btn') }}
                </a>
            @endif
            <div class="mt-2">
                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::recruit.linked_chars_help') }}</small>
                @if(!empty($connectorAvailable) && !empty($connectorLinkUrl))
                    <small class="d-block" style="color: var(--hr-text-muted);">{{ trans('hr-manager::recruit.link_discord_now_help') }}</small>
                @endif
            </div>
        </div>
    </div>

    @if($hasPending)
        <div class="alert alert-warning">
            <i class="fas fa-info-circle"></i> {{ trans('hr-manager::recruit.already_pending') }}
        </div>
    @endif

    {{-- Eligibility result --}}
    @if($eligibilityResult['passed'])
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> {{ trans('hr-manager::recruit.eligibility_passed') }}
        </div>
    @elseif(!empty($hasDataMissingFailure) || !empty($manualReview))
        {{-- Manual review path: SeAT data didn't finish loading in
             time so we couldn't fully evaluate. Different copy + auto-
             ticked override so the applicant isn't accused of failing
             rules we never actually got to check. --}}
        <div class="alert alert-info">
            <h5><i class="fas fa-mug-hot"></i> {{ trans('hr-manager::recruit.manual_review_heading') }}</h5>
            <p class="mb-2">{{ trans('hr-manager::recruit.manual_review_body') }}</p>
            <ul class="mb-2">
                @foreach($eligibilityResult['failures'] as $failure)
                    <li>
                        @if(!empty($failure['data_missing']))
                            <span class="badge badge-warning" style="margin-right: 4px;">
                                <i class="fas fa-hourglass-half"></i> {{ trans('hr-manager::recruit.manual_review_data_missing_chip') }}
                            </span>
                        @endif
                        {{ $failure['reason'] }}
                    </li>
                @endforeach
            </ul>
            <small>{{ trans('hr-manager::recruit.manual_review_subtext') }}</small>
        </div>
    @else
        @php
            // Surface a "Link Discord" CTA when the eligibility failure
            // includes require_seat_connector. Two variants:
            //  - Connector installed → link to its identity page so the
            //    applicant can connect their Discord and come back.
            //  - Connector not installed but landing has a Discord
            //    invite → link to the invite so they at least land in
            //    the server (recruiter can finish the link manually).
            $connectorFailure = false;
            foreach ($eligibilityResult['failures'] as $f) {
                if (($f['rule'] ?? null) === 'require_seat_connector') {
                    $connectorFailure = true;
                    break;
                }
            }
        @endphp
        <div class="alert alert-warning">
            <h5><i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::recruit.eligibility_failed_heading') }}</h5>
            <ul class="mb-2">
                @foreach($eligibilityResult['failures'] as $failure)
                    <li>{{ $failure['reason'] }}</li>
                @endforeach
            </ul>

            @if($connectorFailure && !empty($connectorLinkUrl))
                <div class="mt-3">
                    <a href="{{ $connectorLinkUrl }}" target="_blank" rel="noopener noreferrer"
                       class="btn btn-primary btn-icon">
                        <i class="fab fa-discord"></i>
                        {{ trans('hr-manager::recruit.link_discord_via_connector') }}
                        <i class="fas fa-external-link-alt fa-xs ml-1"></i>
                    </a>
                    <div class="mt-2">
                        <small>{{ trans('hr-manager::recruit.link_discord_help') }}</small>
                    </div>
                </div>
            @elseif($connectorFailure && empty($connectorLinkUrl) && !empty($landing->discord_invite_url))
                <div class="mt-3">
                    <a href="{{ $landing->discord_invite_url }}" target="_blank" rel="noopener noreferrer"
                       class="btn btn-primary btn-icon">
                        <i class="fab fa-discord"></i>
                        {{ trans('hr-manager::recruit.join_discord_invite') }}
                        <i class="fas fa-external-link-alt fa-xs ml-1"></i>
                    </a>
                    <div class="mt-2">
                        <small>{{ trans('hr-manager::recruit.join_discord_invite_help') }}</small>
                    </div>
                </div>
            @endif

            <small class="d-block mt-2">{{ trans('hr-manager::recruit.eligibility_failed_subtext') }}</small>
        </div>
    @endif

    {{-- Form --}}
    @if(!$hasPending)
        <form method="POST" action="{{ route('hr-manager.recruit.apply.submit', ['ticker' => $landing->corp_ticker, 'slug' => $landing->slug]) }}">
            @csrf
            <input type="hidden" name="template_id" value="{{ $template->id }}">

            @foreach($template->questions as $q)
                <div class="card card-dark mb-2">
                    <div class="card-body">
                        <label style="color: var(--hr-text-white); font-weight: 600;">
                            {{ $q->question_text }} @if($q->is_required) <span class="text-danger">*</span> @endif
                        </label>
                        @if($q->help_text)<br><small style="color: var(--hr-text-muted);">{{ $q->help_text }}</small>@endif

                        @switch($q->question_type)
                            @case('textarea')
                                <textarea name="answers[{{ $q->id }}]" class="form-control mt-2" rows="4" @if($q->is_required) required @endif placeholder="{{ $q->placeholder }}"></textarea>
                                @break
                            @case('select')
                                <select name="answers[{{ $q->id }}]" class="form-control mt-2" @if($q->is_required) required @endif>
                                    <option value="">-- Select --</option>
                                    @foreach((array) $q->options as $opt)
                                        <option value="{{ $opt }}">{{ $opt }}</option>
                                    @endforeach
                                </select>
                                @break
                            @case('checkbox')
                                @foreach((array) $q->options as $opt)
                                    <div class="form-check">
                                        <input type="checkbox" name="answers[{{ $q->id }}][]" value="{{ $opt }}" class="form-check-input" id="q{{ $q->id }}_{{ md5($opt) }}">
                                        <label class="form-check-label" for="q{{ $q->id }}_{{ md5($opt) }}">{{ $opt }}</label>
                                    </div>
                                @endforeach
                                @break
                            @case('radio')
                                @foreach((array) $q->options as $opt)
                                    <div class="form-check">
                                        <input type="radio" name="answers[{{ $q->id }}]" value="{{ $opt }}" class="form-check-input" id="q{{ $q->id }}_{{ md5($opt) }}" @if($q->is_required) required @endif>
                                        <label class="form-check-label" for="q{{ $q->id }}_{{ md5($opt) }}">{{ $opt }}</label>
                                    </div>
                                @endforeach
                                @break
                            @case('number')
                                <input type="number" name="answers[{{ $q->id }}]" class="form-control mt-2" @if($q->is_required) required @endif placeholder="{{ $q->placeholder }}">
                                @break
                            @case('url')
                                <input type="url" name="answers[{{ $q->id }}]" class="form-control mt-2" @if($q->is_required) required @endif placeholder="{{ $q->placeholder }}">
                                @break
                            @default
                                <input type="text" name="answers[{{ $q->id }}]" class="form-control mt-2" @if($q->is_required) required @endif placeholder="{{ $q->placeholder }}">
                        @endswitch
                    </div>
                </div>
            @endforeach

            @if(!$eligibilityResult['passed'])
                <div class="card card-dark mb-2">
                    <div class="card-body">
                        <div class="form-check">
                            {{-- Auto-tick when we're on the manual-review path
                                 (data-missing failures or ?manual_review=1) so
                                 the applicant doesn't have to acknowledge a
                                 rule they couldn't actually have been checked
                                 against. They can still untick if they want
                                 to bail. --}}
                            @php $autoCheckOverride = !empty($hasDataMissingFailure) || !empty($manualReview); @endphp
                            <input type="checkbox" name="eligibility_override" value="1" class="form-check-input" id="eligibilityOverride" required
                                   {{ $autoCheckOverride ? 'checked' : '' }}>
                            <label class="form-check-label" for="eligibilityOverride">
                                @if($autoCheckOverride)
                                    {{ trans('hr-manager::recruit.eligibility_override_manual_review_label') }}
                                @else
                                    {{ trans('hr-manager::recruit.eligibility_override_label') }}
                                @endif
                            </label>
                        </div>
                    </div>
                </div>
            @endif

            <button type="submit" class="btn btn-hr-primary btn-lg btn-icon">
                <i class="fas fa-paper-plane"></i> {{ trans('hr-manager::recruit.submit_application') }}
            </button>
            <a href="{{ route('hr-manager.recruit.show', ['ticker' => $landing->corp_ticker, 'slug' => $landing->slug]) }}" class="btn btn-hr-secondary btn-lg">
                {{ trans('hr-manager::recruit.back_to_landing') }}
            </a>
        </form>
    @endif

</div>
@endsection

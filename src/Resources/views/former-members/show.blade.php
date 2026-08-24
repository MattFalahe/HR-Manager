@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::former.record_for', ['name' => $displayName]))
@section('page_header', trans('hr-manager::former.record_for', ['name' => $displayName]))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.8">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    <div class="mb-3">
        <a href="{{ route('hr-manager.former-members.index') }}" class="btn btn-sm btn-hr-secondary btn-icon">
            <i class="fas fa-arrow-left"></i> {{ trans('hr-manager::former.back_to_index') }}
        </a>
    </div>

    @php $anyReconstructed = $stints->contains(fn ($s) => $s->isReconstructed()); @endphp

    @if($anyReconstructed)
        <div class="alert" style="background: rgba(255,193,7,0.08); border: 1px solid rgba(255,193,7,0.35); color: var(--hr-text-light);">
            <i class="fas fa-exclamation-triangle text-warning"></i>
            <strong>{{ trans('hr-manager::former.reconstructed_heading') }}</strong>
            <p class="mb-0 mt-1" style="color: var(--hr-text-muted); font-size: 0.9rem;">{{ trans('hr-manager::former.reconstructed_body') }}</p>
        </div>
    @endif

    {{-- Frozen figures, one card per stint. --}}
    @foreach($stints as $stint)
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title mb-0">
                    <i class="fas fa-id-badge"></i>
                    {{ $names[$stint->character_id] ?? ('Character #' . $stint->character_id) }}
                    <small style="color: var(--hr-text-muted);">
                        {{ trans('hr-manager::former.in_corp') }}
                        {{ $corpNames[$stint->corporation_id] ?? ('#' . $stint->corporation_id) }}
                    </small>
                    @if($stint->isReconstructed())
                        <span class="badge ml-2" style="background: rgba(255,193,7,0.2); color: #ffe08a; font-size: 0.62rem;">
                            {{ trans('hr-manager::former.badge_reconstructed') }}
                        </span>
                    @endif
                </h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <dl class="row mb-0">
                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.joined') }}</dt>
                            <dd class="col-6">@hrDateShort($stint->joined_at)</dd>

                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.left') }}</dt>
                            <dd class="col-6">@hrDateShort($stint->left_at)</dd>

                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.tenure') }}</dt>
                            <dd class="col-6">
                                @if($stint->days_in_corp !== null)
                                    {{ number_format($stint->days_in_corp) }} {{ trans('hr-manager::former.days') }}
                                @else
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::former.unknown') }}</small>
                                @endif
                            </dd>

                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.went_to') }}</dt>
                            <dd class="col-6">
                                @if($stint->destination_corporation_id)
                                    {{ $stint->destination_corporation_name ?: ($corpNames[$stint->destination_corporation_id] ?? ('#' . $stint->destination_corporation_id)) }}
                                @else
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::former.unknown') }}</small>
                                @endif
                            </dd>

                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.how_they_left') }}</dt>
                            <dd class="col-6">{{ trans('hr-manager::former.departure_' . $stint->departure_type) }}</dd>
                        </dl>
                    </div>
                    <div class="col-md-6">
                        <dl class="row mb-0">
                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.tier_at_departure') }}</dt>
                            <dd class="col-6">
                                {{ $stint->tier_at_departure !== null ? $stint->tier_at_departure : trans('hr-manager::former.unknown') }}
                            </dd>

                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.class_at_departure') }}</dt>
                            <dd class="col-6">
                                {{ $stint->classification_at_departure ?: trans('hr-manager::former.unknown') }}
                            </dd>

                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.wallet_contributed') }}</dt>
                            <dd class="col-6">
                                @if($stint->wallet_contributed !== null)
                                    {{ number_format((float) $stint->wallet_contributed) }} ISK
                                @else
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::former.unknown') }}</small>
                                @endif
                            </dd>

                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.mining_contributed') }}</dt>
                            <dd class="col-6">
                                @if($stint->mining_contributed !== null)
                                    {{ number_format((float) $stint->mining_contributed) }}
                                @else
                                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::former.unknown') }}</small>
                                @endif
                            </dd>

                            <dt class="col-6" style="color: var(--hr-text-muted); font-weight: 500;">{{ trans('hr-manager::former.token_state') }}</dt>
                            <dd class="col-6">
                                @if($stint->token_valid_at_departure)
                                    <span style="color: #6ee7b7;">{{ trans('hr-manager::former.token_live') }}</span>
                                @else
                                    <span style="color: #ffc08a;" title="{{ trans('hr-manager::former.token_lost_help') }}">
                                        {{ trans('hr-manager::former.token_lost') }}
                                    </span>
                                @endif
                            </dd>
                        </dl>
                    </div>
                </div>

                @unless($stint->token_valid_at_departure)
                    <small class="d-block mt-3" style="color: var(--hr-text-muted);">
                        <i class="fas fa-info-circle"></i> {{ trans('hr-manager::former.token_lost_help') }}
                    </small>
                @endunless
            </div>
        </div>
    @endforeach

    {{-- Notes and intel are read live from tables that were never at risk, so
         this page links to the real records rather than keeping copies. --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h3 class="card-title mb-0">
                <i class="fas fa-sticky-note"></i> {{ trans('hr-manager::former.notes_heading') }}
                <span class="badge badge-hr badge-applied ml-2">{{ $notes->count() }}</span>
            </h3>
        </div>
        <div class="card-body">
            @forelse($notes as $note)
                <div class="mb-3">
                    <div style="color: var(--hr-text-light); white-space: pre-wrap; line-height: 1.55;">{{ $note->content }}</div>
                    <small style="color: var(--hr-text-muted);">
                        {{ trans('hr-manager::intel.added_by') }}
                        <strong>{{ $noteAuthorNames[$note->author_id] ?? ('User #' . $note->author_id) }}</strong>
                        @hrDate($note->created_at)
                        @if($note->is_private)
                            <span class="badge ml-1" style="background: rgba(255,255,255,0.06); color: var(--hr-text-muted); font-size: 0.62rem;">{{ trans('hr-manager::notes.private') }}</span>
                        @endif
                    </small>
                </div>
                @if(!$loop->last)<hr style="border-color: rgba(255,255,255,0.06);">@endif
            @empty
                <p class="mb-0" style="color: var(--hr-text-muted);">
                    @if($userId)
                        {{ trans('hr-manager::former.no_notes') }}
                    @else
                        {{ trans('hr-manager::former.no_notes_unregistered') }}
                    @endif
                </p>
            @endforelse
        </div>
    </div>

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title mb-0">
                <i class="fas fa-user-secret"></i> {{ trans('hr-manager::former.intel_heading') }}
                <span class="badge badge-hr badge-applied ml-2">{{ $intelNotes->count() }}</span>
            </h3>
        </div>
        <div class="card-body">
            @forelse($intelNotes as $inote)
                <div class="mb-3">
                    <div class="mb-1">
                        <a href="{{ route('hr-manager.intel.show', $inote->character_id) }}" style="color: var(--hr-text-white);">
                            <strong>{{ $names[$inote->character_id] ?? ('Character #' . $inote->character_id) }}</strong>
                        </a>
                        @foreach(($inote->tags ?? []) as $itag)
                            <span class="badge ml-1" style="background: rgba(255,255,255,0.05); color: var(--hr-text-light); border: 1px solid var(--hr-border); font-size: 0.62rem;">{{ $itag }}</span>
                        @endforeach
                    </div>
                    <div style="color: var(--hr-text-light); white-space: pre-wrap; line-height: 1.55;">{{ $inote->body }}</div>
                    <small style="color: var(--hr-text-muted);">
                        {{ trans('hr-manager::intel.added_by') }}
                        <strong>{{ $noteAuthorNames[$inote->author_id] ?? ('User #' . $inote->author_id) }}</strong>
                        @hrDate($inote->created_at)
                    </small>
                </div>
                @if(!$loop->last)<hr style="border-color: rgba(255,255,255,0.06);">@endif
            @empty
                <p class="mb-0" style="color: var(--hr-text-muted);">{{ trans('hr-manager::former.no_intel') }}</p>
            @endforelse
        </div>
    </div>
</div>
@endsection

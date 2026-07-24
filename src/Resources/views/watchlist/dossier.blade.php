@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::watchlist.dossier_title', ['name' => $displayName]))
@section('page_header', trans('hr-manager::watchlist.dossier_title', ['name' => $displayName]))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.8">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    {{-- Character header --}}
    <div class="card card-dark mb-3">
        <div class="card-body">
            <div class="character-header">
                <img src="https://images.evetech.net/characters/{{ $characterId }}/portrait?size=128" class="character-avatar" alt="Portrait">
                <div style="flex: 1;">
                    <div class="character-name">
                        {{ $displayName }}
                        <small class="ml-2" style="color: var(--hr-text-muted); font-size: 0.9rem;">#{{ $characterId }}</small>
                    </div>
                    <div class="mt-2">
                        <a href="{{ route('hr-manager.watchlist.index') }}" class="btn btn-sm btn-hr-secondary btn-icon">
                            <i class="fas fa-arrow-left"></i> {{ trans('hr-manager::watchlist.dossier_back') }}
                        </a>
                        <a href="https://zkillboard.com/character/{{ $characterId }}/" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary btn-icon">
                            <i class="fas fa-crosshairs"></i> zKillboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Watchlist entries — full, untruncated reasons, active + cleared --}}
    <div class="card card-dark mb-3">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-list-ul"></i> {{ trans('hr-manager::watchlist.dossier_watchlist_section') }}
                <span class="badge badge-hr badge-applied ml-2">{{ $entries->count() }}</span>
            </h3>
        </div>
        <div class="card-body">
            @forelse($entries as $entry)
                @php
                    $isBlack   = $entry->list_type === 'blacklist';
                    $isCleared = $entry->status === 'cleared';
                    $sevClass  = ['low' => 'badge-secondary', 'medium' => 'badge-warning', 'high' => 'badge-danger'][$entry->severity] ?? 'badge-secondary';
                @endphp
                <div class="mb-3" style="padding: 12px 14px; border-radius: 6px; background: rgba(255,255,255,0.03); border-left: 3px solid {{ $isCleared ? 'var(--hr-text-muted, #8b95a5)' : ($isBlack ? 'var(--hr-danger, #dc3545)' : 'var(--hr-success, #28a745)') }};{{ $isCleared ? ' opacity: 0.72;' : '' }}">
                    <div class="d-flex align-items-center mb-2" style="gap: 6px;">
                        <img src="https://images.evetech.net/characters/{{ $entry->character_id }}/portrait?size=32" style="width:22px;height:22px;border-radius:50%;" alt="">
                        <strong style="color: var(--hr-text-white, #fff);">{{ $charNames[$entry->character_id] ?? ($entry->character_name ?: ('Character #' . $entry->character_id)) }}</strong>
                        <small class="text-muted">#{{ $entry->character_id }}</small>
                    </div>
                    <div class="d-flex justify-content-between align-items-start mb-1" style="flex-wrap: wrap; gap: 6px;">
                        <div>
                            <span class="badge {{ $isBlack ? 'badge-danger' : 'badge-success' }} mr-1">
                                <i class="fas {{ $isBlack ? 'fa-ban' : 'fa-check-circle' }}"></i> {{ $isBlack ? trans('hr-manager::watchlist.blacklist') : trans('hr-manager::watchlist.whitelist') }}
                            </span>
                            @if($isBlack)
                                <span class="badge {{ $sevClass }} mr-1">@if($entry->severity === 'high')<i class="fas fa-exclamation-triangle"></i> @endif{{ strtoupper($entry->severity) }}</span>
                            @endif
                            @if($isCleared)
                                <span class="badge badge-secondary mr-1"><i class="fas fa-eraser"></i> {{ trans('hr-manager::watchlist.dossier_status_cleared') }}</span>
                            @else
                                <span class="badge badge-success mr-1">{{ trans('hr-manager::watchlist.dossier_status_active') }}</span>
                            @endif
                            @if($entry->scope_corporation_id)
                                <span class="badge badge-secondary mr-1">{{ $corpNames[$entry->scope_corporation_id] ?? ('Corp #' . $entry->scope_corporation_id) }}</span>
                            @elseif($entry->scope_alliance_id)
                                <span class="badge badge-secondary mr-1"><i class="fas fa-users"></i> {{ trans('hr-manager::watchlist.scope_alliance') }}</span>
                            @else
                                <span class="badge mr-1" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.4);"><i class="fas fa-globe"></i> {{ trans('hr-manager::watchlist.scope_global') }}</span>
                            @endif
                        </div>
                    </div>
                    @if($entry->reason)
                        <div style="color: var(--hr-text-light); white-space: pre-wrap; line-height: 1.55;">{{ $entry->reason }}</div>
                    @endif
                    <small class="d-block mt-1" style="color: var(--hr-text-muted);">
                        {{ trans('hr-manager::watchlist.app_match_added_by') }}
                        <strong>@if($entry->is_system_added)<i class="fas fa-robot"></i> @endif{{ $entry->added_by_label }}</strong>
                        {{ trans('hr-manager::watchlist.app_match_added_at') }} @hrDate($entry->added_at)
                        @if($entry->expires_at) · {{ trans('hr-manager::watchlist.expires_label') }} @hrDateShort($entry->expires_at) @endif
                    </small>
                    @if($isCleared && $entry->cleared_reason)
                        <small class="d-block mt-1" style="color: var(--hr-text-muted); font-style: italic;">
                            <i class="fas fa-eraser"></i> {{ trans('hr-manager::watchlist.cleared_by') }}: {{ $entry->cleared_reason }}
                            @if($entry->cleared_at) — @hrDateShort($entry->cleared_at) @endif
                        </small>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">{{ trans('hr-manager::watchlist.dossier_no_watchlist') }}</p>
            @endforelse
        </div>
    </div>

    {{-- Intel notes — visibility-respected by IntelService. Hidden entirely for a
         recruiter with no visible notes (nothing that's hidden for recruiters
         surfaces here); directors always see the section so they can add. --}}
    @if($notes->isNotEmpty() || $isDirector)
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-database"></i> {{ trans('hr-manager::watchlist.dossier_intel_section') }}
                    <span class="badge badge-hr badge-applied ml-2">{{ $notes->count() }}</span>
                </h3>
                <div class="card-tools">
                    <a href="{{ route('hr-manager.intel.show', $characterId) }}" class="btn btn-sm btn-hr-secondary btn-icon">
                        <i class="fas fa-pen"></i> {{ trans('hr-manager::watchlist.dossier_manage_intel') }}
                    </a>
                </div>
            </div>
            <div class="card-body">
                @forelse($notes as $note)
                    <div class="intel-note mb-3">
                        <div class="mb-1">
                            <span class="badge mr-1" style="background: rgba(255,255,255,0.06); color: var(--hr-text-white, #fff); border: 1px solid var(--hr-border);">
                                <img src="https://images.evetech.net/characters/{{ $note->character_id }}/portrait?size=32" style="width:16px;height:16px;border-radius:50%;vertical-align:middle;margin-right:4px;" alt="">{{ $charNames[$note->character_id] ?? ($note->character_name ?: ('Character #' . $note->character_id)) }}
                            </span>
                            @if($note->scope_corporation_id)
                                <span class="badge badge-secondary mr-1">{{ $corpNames[$note->scope_corporation_id] ?? trans('hr-manager::intel.scope_corp') }}</span>
                            @else
                                <span class="badge mr-1" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.4);"><i class="fas fa-globe"></i> {{ trans('hr-manager::intel.scope_global') }}</span>
                            @endif
                            @if($note->recruiter_visible)
                                <span class="badge mr-1" style="background: rgba(40,167,69,0.2); color: var(--hr-text-light); border: 1px solid rgba(40,167,69,0.5);"><i class="fas fa-eye"></i> {{ trans('hr-manager::intel.shared_with_recruiters_short') }}</span>
                            @endif
                            @foreach(($note->tags ?? []) as $tag)
                                <span class="badge mr-1" style="background: rgba(255,255,255,0.05); color: var(--hr-text-light); border: 1px solid var(--hr-border);">{{ $tag }}</span>
                            @endforeach
                        </div>
                        <div style="color: var(--hr-text-light); white-space: pre-wrap; line-height: 1.55;">{{ $note->body }}</div>
                        <small style="color: var(--hr-text-muted);">
                            {{ trans('hr-manager::intel.added_by') }} <strong>{{ $note->author->name ?? 'User #' . $note->author_id }}</strong>
                            @hrDate($note->created_at)
                            @if($note->expires_at) · {{ trans('hr-manager::intel.expires_at') }} @hrDateShort($note->expires_at) @endif
                        </small>
                    </div>
                    @if(!$loop->last)<hr style="border-color: rgba(255,255,255,0.06);">@endif
                @empty
                    <p class="text-muted mb-0">{{ trans('hr-manager::intel.no_notes_for_character') }}</p>
                @endforelse
            </div>
        </div>
    @endif

    {{-- History — director-only. --}}
    @if($isDirector)
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-history"></i> {{ trans('hr-manager::watchlist.dossier_history_section') }}
                    <span class="badge badge-hr badge-applied ml-2">{{ $history->count() }}</span>
                </h3>
            </div>
            <div class="card-body">
                @forelse($history as $event)
                    @php
                        $sev = $event->payload['severity'] ?? null;
                        $sevColor = ['critical' => 'var(--hr-danger, #dc3545)', 'high' => 'var(--hr-danger, #dc3545)', 'warning' => 'var(--hr-warning, #ffc107)'][$sev] ?? 'var(--hr-info, #17a2b8)';
                        $actorName = $event->actor_user_id ? ($actorNames[(int) $event->actor_user_id] ?? ('User #' . $event->actor_user_id)) : trans('hr-manager::watchlist.dossier_actor_system');
                        $evLabel = $event->payload['label'] ?? ucwords(str_replace('_', ' ', $event->event_type));
                        $evReason = $event->payload['reason'] ?? null;
                    @endphp
                    <div class="d-flex" style="gap: 10px; padding: 6px 0;{{ !$loop->last ? ' border-bottom: 1px solid rgba(255,255,255,0.05);' : '' }}">
                        <div style="color: {{ $sevColor }}; padding-top: 2px;"><i class="fas fa-circle" style="font-size: 0.5rem;"></i></div>
                        <div style="flex: 1;">
                            <span style="color: var(--hr-text-white, #fff);">{{ $evLabel }}</span>
                            @if($evReason)
                                <span style="color: var(--hr-text-muted);">&mdash; {{ Str::limit($evReason, 120) }}</span>
                            @endif
                            <small class="d-block" style="color: var(--hr-text-muted);">
                                @hrDate($event->occurred_at) · {{ $actorName }}
                            </small>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">{{ trans('hr-manager::watchlist.dossier_no_history') }}</p>
                @endforelse
            </div>
        </div>
    @endif

</div>
@endsection

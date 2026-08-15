@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::intel.character_intel', ['name' => $displayName]))
@section('page_header', trans('hr-manager::intel.character_intel', ['name' => $displayName]))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.8">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif

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
                        <a href="{{ route('hr-manager.intel.index') }}" class="btn btn-sm btn-hr-secondary btn-icon">
                            <i class="fas fa-arrow-left"></i> {{ trans('hr-manager::intel.back_to_index') }}
                        </a>
                        <a href="https://zkillboard.com/character/{{ $characterId }}/" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary btn-icon">
                            <i class="fas fa-crosshairs"></i> zKillboard
                        </a>
                        @can('hr-manager.recruiter')
                            <a href="{{ route('hr-manager.watchlist.dossier', $characterId) }}" class="btn btn-sm btn-hr-secondary btn-icon">
                                <i class="fas fa-folder-open"></i> {{ trans('hr-manager::watchlist.dossier_view') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Watchlist context, if any --}}
    @if(!empty($watchlistMatch))
        @php $isBlack = $watchlistMatch->list_type === 'blacklist'; @endphp
        <div class="watchlist-match-banner {{ $isBlack ? 'watchlist-banner-blacklist' : 'watchlist-banner-whitelist' }}">
            <div style="display: flex; align-items: flex-start; gap: 18px;">
                <div style="font-size: 2.4rem; flex-shrink: 0;">
                    <i class="fas {{ $isBlack ? 'fa-ban' : 'fa-check-circle' }}"></i>
                </div>
                <div style="flex: 1;">
                    <strong style="font-size: 1.2rem;">
                        {{ $isBlack ? trans('hr-manager::watchlist.app_match_blacklist_heading') : trans('hr-manager::watchlist.app_match_whitelist_heading') }}
                    </strong>
                    @if($watchlistMatch->reason)
                        <p class="mb-0 mt-1">{{ $watchlistMatch->reason }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Add note form (director-only) --}}
    @can('hr-manager.director')
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus-circle"></i> {{ trans('hr-manager::intel.add_note_for', ['name' => $displayName]) }}</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('hr-manager.intel.store') }}">
                    @csrf
                    <input type="hidden" name="input" value="{{ $characterId }}">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ trans('hr-manager::intel.scope_label') }}</label>
                                <select name="scope_corporation_id" class="form-control">
                                    <option value="">{{ trans('hr-manager::intel.scope_global') }}</option>
                                    @foreach($corporations as $corp)
                                        <option value="{{ $corp->corporation_id }}">@if(!empty($corp->ticker))[{{ $corp->ticker }}] @endif{{ $corp->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ trans('hr-manager::intel.expires_label') }}</label>
                                <input type="date" name="expires_at" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>{{ trans('hr-manager::intel.body_label') }} <span class="text-danger">*</span></label>
                        <textarea name="body" class="form-control" rows="3" maxlength="8000" required placeholder="{{ trans('hr-manager::intel.body_placeholder') }}"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group mb-2">
                                <label>{{ trans('hr-manager::intel.tags_label') }}</label>
                                <input type="text" name="tags" class="form-control" placeholder="{{ trans('hr-manager::intel.tags_placeholder') }}">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mt-4">
                                <input type="checkbox" name="recruiter_visible" value="1" class="form-check-input" id="rv">
                                <label class="form-check-label" for="rv"><strong>{{ trans('hr-manager::intel.recruiter_visible_label') }}</strong></label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-hr-primary btn-icon">
                        <i class="fas fa-save"></i> {{ trans('hr-manager::intel.save_note') }}
                    </button>
                </form>
            </div>
        </div>
    @endcan

    {{-- Everyone proven to be the same human, plus anyone a director has
         CLAIMED is. The two are shown apart on purpose: one is account data,
         the other is somebody's assertion. --}}
    @if(count($accountIds) > 1 || $altLinks->isNotEmpty())
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-user-friends"></i> {{ trans('hr-manager::intel.same_human_heading') }}
                </h3>
            </div>
            <div class="card-body">
                @if(count($accountIds) > 1)
                    <div class="mb-2">
                        <small class="d-block mb-1" style="color: var(--hr-text-muted);">{{ trans('hr-manager::intel.same_account_intro') }}</small>
                        @foreach($accountIds as $accId)
                            @if($accId !== $characterId)
                                <a href="{{ route('hr-manager.intel.show', $accId) }}" class="badge mr-1 mb-1"
                                   style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.4); text-decoration: none;">
                                    <i class="fas fa-link"></i> {{ $charNames[$accId] ?? ('#' . $accId) }}
                                    @if(isset($altNotes[$accId]))
                                        <span style="opacity: 0.75;">({{ $altNotes[$accId]->count() }})</span>
                                    @endif
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if($altLinks->isNotEmpty())
                    <div>
                        <small class="d-block mb-1" style="color: var(--hr-text-muted);">{{ trans('hr-manager::intel.claimed_alts_intro') }}</small>
                        @foreach($altLinks as $link)
                            @php
                                $lstate = [
                                    'suspected' => ['fg' => '#ffe08a', 'icon' => 'fa-question-circle'],
                                    'confirmed' => ['fg' => '#6ee7b7', 'icon' => 'fa-link'],
                                    'refuted'   => ['fg' => '#f5a3ac', 'icon' => 'fa-unlink'],
                                ][$link['state']] ?? ['fg' => '#ffe08a', 'icon' => 'fa-question-circle'];
                            @endphp
                            <div class="mb-1" style="padding: 5px 9px; background: rgba(0,0,0,0.15); border-radius: 4px;">
                                <span style="color: {{ $lstate['fg'] }};"><i class="fas {{ $lstate['icon'] }}"></i></span>
                                {{ $link['this_is_suspected']
                                    ? trans('hr-manager::intel.claim_is_alt_of')
                                    : trans('hr-manager::intel.claim_has_alt') }}
                                <a href="{{ route('hr-manager.intel.show', $link['other_character_id']) }}" style="color: var(--hr-text-white);">
                                    <strong>{{ $link['other_character_name'] }}</strong>
                                </a>
                                <span class="badge ml-1" style="background: rgba(255,255,255,0.08); color: {{ $lstate['fg'] }}; font-size: 0.62rem;">
                                    {{ trans('hr-manager::watchlist.alt_state_' . $link['state']) }}
                                </span>
                                @if($link['resolution_note'])
                                    <small class="d-block mt-1" style="color: var(--hr-text-muted);">{{ $link['resolution_note'] }}</small>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Notes list --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-database"></i> {{ trans('hr-manager::intel.notes_for_character') }}
                <span class="badge badge-hr badge-applied ml-2">{{ $notes->count() }}</span>
            </h3>
        </div>
        <div class="card-body">
            @forelse($notes as $note)
                <div class="intel-note mb-3">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            @if($note->scope_corporation_id)
                                <span class="badge badge-secondary mr-1">{{ trans('hr-manager::intel.scope_corp') }}</span>
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
                        @can('hr-manager.director')
                            <form method="POST" action="{{ route('hr-manager.intel.destroy', $note->id) }}" onsubmit="return confirm(@js(trans('hr-manager::intel.confirm_delete')))">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-link p-0" style="color: var(--hr-text-muted);"><i class="fas fa-trash"></i></button>
                            </form>
                        @endcan
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

            {{-- Notes filed against this person's OTHER characters. Kept in
                 this card rather than a separate one: it is the same dossier
                 about the same human, just filed elsewhere. Each is labelled
                 so it never reads as a note about the character on screen. --}}
            @if($altNotes->isNotEmpty())
                <hr style="border-color: rgba(255,255,255,0.10); margin: 20px 0;">
                <h6 style="color: var(--hr-text-white);">
                    <i class="fas fa-users"></i> {{ trans('hr-manager::intel.account_notes_heading') }}
                </h6>
                <small class="d-block mb-3" style="color: var(--hr-text-muted);">{{ trans('hr-manager::intel.account_notes_intro') }}</small>

                @foreach($altNotes as $altCharId => $group)
                    <div class="mb-3" style="padding: 10px 12px; background: rgba(255,255,255,0.03); border-left: 2px solid rgba(102,126,234,0.5); border-radius: 3px;">
                        <div class="mb-2">
                            <a href="{{ route('hr-manager.intel.show', $altCharId) }}" style="color: var(--hr-text-white);">
                                <strong>{{ $charNames[$altCharId] ?? ('#' . $altCharId) }}</strong>
                            </a>
                            <span class="badge ml-1" style="background: rgba(255,255,255,0.06); color: var(--hr-text-muted); font-size: 0.62rem;">
                                {{ trans_choice('hr-manager::intel.account_note_count', $group->count(), ['count' => $group->count()]) }}
                            </span>
                        </div>
                        @foreach($group as $an)
                            <div class="mb-2">
                                <div style="color: var(--hr-text-light); white-space: pre-wrap; line-height: 1.5; font-size: 0.9rem;">{{ $an->body }}</div>
                                <small style="color: var(--hr-text-muted);">
                                    {{ trans('hr-manager::intel.added_by') }} <strong>{{ $an->author->name ?? 'User #' . $an->author_id }}</strong>
                                    @hrDate($an->created_at)
                                </small>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</div>
@endsection

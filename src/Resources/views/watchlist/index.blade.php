@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::watchlist.title'))
@section('page_header', trans('hr-manager::watchlist.title'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.9">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif

    {{-- Page intro banner --}}
    <div class="page-intro-banner">
        <div class="page-intro-header">
            <div class="page-intro-body">
                <h4><i class="fas fa-info-circle"></i> {{ trans('hr-manager::watchlist.intro_what_label') }}</h4>
                <p>{!! trans('hr-manager::watchlist.intro_what_body') !!}</p>
            </div>
            <span class="page-intro-visibility"><i class="fas fa-users"></i> {{ trans('hr-manager::watchlist.intro_visibility') }}</span>
        </div>
        <div class="page-intro-when">
            <h4><i class="fas fa-list-ul"></i> {{ trans('hr-manager::watchlist.intro_when_label') }}</h4>
            <ul>
                <li>{{ trans('hr-manager::watchlist.intro_when_1') }}</li>
                <li>{{ trans('hr-manager::watchlist.intro_when_2') }}</li>
                <li>{{ trans('hr-manager::watchlist.intro_when_3') }}</li>
            </ul>
        </div>
    </div>

    {{-- List type toggle --}}
    <div class="card card-dark mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('hr-manager.watchlist.index', ['list_type' => 'blacklist']) }}"
                   class="btn btn-sm {{ $listType === 'blacklist' ? 'btn-hr-primary' : 'btn-hr-secondary' }}">
                    <span class="badge badge-hr badge-rejected mr-1">{{ $blacklistCount }}</span>
                    <i class="fas fa-ban"></i> {{ trans('hr-manager::watchlist.blacklist') }}
                </a>
                <a href="{{ route('hr-manager.watchlist.index', ['list_type' => 'whitelist']) }}"
                   class="btn btn-sm {{ $listType === 'whitelist' ? 'btn-hr-primary' : 'btn-hr-secondary' }}">
                    <span class="badge badge-hr badge-accepted mr-1">{{ $whitelistCount }}</span>
                    <i class="fas fa-check-circle"></i> {{ trans('hr-manager::watchlist.whitelist') }}
                </a>
                <a href="{{ route('hr-manager.watchlist.index', ['list_type' => 'cleared']) }}"
                   class="btn btn-sm {{ $listType === 'cleared' ? 'btn-hr-primary' : 'btn-hr-secondary' }}">
                    <i class="fas fa-history"></i> {{ trans('hr-manager::watchlist.tab_cleared') }}
                </a>
            </div>
        </div>
    </div>

    {{-- Add entry form (director-only) --}}
    @can('hr-manager.director')
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    @if($listType === 'blacklist')
                        <i class="fas fa-ban" style="color: var(--hr-danger);"></i>
                        {{ trans('hr-manager::watchlist.add_to_blacklist') }}
                    @else
                        <i class="fas fa-check-circle" style="color: var(--hr-success);"></i>
                        {{ trans('hr-manager::watchlist.add_to_whitelist') }}
                    @endif
                </h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('hr-manager.watchlist.store') }}">
                    @csrf
                    <input type="hidden" name="list_type" value="{{ $listType }}">

                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>{{ trans('hr-manager::watchlist.input_label') }} <span class="text-danger">*</span></label>
                                <input type="text" name="input" class="form-control"
                                       placeholder="{{ trans('hr-manager::watchlist.input_placeholder') }}"
                                       value="{{ old('input') }}" required maxlength="64">
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::watchlist.input_help') }}</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>{{ trans('hr-manager::watchlist.scope_label') }}</label>
                                <select name="scope_corporation_id" class="form-control">
                                    <option value="">{{ trans('hr-manager::watchlist.scope_global') }}</option>
                                    @foreach($corporations as $corp)
                                        <option value="{{ $corp->corporation_id }}" {{ (int) old('scope_corporation_id') === (int) $corp->corporation_id ? 'selected' : '' }}>
                                            @if(!empty($corp->ticker))[{{ $corp->ticker }}] @endif{{ $corp->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::watchlist.scope_help') }}</small>
                            </div>
                        </div>
                        @if($listType === 'blacklist')
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>{{ trans('hr-manager::watchlist.severity_label') }}</label>
                                    <select name="severity" class="form-control">
                                        <option value="low" {{ old('severity') === 'low' ? 'selected' : '' }}>{{ trans('hr-manager::watchlist.severity_low') }}</option>
                                        <option value="medium" {{ in_array(old('severity', 'medium'), ['medium', null], true) ? 'selected' : '' }}>{{ trans('hr-manager::watchlist.severity_medium') }}</option>
                                        <option value="high" {{ old('severity') === 'high' ? 'selected' : '' }}>{{ trans('hr-manager::watchlist.severity_high') }}</option>
                                    </select>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="row">
                        <div class="col-md-9">
                            <div class="form-group mb-2">
                                <label>{{ trans('hr-manager::watchlist.reason_label') }}</label>
                                <textarea name="reason" class="form-control" rows="2" maxlength="2000"
                                          placeholder="{{ trans('hr-manager::watchlist.reason_placeholder') }}">{{ old('reason') }}</textarea>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group mb-2">
                                <label>{{ trans('hr-manager::watchlist.expires_label') }}</label>
                                <input type="date" name="expires_at" class="form-control" value="{{ old('expires_at') }}">
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::watchlist.expires_help') }}</small>
                            </div>
                        </div>
                    </div>

                    {{-- Alliance scope + notification policy block --}}
                    <hr style="border-color: rgba(255,255,255,0.06);">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-2">
                                <label>{{ trans('hr-manager::watchlist.alliance_scope_label') }}</label>
                                <input type="number" name="scope_alliance_id" class="form-control"
                                       placeholder="{{ trans('hr-manager::watchlist.alliance_id_placeholder') }}"
                                       value="{{ old('scope_alliance_id') }}">
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::watchlist.alliance_scope_help') }}</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label>{{ trans('hr-manager::watchlist.policy_heading') }}</label>
                            <div class="form-check mb-1">
                                <input type="checkbox" name="notify_on_corp_match" value="1" class="form-check-input" id="watchNotifyCorp" {{ old('notify_on_corp_match', true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="watchNotifyCorp"><small>{{ trans('hr-manager::watchlist.notify_on_corp_match') }}</small></label>
                            </div>
                            <div class="form-check mb-1">
                                <input type="checkbox" name="notify_on_alliance_match" value="1" class="form-check-input" id="watchNotifyAlliance" {{ old('notify_on_alliance_match', true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="watchNotifyAlliance"><small>{{ trans('hr-manager::watchlist.notify_on_alliance_match') }}</small></label>
                            </div>
                            <div class="form-check mb-1">
                                <input type="checkbox" name="notify_on_external_change" value="1" class="form-check-input" id="watchNotifyExternal" {{ old('notify_on_external_change') ? 'checked' : '' }}>
                                <label class="form-check-label" for="watchNotifyExternal"><small>{{ trans('hr-manager::watchlist.notify_on_external_change') }}</small></label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-hr-primary btn-icon">
                            <i class="fas fa-plus"></i> {{ trans('hr-manager::watchlist.add_entry') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    {{-- Entries table --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-list"></i>
                {{ $listType === 'blacklist' ? trans('hr-manager::watchlist.blacklist') : trans('hr-manager::watchlist.whitelist') }}
            </h3>
            <div class="card-tools" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                {{-- Display mode: flat by-character list, or grouped by human account. --}}
                <div class="btn-group btn-group-sm" role="group">
                    <a href="{{ route('hr-manager.watchlist.index', array_merge(request()->query(), ['group' => 'character'])) }}"
                       class="btn {{ $groupMode === 'character' ? 'btn-hr-primary' : 'btn-hr-secondary' }}">
                        <i class="fas fa-user"></i> {{ trans('hr-manager::watchlist.group_by_character') }}
                    </a>
                    <a href="{{ route('hr-manager.watchlist.index', array_merge(request()->query(), ['group' => 'account'])) }}"
                       class="btn {{ $groupMode === 'account' ? 'btn-hr-primary' : 'btn-hr-secondary' }}">
                        <i class="fas fa-users"></i> {{ trans('hr-manager::watchlist.group_by_account') }}
                    </a>
                </div>
                <form method="GET" action="{{ route('hr-manager.watchlist.index') }}" class="form-inline">
                    <input type="hidden" name="list_type" value="{{ $listType }}">
                    <input type="hidden" name="group" value="{{ $groupMode }}">
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" class="form-control" placeholder="{{ trans('hr-manager::watchlist.search_placeholder') }}" value="{{ request('search') }}">
                        <div class="input-group-append">
                            <button class="btn btn-hr-primary" type="submit"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            @php $isEmpty = $groupMode === 'account' ? empty($accountGroups) : $entries->isEmpty(); @endphp
            @if($isEmpty)
                <div class="p-4 text-center text-muted">
                    {{ $listType === 'blacklist' ? trans('hr-manager::watchlist.empty_blacklist') : trans('hr-manager::watchlist.empty_whitelist') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::watchlist.character_col') }}</th>
                                <th>{{ trans('hr-manager::watchlist.scope_col') }}</th>
                                @if($listType === 'blacklist')
                                    <th>{{ trans('hr-manager::watchlist.severity_col') }}</th>
                                @endif
                                <th>{{ trans('hr-manager::watchlist.reason_col') }}</th>
                                <th>{{ trans('hr-manager::watchlist.added_col') }}</th>
                                <th>{{ trans('hr-manager::watchlist.expires_col') }}</th>
                                @can('hr-manager.director')
                                    <th>{{ trans('hr-manager::applications.actions') }}</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @if($groupMode === 'account')
                                @php $wlColspan = 5 + ($listType === 'blacklist' ? 1 : 0) + (auth()->user()->can('hr-manager.director') ? 1 : 0); @endphp
                                @foreach($accountGroups as $g)
                                    <tr style="background: rgba(102,126,234,0.10);">
                                        <td colspan="{{ $wlColspan }}">
                                            <img src="https://images.evetech.net/characters/{{ $g['main_character_id'] }}/portrait?size=32" style="width:24px;height:24px;border-radius:50%;vertical-align:middle;margin-right:8px;" alt="">
                                            <strong style="color: var(--hr-text-white, #fff);">{{ $g['main_name'] }}</strong>
                                            <span class="badge badge-hr ml-2">{{ count($g['entries']) }}</span>
                                            @unless($g['user_id'])
                                                <span class="badge ml-1" style="background: rgba(255,193,7,0.22); color:#ffe08a; font-size:0.62rem;" title="{{ trans('hr-manager::watchlist.group_unlinked_hint') }}"><i class="fas fa-user-slash"></i> {{ trans('hr-manager::watchlist.group_unlinked') }}</span>
                                            @endunless
                                        </td>
                                    </tr>
                                    @foreach($g['entries'] as $entry)
                                        @include('hr-manager::watchlist.partials._watchlist_row')
                                    @endforeach
                                @endforeach
                            @else
                                @foreach($entries as $entry)
                                    @include('hr-manager::watchlist.partials._watchlist_row')
                                @endforeach
                            @endif
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($groupMode !== 'account' && $entries && $entries->hasPages())
            <div class="card-footer">
                {{ $entries->appends(request()->query())->links() }}
            </div>
        @endif
    </div>

</div>

<script>
// Clear a watchlist entry. When the character's account has more than one active
// entry (a spy whose alts are all listed), ask whether to clear only this
// character or the whole account before prompting for the required reason.
function hrWatchlistClear(form) {
    var count = parseInt(form.getAttribute('data-account-count') || '1', 10);
    var scope = 'single';
    if (count > 1) {
        var clearAll = confirm(@js(trans('hr-manager::watchlist.clear_account_confirm')).replace(':count', count));
        scope = clearAll ? 'account' : 'single';
    }
    var reason = prompt(@js(trans('hr-manager::watchlist.cleared_reason_label')));
    if (!reason || reason.length < 3) { return false; }
    form.querySelector('[name=cleared_reason]').value = reason;
    form.querySelector('[name=clear_scope]').value = scope;
    return true;
}
</script>
@endsection

{{--
    Recruiter Access panel — surfaced on the application detail page
    when the current viewer has an active temporary-access grant for
    this applicant's character data.

    Self-contained: fetches the current viewer's grant for $application
    from the service, no controller wiring needed. Renders nothing
    when feature is off, viewer has no grant, or the grant has been
    revoked / expired.

    The deep links jump straight into SeAT's native character pages
    (wallet journal / mail / assets / etc.) — the recruiter operates
    in SeAT's own UI, not HR Manager. SeAT's permission middleware
    transparently honours the grant via the temporary role.
--}}
@php
    $accessSvc = app(\HrManager\Services\ApplicantAccessService::class);
    $viewerId  = auth()->user()?->id ?? 0;
    $currentGrant = $viewerId > 0
        ? \HrManager\Models\RecruiterAccessGrant::active()
            ->where('application_id', $application->id)
            ->where('user_id', $viewerId)
            ->where('expires_at', '>=', now())
            ->first()
        : null;

    // For the "Grant access now" fallback: a handler with no active grant
    // (e.g. joined before the feature was enabled).
    $accessFeatureOn = $accessSvc->isFeatureEnabled();
    $viewerIsHandler = $viewerId > 0
        && \HrManager\Models\ApplicationHandler::where('application_id', $application->id)
            ->where('user_id', $viewerId)->exists();

    if (!$currentGrant) {
        $shouldRender = false;
    } else {
        $shouldRender = true;
        // Pre-resolve character names for the deep-link buttons. Cheap
        // — one IN query against character_infos for the grant's IDs.
        $charIds = (array) $currentGrant->character_ids;
        $charNames = \Illuminate\Support\Facades\DB::table('character_infos')
            ->whereIn('character_id', $charIds)
            ->pluck('name', 'character_id')
            ->toArray();
    }
@endphp

@if($shouldRender)
    <div class="card card-dark mb-3" style="border-left: 4px solid var(--hr-info, #17a2b8);">
        <div class="card-header" style="background: rgba(23, 162, 184, 0.12);">
            <h3 class="card-title" style="color: var(--hr-text-white, #fff);">
                <i class="fas fa-key"></i> {{ trans('hr-manager::applications.access_panel_heading') }}
            </h3>
            <div class="card-tools">
                <span class="badge badge-info">
                    <i class="fas fa-clock"></i>
                    {{ trans('hr-manager::applications.access_expires_in', ['rel' => $currentGrant->expires_at->diffForHumans(now(), true)]) }}
                </span>
            </div>
        </div>
        <div class="card-body">
            <p style="color: var(--hr-text-light, #c9d1d9); margin-bottom: 0.75rem;">
                {!! trans('hr-manager::applications.access_panel_body') !!}
            </p>

            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                @php
                    // SeAT mounts the character pages under /characters/ (plural)
                    // and the wallet view is /journal (not /wallet/journal), so
                    // build the URLs from the named routes instead of guessing
                    // paths. Route::has guard degrades to the conventional path
                    // rather than 500ing if a SeAT version renames a route.
                    $charUrl = function ($name, $cid, $path) {
                        return \Illuminate\Support\Facades\Route::has($name)
                            ? route($name, $cid)
                            : url('/characters/' . $cid . $path);
                    };
                @endphp
                @foreach((array) $currentGrant->character_ids as $cid)
                    @php $cname = $charNames[$cid] ?? ('Character #' . $cid); @endphp
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 6px 10px; background: rgba(255,255,255,0.03); border-radius: 4px;">
                        <img src="https://images.evetech.net/characters/{{ $cid }}/portrait?size=32"
                             style="width: 32px; height: 32px; border-radius: 50%;" alt="">
                        <strong style="color: var(--hr-text-white, #fff); min-width: 180px;">{{ $cname }}</strong>
                        <a href="{{ $charUrl('seatcore::character.view.sheet', $cid, '/sheet') }}" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary">
                            <i class="fas fa-id-card"></i> {{ trans('hr-manager::applications.access_link_sheet') }}
                        </a>
                        @if(in_array('character.journal', (array) $currentGrant->permission_set, true) || in_array('character.transactions', (array) $currentGrant->permission_set, true))
                            <a href="{{ $charUrl('seatcore::character.view.journal', $cid, '/journal') }}" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary">
                                <i class="fas fa-wallet"></i> {{ trans('hr-manager::applications.access_link_wallet') }}
                            </a>
                        @endif
                        @if(in_array('character.mail', (array) $currentGrant->permission_set, true))
                            <a href="{{ $charUrl('seatcore::character.view.mail', $cid, '/mail') }}" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary">
                                <i class="fas fa-envelope"></i> {{ trans('hr-manager::applications.access_link_mail') }}
                            </a>
                        @endif
                        @if(in_array('character.asset', (array) $currentGrant->permission_set, true))
                            <a href="{{ $charUrl('seatcore::character.view.assets', $cid, '/assets') }}" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary">
                                <i class="fas fa-boxes"></i> {{ trans('hr-manager::applications.access_link_assets') }}
                            </a>
                        @endif
                        @if(in_array('character.skill', (array) $currentGrant->permission_set, true))
                            <a href="{{ $charUrl('seatcore::character.view.skills', $cid, '/skills') }}" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary">
                                <i class="fas fa-graduation-cap"></i> {{ trans('hr-manager::applications.access_link_skills') }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>

            <small class="d-block mt-3" style="color: var(--hr-text-muted, #8b95a5);">
                <i class="fas fa-info-circle"></i>
                {{ trans('hr-manager::applications.access_panel_footnote') }}
            </small>
        </div>
    </div>
@elseif($accessFeatureOn && $viewerIsHandler)
    {{-- Handler with no active grant (joined before the feature was on, or it
         expired). One-click re-grant — no leave/re-join needed. --}}
    <div class="card card-dark mb-3" style="border-left: 4px solid var(--hr-warning, #ffc107);">
        <div class="card-body" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <i class="fas fa-key" style="color: var(--hr-warning, #ffc107);"></i>
            <span style="color: var(--hr-text-light, #c9d1d9); flex: 1; min-width: 200px;">{{ trans('hr-manager::applications.access_grant_prompt') }}</span>
            <form method="POST" action="{{ route('hr-manager.applications.grant-access', $application->id) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-hr-primary"><i class="fas fa-key"></i> {{ trans('hr-manager::applications.access_grant_now_btn') }}</button>
            </form>
        </div>
    </div>
@endif

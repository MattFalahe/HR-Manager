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
                    // SeAT mounts the character pages under /characters/{id}/<page>.
                    // Build URLs from the named routes; the Route::has guard degrades
                    // to the conventional path rather than 500ing if a SeAT release
                    // renames a route.
                    $charUrl = function ($name, $cid, $path) {
                        return \Illuminate\Support\Facades\Route::has($name)
                            ? route($name, $cid)
                            : url('/characters/' . $cid . $path);
                    };

                    // Full permission -> SeAT character-page map, mirroring SeAT's own
                    // web/src/Config/package.character.menu.php (route names + the
                    // permission that unlocks each page). A button renders only when
                    // the grant's permission_set includes that permission, so the panel
                    // shows EXACTLY what was granted in Settings -> Recruiter Access:
                    // grant character.market and a Market button appears; drop it and
                    // the button disappears. Wallet needs journal OR transactions,
                    // matching SeAT's combined wallet menu entry. Labels reuse SeAT's
                    // own web::seat.* keys (localised, and identical to the sidebar the
                    // recruiter lands on); trans_choice handles the pluralised ones
                    // (e.g. "Standing|Standings").
                    $permSet = (array) $currentGrant->permission_set;
                    $pages = [
                        [['character.sheet'],                             'seatcore::character.view.sheet',         '/sheet',          'fa-id-card',          'web::seat.sheet'],
                        [['character.journal', 'character.transactions'], 'seatcore::character.view.journal',        '/journal',        'fa-wallet',           'web::seat.wallet'],
                        [['character.mail'],                              'seatcore::character.view.mail',          '/mail',           'fa-envelope',         'web::seat.mail'],
                        [['character.asset'],                             'seatcore::character.view.assets',        '/assets',         'fa-boxes',            'web::seat.assets'],
                        [['character.skill'],                             'seatcore::character.view.skills',        '/skills',         'fa-graduation-cap',   'web::seat.skills'],
                        [['character.contract'],                          'seatcore::character.view.contracts',     '/contracts',      'fa-file-signature',   'web::seat.contracts'],
                        [['character.market'],                            'seatcore::character.view.market',        '/markets',        'fa-store',            'web::seat.market'],
                        [['character.industry'],                          'seatcore::character.view.industry',      '/industry',       'fa-industry',         'web::seat.industry'],
                        [['character.mining'],                            'seatcore::character.view.mining_ledger', '/mining-ledger',  'fa-gem',              'web::seat.mining'],
                        [['character.contact'],                           'seatcore::character.view.contacts',      '/contacts',       'fa-address-book',     'web::seat.contacts'],
                        [['character.standing'],                          'seatcore::character.view.standings',     '/standings',      'fa-handshake',        'web::seat.standings'],
                        [['character.killmail'],                          'seatcore::character.view.killmails',     '/killmails',      'fa-skull-crossbones', 'web::seat.killmails'],
                        [['character.notification'],                      'seatcore::character.view.notifications', '/notifications',  'fa-bell',             'web::seat.notifications'],
                        [['character.planetary'],                         'seatcore::character.view.pi',            '/pi',             'fa-globe',            'web::seat.pi'],
                        [['character.research'],                          'seatcore::character.view.research',      '/research',       'fa-flask',            'web::seat.research'],
                        [['character.blueprint'],                         'seatcore::character.view.blueprint',     '/blueprint',      'fa-scroll',           'web::seat.blueprint'],
                        [['character.fitting'],                           'seatcore::character.view.fittings',      '/fittings',       'fa-wrench',           'web::seat.fittings'],
                        [['character.calendar'],                          'seatcore::character.view.calendar',      '/calendar',       'fa-calendar-alt',     'web::seat.calendar'],
                        [['character.intel'],                             'seatcore::character.view.intel.summary', '/intel/summary',  'fa-user-secret',      'web::seat.intel'],
                        [['character.loyalty_points'],                    'character.view.loyalty_points',          '/loyalty-points', 'fa-award',            'web::seat.loyalty_points'],
                    ];
                    $grantedPages = array_values(array_filter($pages, fn ($p) => count(array_intersect($p[0], $permSet)) > 0));
                @endphp
                @foreach((array) $currentGrant->character_ids as $cid)
                    @php $cname = $charNames[$cid] ?? ('Character #' . $cid); @endphp
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 6px 10px; background: rgba(255,255,255,0.03); border-radius: 4px;">
                        <img src="https://images.evetech.net/characters/{{ $cid }}/portrait?size=32"
                             style="width: 32px; height: 32px; border-radius: 50%;" alt="">
                        <strong style="color: var(--hr-text-white, #fff); min-width: 180px;">{{ $cname }}</strong>
                        @forelse($grantedPages as $pg)
                            <a href="{{ $charUrl($pg[1], $cid, $pg[2]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary">
                                <i class="fas {{ $pg[3] }}"></i> {{ trans_choice($pg[4], 2) }}
                            </a>
                        @empty
                            <span style="color: var(--hr-text-muted, #8b95a5); font-size: 0.85rem;">
                                <i class="fas fa-info-circle"></i> {{ trans('hr-manager::applications.access_no_pages') }}
                            </span>
                        @endforelse
                    </div>
                @endforeach
            </div>

            <small class="d-block mt-3" style="color: var(--hr-text-muted, #8b95a5);">
                <i class="fas fa-info-circle"></i>
                {{ trans('hr-manager::applications.access_panel_footnote') }}
            </small>

            {{-- Re-sync: the character list above is a snapshot taken when the grant
                 was created. If the applicant registered a new alt afterwards, this
                 re-runs the grant so the newcomer is picked up (idempotent). --}}
            <form method="POST" action="{{ route('hr-manager.applications.grant-access', $application->id) }}" class="mt-2">
                @csrf
                <button type="submit" class="btn btn-xs btn-hr-secondary"
                        title="{{ trans('hr-manager::applications.access_resync_help') }}"
                        style="font-size: 0.72rem; padding: 2px 8px;">
                    <i class="fas fa-sync-alt"></i> {{ trans('hr-manager::applications.access_resync_btn') }}
                </button>
            </form>
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

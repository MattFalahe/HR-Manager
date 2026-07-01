{{--
    SeAT native-page deep links for a single character.

    Unlike the recruiter-access panel on the application page, there is NO
    temporary grant here: the Members profile is director-tier, and a director
    already holds the SeAT permissions for these pages. These buttons just jump
    straight into SeAT's own character views in a new tab; SeAT's permission
    middleware still gates each page on click, so a director missing a specific
    scope simply gets SeAT's own "not authorised" there.

    Expects: $characterId (int)
--}}
@php
    // Build URLs from SeAT's named routes, with a conventional-path fallback so a
    // route rename in a future SeAT release degrades instead of 500ing (same
    // pattern as the recruiter-access panel).
    $charUrl = function ($name, $cid, $path) {
        return \Illuminate\Support\Facades\Route::has($name)
            ? route($name, $cid)
            : url('/characters/' . $cid . $path);
    };
    $seatLinks = [
        ['seatcore::character.view.sheet',   '/sheet',   'fa-id-card',        trans('hr-manager::members.seat_link_sheet')],
        ['seatcore::character.view.journal', '/journal', 'fa-wallet',         trans('hr-manager::members.seat_link_wallet')],
        ['seatcore::character.view.mail',    '/mail',    'fa-envelope',       trans('hr-manager::members.seat_link_mail')],
        ['seatcore::character.view.assets',  '/assets',  'fa-boxes',          trans('hr-manager::members.seat_link_assets')],
        ['seatcore::character.view.skills',  '/skills',  'fa-graduation-cap', trans('hr-manager::members.seat_link_skills')],
    ];
@endphp
<div class="card card-dark mb-3">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-external-link-alt"></i> {{ trans('hr-manager::members.seat_links_heading') }}
        </h3>
    </div>
    <div class="card-body">
        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            @foreach($seatLinks as [$route, $path, $icon, $label])
                <a href="{{ $charUrl($route, $characterId, $path) }}" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary">
                    <i class="fas {{ $icon }}"></i> {{ $label }}
                </a>
            @endforeach
        </div>
        <small class="d-block mt-2" style="color: var(--hr-text-muted, #8b95a5);">
            <i class="fas fa-info-circle"></i> {{ trans('hr-manager::members.seat_links_footnote') }}
        </small>
    </div>
</div>

{{--
    SeAT native-page deep links for a character (or a whole account).

    Unlike the recruiter-access panel on the application page, there is NO
    temporary grant here: the Members profile is director-tier, and a director
    already holds the SeAT permissions for these pages. These buttons just jump
    straight into SeAT's own character views in a new tab; SeAT's permission
    middleware still gates each page on click, so a director missing a specific
    scope simply gets SeAT's own "not authorised" there.

    Two modes, one renderer:
      - Single character (member profile): pass $characterId. Renders one button
        row, no name (the page already names the character).
      - Whole account (application director card): pass $characters, a list of
        ['character_id' => int, 'name' => string, 'is_applicant' => bool]. Renders
        one signed row per character (portrait + name + buttons).

    Expects:
      $characterId   (int)              — required in single mode
      $characters    (array, optional)  — multi mode; overrides $characterId when non-empty
      $heading       (string, optional) — card title override (default: members heading)
      $note          (string, optional) — footnote override (default: members footnote)
      $characterName (string, optional) — single mode: signs the one row with a name
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
    // Optional overrides — null-coalesce so the shared member-view include (which
    // passes none of these) keeps its original wording.
    $linksHeading  = $heading  ?? trans('hr-manager::members.seat_links_heading');
    $linksFootnote = $note     ?? trans('hr-manager::members.seat_links_footnote');

    // Normalise to a single character list both modes iterate. Multi mode wins
    // when a non-empty $characters is passed; otherwise fall back to the single
    // $characterId / $characterName row (member view + backwards compat).
    $linksCharacters = (isset($characters) && is_array($characters) && !empty($characters))
        ? $characters
        : [[
            'character_id' => (int) ($characterId ?? 0),
            'name'         => $characterName ?? null,
            'is_applicant' => false,
        ]];
    $linksMulti = count($linksCharacters) > 1;
    // Account mode = the caller handed us a character list (the application's
    // director card). Distinct from count > 1: a one-character applicant is
    // still an account listing and still worth a copy button, whereas the
    // single-character member page already names its one character.
    $linksAccountMode = isset($characters) && is_array($characters) && !empty($characters);

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
            <i class="fas fa-external-link-alt"></i> {{ $linksHeading }}
        </h3>
        {{-- Account mode only: the whole account is listed here, so this is the
             natural place to grab every name at once (blacklist checks etc.).
             The single-character member page already names its one character. --}}
        @if($linksAccountMode)
            <div class="card-tools">
                @include('hr-manager::partials._copy_names', [
                    'names' => array_values(array_filter(array_map(function ($lc) {
                        return $lc['name'] ?? null;
                    }, $linksCharacters))),
                    'cid'   => 'seatlinks',
                ])
            </div>
        @endif
    </div>
    <div class="card-body">
        <div style="display: flex; flex-direction: column; gap: 8px;">
            @foreach($linksCharacters as $lc)
                @php
                    $lcId   = (int) ($lc['character_id'] ?? 0);
                    $lcName = $lc['name'] ?? null;
                @endphp
                @if($lcId > 0)
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;{{ $linksMulti ? ' padding: 6px 10px; background: rgba(255,255,255,0.03); border-radius: 4px;' : '' }}">
                        @if($lcName)
                            {{-- Sign the row with the character these links open, so the
                                 reader knows exactly whose pages they're jumping to. --}}
                            <img src="https://images.evetech.net/characters/{{ $lcId }}/portrait?size=32"
                                 style="width: 28px; height: 28px; border-radius: 50%;" alt="">
                            <span style="color: var(--hr-text-white, #fff); font-weight: 600; min-width: 150px;">
                                {{ $lcName }}
                                @if(!empty($lc['is_applicant']))
                                    <span class="badge badge-primary" style="font-size: 0.6rem;">{{ trans('hr-manager::applications.assess_char_applicant') }}</span>
                                @endif
                            </span>
                        @endif
                        @foreach($seatLinks as [$route, $path, $icon, $label])
                            <a href="{{ $charUrl($route, $lcId, $path) }}" target="_blank" rel="noopener" class="btn btn-sm btn-hr-secondary">
                                <i class="fas {{ $icon }}"></i> {{ $label }}
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
        <small class="d-block mt-2" style="color: var(--hr-text-muted, #8b95a5);">
            <i class="fas fa-info-circle"></i> {{ $linksFootnote }}
        </small>
    </div>
</div>

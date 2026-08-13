@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::help.help_documentation'))
@section('page_header', trans('hr-manager::help.help_documentation'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.8">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    <div class="help-wrapper">
        {{-- Sidebar Navigation --}}
        <div class="help-sidebar">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-compass"></i>
                        {{ trans('hr-manager::help.navigation') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <ul class="nav nav-pills flex-column help-nav">
                        <li class="nav-item">
                            <a href="#" class="nav-link active" data-section="overview">
                                <i class="fas fa-home"></i> {{ trans('hr-manager::help.overview') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="getting-started">
                                <i class="fas fa-rocket"></i> {{ trans('hr-manager::help.getting_started') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="features">
                                <i class="fas fa-star"></i> {{ trans('hr-manager::help.features') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="recruitment">
                                <i class="fas fa-bullhorn"></i> {{ trans('hr-manager::help.recruitment_site') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="applications">
                                <i class="fas fa-file-alt"></i> {{ trans('hr-manager::help.applications') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="onboarding">
                                <i class="fas fa-hand-holding-heart"></i> {{ trans('hr-manager::help.onboarding_nav') }}
                                <span class="badge ml-1" style="background: rgba(40,167,69,0.28); color: #6ee7b7; border: 1px solid rgba(40,167,69,0.55); font-size: 0.72rem; font-weight: 700; padding: 2px 7px; vertical-align: middle;">1.0.1</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="tiers">
                                <i class="fas fa-layer-group"></i> {{ trans('hr-manager::help.activity_tiers') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="corp-health">
                                <i class="fas fa-heartbeat"></i> {{ trans('hr-manager::help.corp_health') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="purge">
                                <i class="fas fa-user-times"></i> {{ trans('hr-manager::help.purge_workflow') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="players">
                                <i class="fas fa-user-friends"></i> {{ trans('hr-manager::help.players_members') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="notes">
                                <i class="fas fa-sticky-note"></i> {{ trans('hr-manager::help.notes') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="notifications">
                                <i class="fas fa-bell"></i> {{ trans('hr-manager::help.notifications') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="integrations">
                                <i class="fas fa-plug"></i> {{ trans('hr-manager::help.integrations') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="permissions">
                                <i class="fas fa-shield-alt"></i> {{ trans('hr-manager::help.permissions') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="commands">
                                <i class="fas fa-terminal"></i> {{ trans('hr-manager::help.commands') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="custom-styling">
                                <i class="fas fa-paint-brush"></i> {{ trans('hr-manager::help.custom_styling') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="faq">
                                <i class="fas fa-question-circle"></i> {{ trans('hr-manager::help.faq') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Content Area --}}
        <div class="help-content">

            {{-- Search Box --}}
            <div class="search-box">
                <input type="text" id="helpSearch" placeholder="{{ trans('hr-manager::help.search_placeholder') }}" class="form-control">
                <i class="fas fa-search"></i>
            </div>

            {{-- ============================================================
                 OVERVIEW — canonical six-card structure:
                   1. Plugin Information
                   2. Version Status
                   3. Welcome to HR Manager
                   4. Two Faces (brand hero)
                   5. What is HR Manager?
                   6. Key Features
                   7. Quick Links + Support
                 ============================================================ --}}
            <div id="overview" class="help-section active">

                {{-- 1. Plugin Information — identity only.
                     Quick links + Support moved to a dedicated card at
                     the bottom of the overview. --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-info-circle"></i>
                        {{ trans('hr-manager::help.plugin_info_title') }}
                    </h3>
                    <p>
                        Version:
                        <img src="https://img.shields.io/packagist/v/mattfalahe/hr-manager?label=release&color=667eea" alt="Version" style="vertical-align: middle;">
                        <img src="https://img.shields.io/badge/SeAT-5.0-764ba2" alt="SeAT 5.0" style="vertical-align: middle;">
                    </p>
                    <p>License: GPL-2.0</p>
                    <p>
                        <i class="fas fa-user"></i> <strong>{{ trans('hr-manager::help.author') }}:</strong> Matt Falahe<br>
                        <i class="fas fa-envelope"></i> <a href="mailto:mattfalahe@gmail.com" style="color: #667eea;">mattfalahe@gmail.com</a>
                    </p>
                </div>

                {{-- 2. Version Status — installed vs latest on Packagist.
                     VersionChecker handles caching, fallbacks, dev-branch
                     awareness; the badge color/label keys off the
                     resolved status. --}}
                @php
                    $vs = $versionStatus ?? ['current' => '?', 'current_source' => 'config', 'is_dev_branch' => false, 'latest' => null, 'status' => 'unknown', 'message' => '', 'release_url' => null];
                    $statusBadgeClass = [
                        'current'    => 'badge-success',
                        'outdated'   => 'badge-warning',
                        'ahead'      => 'badge-info',
                        'dev_branch' => 'badge-info',
                        'unknown'    => 'badge-secondary',
                    ][$vs['status']] ?? 'badge-secondary';
                    $statusLabel = [
                        'current'    => '✓ Up to date',
                        'outdated'   => '⚠ Update available',
                        'ahead'      => '🚀 Pre-release',
                        'dev_branch' => '🌱 Development branch',
                        'unknown'    => '— Unable to check',
                    ][$vs['status']] ?? '— Unknown';
                    $installedDisplay = $vs['is_dev_branch'] ? $vs['current'] : ('v' . $vs['current']);
                    $sourceHint = $vs['current_source'] === 'composer'
                        ? "resolved via Composer's installed.json"
                        : 'resolved via hr-manager.config.php (fallback, Composer metadata unavailable)';
                @endphp
                <div class="help-card">
                    <h3><i class="fas fa-tag"></i> {{ trans('hr-manager::help.version_status_title') }}</h3>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin: 0.5rem 0;">
                        <div>
                            <strong>{{ trans('hr-manager::help.installed') }}:</strong>
                            <span class="badge badge-secondary" style="font-size: 0.9rem;" title="{{ $sourceHint }}">{{ $installedDisplay }}</span>
                        </div>
                        <div>
                            <strong>{{ trans('hr-manager::help.latest_release') }}:</strong>
                            @if($vs['latest'])
                                <span class="badge badge-secondary" style="font-size: 0.9rem;">v{{ $vs['latest'] }}</span>
                            @else
                                <span class="badge badge-secondary" style="font-size: 0.9rem;">{{ trans('hr-manager::help.unknown') }}</span>
                            @endif
                        </div>
                        <div>
                            <span class="badge {{ $statusBadgeClass }}" style="font-size: 0.9rem;">{{ $statusLabel }}</span>
                        </div>
                        @if($vs['release_url'])
                            <div>
                                <a href="{{ $vs['release_url'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-sm-primary">
                                    <i class="fas fa-external-link-alt"></i> {{ trans('hr-manager::help.view_release_notes') }}
                                </a>
                            </div>
                        @endif
                    </div>
                    <small class="text-muted">{{ $vs['message'] }}</small>
                    @if($vs['status'] === 'outdated')
                        <div class="info-box" style="margin-top: 0.75rem;">
                            <i class="fas fa-arrow-circle-up"></i>
                            <strong>{{ trans('hr-manager::help.upgrade_recipe') }}:</strong>
                            <pre style="margin-top: 0.4rem; margin-bottom: 0;"><code>docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d</code></pre>
                            <small class="text-muted" style="display: block; margin-top: 0.4rem;">{{ trans('hr-manager::help.upgrade_recipe_note') }}</small>
                        </div>
                    @endif
                    <small class="text-muted" style="display: block; margin-top: 0.4rem; font-size: 0.75rem;">
                        <i class="fas fa-info-circle"></i>
                        {{ trans('hr-manager::help.version_check_note') }}
                    </small>
                </div>

                {{-- 3. Welcome --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-users"></i>
                        {{ trans('hr-manager::help.welcome_title') }}
                    </h3>
                    <p>{{ trans('hr-manager::help.welcome_desc') }}</p>
                </div>

                {{-- 4. Two Faces — brand hero. Gradient frame, side-by-side
                     face cards, three-beat motto strip. --}}
                <div class="help-card two-faces-hero">
                    <div class="two-faces-tagline">
                        <i class="fas fa-theater-masks"></i>
                        <i class="fas fa-balance-scale" style="margin: 0 0.25rem;"></i>
                        {{ trans('hr-manager::help.brand_tagline') }}
                    </div>
                    <h3 class="two-faces-title">
                        <i class="fas fa-balance-scale" style="margin-right: 0.4rem;"></i>{{ trans('hr-manager::help.two_faces_title') }}
                    </h3>
                    <p class="two-faces-intro">{!! trans('hr-manager::help.two_faces_intro') !!}</p>

                    <div class="two-faces-grid">
                        <div class="face-card face-recruiter">
                            <div class="face-icon"><i class="fas fa-bullhorn"></i></div>
                            <h4>{{ trans('hr-manager::help.face_recruiter_title') }}</h4>
                            <p>{!! trans('hr-manager::help.face_recruiter_body') !!}</p>
                        </div>
                        <div class="face-card face-director">
                            <div class="face-icon"><i class="fas fa-heartbeat"></i></div>
                            <h4>{{ trans('hr-manager::help.face_director_title') }}</h4>
                            <p>{!! trans('hr-manager::help.face_director_body') !!}</p>
                        </div>
                    </div>

                    <div class="two-faces-motto">
                        <i class="fas fa-circle"></i> {{ trans('hr-manager::help.brand_motto_part_1') }}
                        <i class="fas fa-circle"></i> {{ trans('hr-manager::help.brand_motto_part_2') }}
                        <i class="fas fa-circle"></i> {{ trans('hr-manager::help.brand_motto_part_3') }}
                    </div>
                </div>

                {{-- 5. What is HR Manager? --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-flag"></i>
                        {{ trans('hr-manager::help.what_is_title') }}
                    </h3>
                    <p>{!! trans('hr-manager::help.what_is_desc') !!}</p>

                    <div class="info-box">
                        <i class="fas fa-lightbulb"></i>
                        <strong>{{ trans('hr-manager::help.key_benefit') }}:</strong>
                        {{ trans('hr-manager::help.key_benefit_desc') }}
                    </div>
                </div>

                {{-- 6. Key Features — curated grid of the biggest capabilities
                     so operators get the at-a-glance pitch without scrolling
                     to the Features sidebar section. Fourteen items, two columns:
                     recruitment funnel + counter-intel on the left, the director
                     assessment console + ops on the right. --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-star"></i>
                        {{ trans('hr-manager::help.key_features_title') }}
                    </h3>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-bullhorn"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_public_landings') }}</strong><br><small>{{ trans('hr-manager::help.feat_public_landings_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-filter"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_eligibility') }}</strong><br><small>{{ trans('hr-manager::help.feat_eligibility_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-clipboard-check"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_assessment') }}</strong><br><small>{{ trans('hr-manager::help.feat_assessment_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-user-secret"></i></span>
                                <div><strong>{!! trans('hr-manager::help.feat_counterintel') !!}</strong><br><small>{{ trans('hr-manager::help.feat_counterintel_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-link"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_public_tracking') }}</strong><br><small>{{ trans('hr-manager::help.feat_public_tracking_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-user-friends"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_multi_handler') }}</strong><br><small>{{ trans('hr-manager::help.feat_multi_handler_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-history"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_activity_log') }}</strong><br><small>{{ trans('hr-manager::help.feat_activity_log_desc') }}</small></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-heartbeat"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_corp_health') }}</strong><br><small>{{ trans('hr-manager::help.feat_corp_health_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-user-tag"></i></span>
                                <div><strong>{!! trans('hr-manager::help.feat_alignment') !!}</strong><br><small>{{ trans('hr-manager::help.feat_alignment_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-key"></i></span>
                                <div><strong>{!! trans('hr-manager::help.feat_token_coverage') !!}</strong><br><small>{{ trans('hr-manager::help.feat_token_coverage_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-user-times"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_purge') }}</strong><br><small>{{ trans('hr-manager::help.feat_purge_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-id-badge"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_titles_roles') }}</strong><br><small>{{ trans('hr-manager::help.feat_titles_roles_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-wallet"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_wallet') }}</strong><br><small>{{ trans('hr-manager::help.feat_wallet_desc') }}</small></div>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon"><i class="fas fa-puzzle-piece"></i></span>
                                <div><strong>{{ trans('hr-manager::help.feat_ecosystem') }}</strong><br><small>{{ trans('hr-manager::help.feat_ecosystem_desc') }}</small></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 7. Quick Links + Support — standalone card so the
                     buttons get their own visual real-estate at the end
                     of the overview. --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-link"></i>
                        {{ trans('hr-manager::help.quick_links_title') }}
                    </h3>
                    <div class="quick-links" style="margin-top: 15px;">
                        <a href="https://github.com/MattFalahe/HR-Manager" class="quick-link" target="_blank" style="padding: 12px;">
                            <i class="fab fa-github" style="font-size: 1.1rem; margin-bottom: 4px;"></i>
                            {{ trans('hr-manager::help.github_repo') }}
                        </a>
                        <a href="https://github.com/MattFalahe/HR-Manager/blob/main/CHANGELOG.md" class="quick-link" target="_blank" style="padding: 12px;">
                            <i class="fas fa-list" style="font-size: 1.1rem; margin-bottom: 4px;"></i>
                            {{ trans('hr-manager::help.changelog') }}
                        </a>
                        <a href="https://github.com/MattFalahe/HR-Manager/issues" class="quick-link" target="_blank" style="padding: 12px;">
                            <i class="fas fa-bug" style="font-size: 1.1rem; margin-bottom: 4px;"></i>
                            {{ trans('hr-manager::help.report_issues') }}
                        </a>
                        <a href="https://github.com/MattFalahe/HR-Manager/blob/main/README.md" class="quick-link" target="_blank" style="padding: 12px;">
                            <i class="fas fa-book" style="font-size: 1.1rem; margin-bottom: 4px;"></i>
                            {{ trans('hr-manager::help.readme') }}
                        </a>
                    </div>

                    <div class="success-box" style="margin-top: 20px;">
                        <i class="fas fa-heart"></i>
                        <div>
                            <strong>{{ trans('hr-manager::help.support_project') }}:</strong>
                            {!! trans('hr-manager::help.support_list') !!}
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 GETTING STARTED
                 ============================================================ --}}
            <div id="getting-started" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-rocket"></i> {{ trans('hr-manager::help.getting_started_title') }}</h3>
                    <p>{!! trans('hr-manager::help.getting_started_intro') !!}</p>

                    <div class="info-box">
                        <i class="fas fa-id-badge"></i>
                        <strong>Requires a Director ESI token (you almost certainly already have one).</strong> HR reads your corporation's members, roles, titles and login activity from SeAT's synced data, which needs at least one <strong>character holding the in-game Director role</strong> with an ESI token in SeAT (with the corporation scopes). Without it the Members roster falls back to a sparse list and Corp Health has very little to classify.
                    </div>

                    <h4>{{ trans('hr-manager::help.first_run_title') }}</h4>
                    <ol class="step-by-step">
                        <li>
                            <strong>Assign permissions</strong><br>
                            In SeAT's <strong>Access Management</strong>, grant the HR roles to the right people: <code>hr-manager.recruiter</code> (Applications + applicant assessment + watchlist/intel), <code>hr-manager.personnel</code> (Personnel Manager — grant <em>alongside</em> Recruiter to let a senior recruiter accept/reject without the full Director role), <code>hr-manager.director</code> (member / player profiles, Corp Health, purge, Activity Log), <code>hr-manager.admin</code> (Settings + the diagnostic page). Higher tiers inherit the lower ones; Personnel Manager is the one add-on you pair with Recruiter.
                        </li>
                        <li>
                            <strong>Pick your recruitment SSO scope profile</strong> — do this <em>before</em> you collect applications<br>
                            <strong>Settings &rarr; SSO &amp; Scopes</strong> chooses which SeAT SSO profile the apply flow sends applicants through, and that single choice decides what the <strong>Applicant Assessment</strong> can see. Scopes are tiered: <strong>required</strong> (<code>publicData</code> — applications don't work without it), <strong>recommended</strong> (skills / wallet / assets / mail — lights up the core assessment signals), and <strong>optional intel</strong> (clones &amp; implants, corp roles, killmails, standings, contacts — the deeper recruiter-intel signals). HR shows a sufficiency verdict (<em>broken / minimal / full</em>) so you can tell at a glance whether the profile carries enough. A missing scope is just an unlit signal, never an error.
                        </li>
                        <li>
                            <strong>Set the member token requirement</strong> (optional)<br>
                            Same screen, <strong>Member token requirement</strong>: pick a profile every existing member's token is graded against — <em>Token OK / Missing scopes / Token lost / Never linked</em> — surfaced on the Members roster and a Corp Health coverage card. Leave it as <em>None</em> to only check that a token exists at all.
                        </li>
                        <li>
                            <strong>{{ trans('hr-manager::help.step_template_title') }}</strong><br>
                            {!! trans('hr-manager::help.step_template_body') !!}
                        </li>
                        <li>
                            <strong>{{ trans('hr-manager::help.step_landing_title') }}</strong><br>
                            {!! trans('hr-manager::help.step_landing_body') !!}
                        </li>
                        <li>
                            <strong>{{ trans('hr-manager::help.step_tiers_title') }}</strong><br>
                            {!! trans('hr-manager::help.step_tiers_body') !!}
                            <div class="info-box info-box-warning" style="margin-top:0.5rem;">
                                <i class="fas fa-plug"></i>
                                <strong>Needs SeAT Connector.</strong> Auto-resolving Discord roles to tiers only works with the SeAT Connector framework installed. <strong>No Connector? Skip this step</strong> — set tiers manually or leave members at the <code>L0 Member</code> default; Corp Health still works.
                            </div>
                        </li>
                        <li>
                            <strong>{{ trans('hr-manager::help.step_webhooks_title') }}</strong><br>
                            {!! trans('hr-manager::help.step_webhooks_body') !!} The <strong>Notification Routing Map</strong> tab then shows, read-only, which webhook fires for every category and which role it pings.
                        </li>
                        <li>
                            <strong>Configure purge squad cleanup</strong> — before you ever schedule a purge<br>
                            <strong>Settings &rarr; Squad Cleanup</strong>: HR can clear a purged member's SeAT squads so any Connector-bound Discord roles drop off with them. <strong>Set the never-touch exclusions list first</strong> — add keep-in-touch squads such as <em>Former Member</em> or <em>Alliance</em> access so both the button and the automation always skip them. Only <code>manual</code> / <code>hidden</code> squads are ever removed; <code>auto</code> squads SeAT recomputes are never touched. Cleanup is <strong>off by default</strong> — a human clicks <em>Remove from these squads</em> on the purge board. Opt in here to have it run automatically at <strong>T-24h / T-12h</strong> before the kick date (or immediately once the member is detected as having left the corp). The full reminder ladder is on the <strong>Purge Workflow</strong> page.
                        </li>
                        <li>
                            <strong>Run the guided initializer</strong> — <code>php artisan hr-manager:init</code><br>
                            This populates every dashboard at once instead of waiting for the nightly crons. <strong>Skip it and the first load of Corp Health / Members is slow</strong>, because the assessment cache, the classifications and corp-join detection are all computed on demand. <code>init</code> runs a readiness check then a sequenced load (cache &rarr; classify &rarr; detect joins, plus a buyback backfill when Buyback Manager is present); it deliberately <strong>skips the notification passes</strong>, so running it before your webhooks are wired gives a quiet first load. Add <code>--check</code> to only print the readiness report.
                            <div class="info-box" style="margin-top:0.5rem;">
                                <i class="fas fa-terminal"></i>
                                <code>docker exec -it seat-docker-front-1 php artisan hr-manager:init</code>
                            </div>
                        </li>
                    </ol>

                    <div class="info-box info-box-success">
                        <i class="fas fa-bolt"></i>
                        <strong>Faster detection with Manager Core.</strong> HR works standalone, but if <strong>Manager Core</strong> is installed it rides MC's <strong>ESI fast-poll</strong>: new corp joins (<code>CorpAppAcceptMsg</code>) and <em>voluntary</em> leaves (<code>CharLeftCorpMsg</code>) are picked up within <strong>~2 minutes</strong> from the director notification feed instead of waiting on the 30-minute roster diff. (A kick sends no EVE notification, so those still come from the diff.) Manager Core also unlocks the cross-plugin wallet / mining / blueprint / structure / buyback signals throughout Corp Health.
                    </div>

                    <div class="success-box">
                        <i class="fas fa-check-circle"></i>
                        <strong>{{ trans('hr-manager::help.tip_label') }}:</strong>
                        {{ trans('hr-manager::help.getting_started_tip') }}
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 FEATURES — proper documentation, one card per feature
                 group with what / how / where / related, not just info
                 tooltips.
                 ============================================================ --}}
            <div id="features" class="help-section">

                {{-- Intro --}}
                <div class="help-card">
                    <h3><i class="fas fa-star"></i> {{ trans('hr-manager::help.features_title') }}</h3>
                    <p>{!! trans('hr-manager::help.features_intro') !!}</p>
                </div>

                {{-- 1. Recruitment funnel --}}
                <div class="help-card">
                    <h3><i class="fas fa-bullhorn"></i> {{ trans('hr-manager::help.feat_recruitment_funnel') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_recruitment_funnel_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_capabilities') }}</h4>
                    <ul>
                        <li>{!! trans('hr-manager::help.feat_rf_cap_1') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_rf_cap_2') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_rf_cap_3') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_rf_cap_4') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_rf_cap_5') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_rf_cap_6') !!}</li>
                    </ul>

                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_rf_where') !!}
                    </div>
                </div>

                {{-- 2. Application workflow --}}
                <div class="help-card">
                    <h3><i class="fas fa-file-alt"></i> {{ trans('hr-manager::help.feat_app_workflow') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_app_workflow_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_capabilities') }}</h4>
                    <ul>
                        <li>{!! trans('hr-manager::help.feat_aw_cap_1') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_aw_cap_2') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_aw_cap_3') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_aw_cap_4') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_aw_cap_5') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_aw_cap_6') !!}</li>
                    </ul>

                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_aw_where') !!}
                    </div>
                </div>

                {{-- 3. Members & Players views --}}
                <div class="help-card">
                    <h3><i class="fas fa-user-friends"></i> {{ trans('hr-manager::help.feat_member_player_views') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_member_player_views_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_mpv_members_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_mpv_members_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_mpv_players_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_mpv_players_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_mpv_titles_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_mpv_titles_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_mpv_token_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_mpv_token_body') !!}</p>

                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_mpv_where') !!}
                    </div>
                </div>

                {{-- 4. Corp Health & classification --}}
                <div class="help-card">
                    <h3><i class="fas fa-heartbeat"></i> {{ trans('hr-manager::help.feat_corp_health_section') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_corp_health_section_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_ch_tiers_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_ch_tiers_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_ch_classifier_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_ch_classifier_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_ch_signals_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_ch_signals_body') !!}</p>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('hr-manager::help.feat_heads_up_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_ch_heads_up') !!}
                    </div>

                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_ch_where') !!}
                    </div>
                </div>

                {{-- Role & title alignment (SeAT vs in-game, main vs alts) --}}
                <div class="help-card">
                    <h3><i class="fas fa-user-tag"></i> {{ trans('hr-manager::help.feat_alignment_section') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_alignment_section_desc') !!}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.feat_alignment_missing') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_alignment_extra') !!}</li>
                    </ul>
                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_alignment_where') !!}
                    </div>
                </div>

                {{-- ESI token & scope coverage (active-key tracking) --}}
                <div class="help-card">
                    <h3><i class="fas fa-key"></i> {{ trans('hr-manager::help.feat_token_section') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_token_section_desc') !!}</p>
                    <h4>{{ trans('hr-manager::help.feat_token_loss_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_token_loss_body') !!}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.feat_tw_cap_1') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_tw_cap_2') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_tw_cap_3') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_tw_cap_4') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_tw_cap_5') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_tw_cap_6') !!}</li>
                    </ul>
                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_token_where') !!}
                    </div>
                </div>

                {{-- 5. Purge workflow --}}
                <div class="help-card">
                    <h3><i class="fas fa-user-times"></i> {{ trans('hr-manager::help.feat_purge_section') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_purge_section_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_capabilities') }}</h4>
                    <ul>
                        <li>{!! trans('hr-manager::help.feat_p_cap_1') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_p_cap_2') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_p_cap_3') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_p_cap_4') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_p_cap_5') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_p_cap_6') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_p_cap_7') !!}</li>
                    </ul>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('hr-manager::help.feat_heads_up_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_p_heads_up') !!}
                    </div>

                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_p_where') !!}
                    </div>
                </div>

                {{-- 6. Watchlist (blacklist + whitelist) --}}
                <div class="help-card">
                    <h3><i class="fas fa-clipboard-list"></i> {{ trans('hr-manager::help.feat_watchlist') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_watchlist_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_w_blacklist_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_w_blacklist_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_w_whitelist_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_w_whitelist_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_w_resolution_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_w_resolution_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_w_dossier_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_w_dossier_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_w_clear_account_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_w_clear_account_body') !!}</p>

                    <h4>
                        {{ trans('hr-manager::help.feat_w_altclaims_heading') }}
                        <span class="badge ml-1" style="background: rgba(40,167,69,0.28); color: #6ee7b7; border: 1px solid rgba(40,167,69,0.55); font-size: 0.78rem; font-weight: 700; padding: 3px 9px; vertical-align: middle;">1.0.1</span>
                    </h4>
                    <p>{!! trans('hr-manager::help.feat_w_altclaims_body') !!}</p>

                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_w_where') !!}
                    </div>
                </div>

                {{-- 7. History timeline --}}
                <div class="help-card">
                    <h3><i class="fas fa-history"></i> {{ trans('hr-manager::help.feat_history_section') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_history_section_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_h_event_types_heading') }}</h4>
                    <ul>
                        <li>{!! trans('hr-manager::help.feat_h_ev_1') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_h_ev_2') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_h_ev_3') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_h_ev_4') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_h_ev_5') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_h_ev_6') !!}</li>
                    </ul>

                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_h_where') !!}
                    </div>
                </div>

                {{-- 8. Public tracking + corp-join detection --}}
                <div class="help-card">
                    <h3><i class="fas fa-link"></i> {{ trans('hr-manager::help.feat_public_tracking_section') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_public_tracking_section_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_pt_tracking_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_pt_tracking_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_pt_join_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_pt_join_body') !!}</p>

                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_pt_where') !!}
                    </div>
                </div>

                {{-- 9. Notifications --}}
                <div class="help-card">
                    <h3><i class="fas fa-bell"></i> {{ trans('hr-manager::help.feat_notifications_section') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_notifications_section_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_capabilities') }}</h4>
                    <ul>
                        <li>{!! trans('hr-manager::help.feat_n_cap_1') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_n_cap_2') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_n_cap_3') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_n_cap_4') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_n_cap_5') !!}</li>
                        <li>{!! trans('hr-manager::help.feat_n_cap_6') !!}</li>
                    </ul>

                    <div class="info-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <strong>{{ trans('hr-manager::help.feat_where_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_n_where') !!}
                    </div>
                </div>

                {{-- 10. Cross-plugin integrations --}}
                <div class="help-card">
                    <h3><i class="fas fa-plug"></i> {{ trans('hr-manager::help.feat_integrations_section') }}</h3>
                    <p>{!! trans('hr-manager::help.feat_integrations_section_desc') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_i_cwm_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_i_cwm_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_i_mm_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_i_mm_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_i_mc_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_i_mc_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_i_bp_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_i_bp_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_i_buyback_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_i_buyback_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_i_sm_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_i_sm_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_i_broadcast_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_i_broadcast_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_i_connector_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_i_connector_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.feat_i_zkill_heading') }}</h4>
                    <p>{!! trans('hr-manager::help.feat_i_zkill_body') !!}</p>

                    <div class="success-box">
                        <i class="fas fa-check-circle"></i>
                        <strong>{{ trans('hr-manager::help.feat_i_standalone_label') }}:</strong>
                        {!! trans('hr-manager::help.feat_i_standalone_body') !!}
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 RECRUITMENT SITE
                 ============================================================ --}}
            <div id="recruitment" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-bullhorn"></i> {{ trans('hr-manager::help.recruitment_title') }}</h3>
                    <p>{!! trans('hr-manager::help.recruitment_intro') !!}</p>

                    <h4>{{ trans('hr-manager::help.url_pattern_title') }}</h4>
                    <pre><code>/recruit/{corp_ticker}/{slug}</code></pre>
                    <p>{!! trans('hr-manager::help.url_pattern_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.visual_templates_title') }}</h4>
                    <ul>
                        <li><strong>Classic</strong>: {!! trans('hr-manager::help.tpl_classic_desc') !!}</li>
                        <li><strong>Showcase</strong>: {!! trans('hr-manager::help.tpl_showcase_desc') !!}</li>
                        <li><strong>Minimal</strong>: {!! trans('hr-manager::help.tpl_minimal_desc') !!}</li>
                        <li><strong>Industrial</strong>: {!! trans('hr-manager::help.tpl_industrial_desc') !!}</li>
                    </ul>

                    <h4>{{ trans('hr-manager::help.post_submission_title') }}</h4>
                    <ul>
                        <li><strong>Discord invite</strong>: {!! trans('hr-manager::help.psm_discord_desc') !!}</li>
                        <li><strong>SeAT Connector</strong>: {!! trans('hr-manager::help.psm_connector_desc') !!}</li>
                        <li><strong>Custom</strong>: {!! trans('hr-manager::help.psm_custom_desc') !!}</li>
                        <li><strong>None</strong>: {!! trans('hr-manager::help.psm_none_desc') !!}</li>
                    </ul>

                    <div class="info-box info-box-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('hr-manager::help.psm_connector_perm_title') }}</strong>
                        <p>{!! trans('hr-manager::help.psm_connector_perm_body') !!}</p>
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('hr-manager::help.override_title') }}:</strong>
                        <p>{!! trans('hr-manager::help.override_body') !!}</p>
                        <pre><code>php artisan vendor:publish --tag=hr-manager-recruit-views</code></pre>
                    </div>
                </div>

                <div class="help-card">
                    <h3><i class="fas fa-filter"></i> {{ trans('hr-manager::help.eligibility_title') }}</h3>
                    <p>{!! trans('hr-manager::help.eligibility_intro') !!}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.elig_sec_status') !!}</li>
                        <li>{!! trans('hr-manager::help.elig_total_sp') !!}</li>
                        <li>{!! trans('hr-manager::help.elig_age_days') !!}</li>
                        <li>{!! trans('hr-manager::help.elig_blacklist_corps') !!}</li>
                        <li>{!! trans('hr-manager::help.elig_whitelist_alliances') !!}</li>
                        <li>{!! trans('hr-manager::help.elig_connector') !!}</li>
                    </ul>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::help.elig_escape_hatch') !!}
                    </div>
                </div>

                {{-- =====================================================
                     Discord roles — handled by your SeAT Connector.
                     ===================================================== --}}
                <div class="help-card">
                    <h3><i class="fab fa-discord"></i> {{ trans('hr-manager::help.discord_connector_title') }}</h3>
                    <p>{!! trans('hr-manager::help.discord_connector_intro') !!}</p>

                    <div class="info-box info-box-success">
                        <h4><i class="fas fa-users"></i> {{ trans('hr-manager::help.discord_connector_members_title') }}</h4>
                        <p>{!! trans('hr-manager::help.discord_connector_members_body') !!}</p>
                    </div>

                    <div class="info-box">
                        <h4><i class="fas fa-link"></i> {{ trans('hr-manager::help.discord_connector_applicants_title') }}</h4>
                        <p>{!! trans('hr-manager::help.discord_connector_applicants_body') !!}</p>
                        <ol class="step-by-step">
                            <li>{!! trans('hr-manager::help.discord_connector_applicants_step1') !!}</li>
                            <li>{!! trans('hr-manager::help.discord_connector_applicants_step2') !!}</li>
                            <li>{!! trans('hr-manager::help.discord_connector_applicants_step3') !!}</li>
                        </ol>
                    </div>

                    <div class="info-box info-box-purple">
                        <h4><i class="fas fa-user-tag"></i> {{ trans('hr-manager::help.discord_connector_prospect_title') }}</h4>
                        <p>{!! trans('hr-manager::help.discord_connector_prospect_body') !!}</p>
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 APPLICATIONS
                 ============================================================ --}}
            <div id="applications" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-file-alt"></i> {{ trans('hr-manager::help.applications_title') }}</h3>
                    <p>{!! trans('hr-manager::help.applications_intro') !!}</p>

                    <h4>{{ trans('hr-manager::help.workflow_title') }}</h4>
                    <ol class="step-by-step">
                        <li><span class="badge badge-hr badge-applied">Applied</span> - {{ trans('hr-manager::help.status_applied_desc') }}</li>
                        <li><span class="badge badge-hr badge-under-review">Under Review</span> - {{ trans('hr-manager::help.status_under_review_desc') }}</li>
                        <li><span class="badge badge-hr badge-interview">Interview</span> - {{ trans('hr-manager::help.status_interview_desc') }}</li>
                        <li><span class="badge badge-hr badge-accepted">Accepted</span> - {{ trans('hr-manager::help.status_accepted_desc') }}</li>
                        <li><span class="badge badge-hr badge-rejected">Rejected</span> - {{ trans('hr-manager::help.status_rejected_desc') }}</li>
                        <li><span class="badge badge-hr badge-withdrawn">Withdrawn</span> - {{ trans('hr-manager::help.status_withdrawn_desc') }}</li>
                    </ol>
                    <p>{!! trans('hr-manager::help.transitions_body') !!}</p>
                </div>

                <div class="help-card">
                    <h3><i class="fas fa-clipboard-list"></i> {{ trans('hr-manager::help.templates_title') }}</h3>
                    <p>{!! trans('hr-manager::help.templates_intro') !!}</p>
                    <h4>{{ trans('hr-manager::help.question_types_title') }}</h4>
                    <ul>
                        <li><strong>Text</strong>: {{ trans('hr-manager::help.type_text') }}</li>
                        <li><strong>Textarea</strong>: {{ trans('hr-manager::help.type_textarea') }}</li>
                        <li><strong>Select</strong>: {{ trans('hr-manager::help.type_select') }}</li>
                        <li><strong>Checkbox</strong>: {{ trans('hr-manager::help.type_checkbox') }}</li>
                        <li><strong>Radio</strong>: {{ trans('hr-manager::help.type_radio') }}</li>
                        <li><strong>Number</strong>: {{ trans('hr-manager::help.type_number') }}</li>
                        <li><strong>URL</strong>: {{ trans('hr-manager::help.type_url') }}</li>
                    </ul>

                    <div class="info-box">
                        <i class="fas fa-lock"></i>
                        <strong>{{ trans('hr-manager::help.template_lock_title') }}</strong>
                        <p>{!! trans('hr-manager::help.template_lock_body') !!}</p>
                    </div>
                </div>

                {{-- =====================================================
                     Recruiter Access — temporary SeAT role grants.
                     ===================================================== --}}
                <div class="help-card">
                    <h3><i class="fas fa-key"></i> {{ trans('hr-manager::help.access_title') }}</h3>
                    <p>{!! trans('hr-manager::help.access_intro') !!}</p>

                    <h4>{{ trans('hr-manager::help.access_lifecycle_title') }}</h4>
                    <ol class="step-by-step">
                        <li>{!! trans('hr-manager::help.access_lifecycle_join') !!}</li>
                        <li>{!! trans('hr-manager::help.access_lifecycle_use') !!}</li>
                        <li>{!! trans('hr-manager::help.access_lifecycle_close') !!}</li>
                        <li>{!! trans('hr-manager::help.access_lifecycle_sweep') !!}</li>
                    </ol>
                    <p>{!! trans('hr-manager::help.access_grant_now_note') !!}</p>

                    <h4>{{ trans('hr-manager::help.access_setup_title') }}</h4>
                    <p>{!! trans('hr-manager::help.access_setup_body') !!}</p>
                    <ol>
                        <li>{!! trans('hr-manager::help.access_setup_step1') !!}</li>
                        <li>{!! trans('hr-manager::help.access_setup_step2') !!}</li>
                        <li>{!! trans('hr-manager::help.access_setup_step3') !!}</li>
                        <li>{!! trans('hr-manager::help.access_setup_step4') !!}</li>
                    </ol>

                    <h4>{{ trans('hr-manager::help.access_permissions_title') }}</h4>
                    <p>{!! trans('hr-manager::help.access_permissions_body') !!}</p>
                    <table class="table table-dark">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::help.access_perm_col') }}</th>
                                <th>{{ trans('hr-manager::help.access_what_col') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><code>character.sheet</code></td><td>{{ trans('hr-manager::help.access_perm_sheet') }}</td></tr>
                            <tr><td><code>character.journal</code></td><td>{{ trans('hr-manager::help.access_perm_journal') }}</td></tr>
                            <tr><td><code>character.transactions</code></td><td>{{ trans('hr-manager::help.access_perm_transactions') }}</td></tr>
                            <tr><td><code>character.mail</code></td><td>{{ trans('hr-manager::help.access_perm_mail') }}</td></tr>
                            <tr><td><code>character.asset</code></td><td>{{ trans('hr-manager::help.access_perm_asset') }}</td></tr>
                            <tr><td><code>character.skill</code></td><td>{{ trans('hr-manager::help.access_perm_skill') }}</td></tr>
                            <tr><td><code>character.contract</code></td><td>{{ trans('hr-manager::help.access_perm_contract') }}</td></tr>
                            <tr><td><code>character.industry</code></td><td>{{ trans('hr-manager::help.access_perm_industry') }}</td></tr>
                            <tr><td><code>character.killmail</code></td><td>{{ trans('hr-manager::help.access_perm_killmail') }}</td></tr>
                            <tr><td><code>character.notification</code></td><td>{{ trans('hr-manager::help.access_perm_notification') }}</td></tr>
                        </tbody>
                    </table>
                    <small style="color: var(--hr-text-muted);">{!! trans('hr-manager::help.access_perms_footnote') !!}</small>

                    <h4 class="mt-3">{{ trans('hr-manager::help.access_safety_title') }}</h4>
                    <ul>
                        <li>{!! trans('hr-manager::help.access_safety_isolated') !!}</li>
                        <li>{!! trans('hr-manager::help.access_safety_scope') !!}</li>
                        <li>{!! trans('hr-manager::help.access_safety_namespace') !!}</li>
                        <li>{!! trans('hr-manager::help.access_safety_pivot') !!}</li>
                        <li>{!! trans('hr-manager::help.access_safety_concurrent') !!}</li>
                        <li>{!! trans('hr-manager::help.access_safety_audit') !!}</li>
                    </ul>

                    <div class="info-box info-box-warning">
                        <h4><i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::help.access_caveat_title') }}</h4>
                        <p>{!! trans('hr-manager::help.access_caveat_sso') !!}</p>
                        <p>{!! trans('hr-manager::help.access_caveat_manual') !!}</p>
                    </div>

                    <h4>{{ trans('hr-manager::help.access_recruiter_ux_title') }}</h4>
                    <p>{!! trans('hr-manager::help.access_recruiter_ux_body') !!}</p>
                </div>

                {{-- =====================================================
                     Activity Log — director-only accountability trail.
                     ===================================================== --}}
                <div class="help-card">
                    <h3><i class="fas fa-history"></i> {{ trans('hr-manager::help.audit_help_title') }}</h3>
                    <p>{!! trans('hr-manager::help.audit_help_intro') !!}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.audit_help_views') !!}</li>
                        <li>{!! trans('hr-manager::help.audit_help_actions') !!}</li>
                        <li>{!! trans('hr-manager::help.audit_help_privilege') !!}</li>
                        <li>{!! trans('hr-manager::help.audit_help_decision') !!}</li>
                        <li>{!! trans('hr-manager::help.audit_help_notification') !!}</li>
                    </ul>
                    <div class="info-box">
                        <h4><i class="fas fa-info-circle"></i> {{ trans('hr-manager::help.audit_help_retention_title') }}</h4>
                        <p>{!! trans('hr-manager::help.audit_help_retention_body') !!}</p>
                    </div>
                </div>

                {{-- =====================================================
                     SSO & Scopes — which scope profile the funnel uses.
                     ===================================================== --}}
                <div class="help-card">
                    <h3><i class="fas fa-id-badge"></i> {{ trans('hr-manager::help.sso_help_title') }}</h3>
                    <p>{!! trans('hr-manager::help.sso_help_intro') !!}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.sso_help_broken') !!}</li>
                        <li>{!! trans('hr-manager::help.sso_help_minimal') !!}</li>
                        <li>{!! trans('hr-manager::help.sso_help_full') !!}</li>
                    </ul>
                    <p>{!! trans('hr-manager::help.sso_help_optional') !!}</p>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::help.sso_help_note') !!}
                    </div>

                    <div class="info-box info-box-warning">
                        <h4><i class="fas fa-triangle-exclamation"></i> {{ trans('hr-manager::help.sso_overwrite_title') }}</h4>
                        <p>{!! trans('hr-manager::help.sso_overwrite_body') !!}</p>
                    </div>

                    <h4>{{ trans('hr-manager::help.sso_updates_title') }}</h4>
                    <p>{!! trans('hr-manager::help.sso_updates_body') !!}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.sso_updates_buckets') !!}</li>
                        <li>{!! trans('hr-manager::help.sso_updates_tokens') !!}</li>
                        <li>{!! trans('hr-manager::help.sso_updates_recruiter') !!}</li>
                        <li>{!! trans('hr-manager::help.sso_updates_caveat') !!}</li>
                    </ul>
                </div>

                {{-- Applicant Assessment (recruiter intel on the application view) --}}
                <div class="help-card">
                    <h3><i class="fas fa-user-check"></i> Applicant Assessment</h3>
                    <p>Every application opens with an automated <strong>assessment</strong> panel: a green / amber / red verdict plus the signals behind it. It's <strong>intel for the recruiter, not a gate</strong>: eligibility rules still decide who can apply; the assessment just surfaces what's worth a second look.</p>
                    <p><strong>Always-on signals</strong> (no extra scopes, from public + already-synced data):</p>
                    <ul>
                        <li><strong>Corp-hopping</strong>: many corporations joined in a short window, or a low average tenure (instability, or a possible intel alt).</li>
                        <li><strong>NPC-corp parking</strong>: currently sitting in an NPC corp a long time (inactive, or parked for intel).</li>
                        <li><strong>Character age</strong> and <strong>security status</strong> for context.</li>
                        <li><strong>Watchlist cross-check</strong>: a hit on your blacklist is the one hard red flag.</li>
                        <li><strong>zKillboard</strong> PvP summary (kills / losses / danger ratio).</li>
                    </ul>
                    <p><strong>Progressive signals</strong> (light up only when the applicant granted the optional scope):</p>
                    <ul>
                        <li><strong>Skill points</strong> (skills scope) against a guideline.</li>
                        <li><strong>Implants</strong> (clones scope): an established main vs a throwaway clean clone.</li>
                        <li><strong>Current-corp roles</strong> (corp-roles scope): a Director elsewhere is flagged amber (awox / intel risk + seniority).</li>
                        <li><strong>Standings</strong> (contacts scope): flags an applicant <strong>blue to an entity you rate badly</strong>. Configured in <strong>Settings &rarr; Standings</strong> (see below). Inert until you pick a source.</li>
                    </ul>
                    <p>Tune the thresholds in <strong>Settings &rarr; Assessment</strong> (hopper count, NPC-park days, min tenure, age, SP, security floor). The optional scopes themselves are requested through your <strong>recruitment SSO profile</strong> (Settings &rarr; SSO &amp; Scopes lists which are present, alongside the optional intel-scope tier).</p>
                </div>

                {{-- Standings — own settings tab since 1.0.2, and now numeric --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-handshake-slash"></i> Standings
                        <span class="badge ml-1" style="background: rgba(40,167,69,0.28); color: #6ee7b7; border: 1px solid rgba(40,167,69,0.55); font-size: 0.78rem; font-weight: 700; padding: 3px 9px; vertical-align: middle;">1.0.2</span>
                    </h3>
                    <p><strong>Settings &rarr; Standings</strong> holds your corp's view of who is hostile <em>and how hostile</em>, on EVE's own scale: <strong>-10 terrible, -5 bad, 0 neutral, +5 good, +10 excellent</strong>. It feeds the assessment's contact check, and anything else in HR that needs to know what you think of an entity.</p>
                    <p>Standings used to be a sub-form on the Assessment tab holding four flat lists of IDs, which could only ever answer <em>hostile: yes or no</em>. They now carry a real value, and have their own tab.</p>

                    <h4>Where the values come from</h4>
                    <ul>
                        <li><strong>Off</strong> — nothing is rated and the standings check does not run.</li>
                        <li><strong>SeAT</strong> — a Standings Builder profile (SeAT's own <em>Tools &rarr; Standings</em>), used exactly as it stands.</li>
                        <li><strong>HR</strong> — the list on this tab only.</li>
                        <li><strong>Hybrid</strong> — SeAT as the baseline with HR overriding it <em>per entity</em>. This is the useful shape for most corps: you are not maintaining a parallel list, only the handful of entities where your corp's view differs from your alliance's. Overrides work <strong>downward</strong> too, so something your alliance rates terrible can be set neutral locally and stop generating flags.</li>
                    </ul>
                    <p>Two precedences, and they are easy to confuse. The <strong>source</strong> precedence is HR over SeAT, and only applies in hybrid mode. The <strong>conflict</strong> precedence (also on this tab) is about one entity: when a corp and its alliance are rated differently, which verdict wins for a member of that corp.</p>

                    <h4>Building the list</h4>
                    <ul>
                        <li>Paste entities <strong>one per line</strong>, IDs and names mixed freely, and they all take the value you pick. Names must match exactly (case does not matter) — a standings list built from fuzzy matches would be quietly wrong in exactly the way that matters.</li>
                        <li>Type is <strong>auto-detected</strong>. Pick one manually only for IDs that cannot be looked up, for instance while ESI is unreachable. If you pick a type and the entity turns out to be something else, it is filed under what it really is and the save message tells you — an entry filed under the wrong category silently never matches anything.</li>
                        <li>Adding an entity that is already listed <strong>changes its value</strong> rather than duplicating it.</li>
                        <li>Select rows to <strong>revalue or remove in bulk</strong>. The filter box narrows the table, and "select all" only takes the rows currently shown.</li>
                        <li>In hybrid mode each row shows the <strong>SeAT baseline</strong> beside your value, marked when you have overridden it.</li>
                    </ul>

                    <h4>Upgrading from the old lists</h4>
                    <p>Your four existing lists were carried over automatically the first time HR ran after the update: <strong>hostile entries became -10</strong> and <strong>friendly entries became +10</strong>, on the reasoning that typing an entity into a list called <em>hostile</em> is a deliberate act rather than a shrug. Nothing you had set by hand was overwritten, and the old settings were left in place untouched. Entries arrive with an ID and no name; the <strong>Resolve missing names</strong> button on the tab fills them in.</p>
                </div>

                {{-- Donation flags --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-user-secret"></i> Donation flags
                        <span class="badge ml-1" style="background: rgba(40,167,69,0.28); color: #6ee7b7; border: 1px solid rgba(40,167,69,0.55); font-size: 0.78rem; font-weight: 700; padding: 3px 9px; vertical-align: middle;">1.0.2</span>
                    </h3>
                    <p>Once standings are set up, HR can watch for <strong>direct ISK transfers</strong> between your members and entities you rate badly. Off by default; the switch is on the <strong>Standings</strong> tab, because the check is meaningless without a standings source.</p>

                    <h4>What counts</h4>
                    <p>Only <code>player_donation</code>: a wallet-to-wallet transfer with nothing given back. Contracts and market trades are deliberately excluded, because trading with a hostile entity is ordinary commerce and would bury the signal in noise. Someone handing ISK to an entity you rate terrible is a small, specific thing worth a look.</p>
                    <p>You set the <strong>ISK floor</strong> for each tier and <strong>which ratings count</strong> (terrible only, or bad and worse). Only the hostile steps are offered: flagging transfers to entities you rate neutral or better would flag ordinary trade.</p>

                    <h4>The two tiers</h4>
                    <ul>
                        <li><strong>Before they joined</strong> &mdash; a fact on the record, not something they did to you. Shown plainly, and usually worth a higher ISK floor to keep old noise out.</li>
                        <li><strong>Since they joined</strong> &mdash; highlighted in amber. This is the one that means something.</li>
                    </ul>
                    <p>The join date is <strong>account-level</strong>, taken the same way the profile takes tenure: the longest current stint across the whole account. Someone who joined on their main a year ago and brings an alt in today has been inside for a year, and grading that alt's transfers as though they were a fresh recruit would read the timeline backwards. A transfer with no known join date stays in the lower tier, because guessing the accusing tier from missing data is the wrong way to be wrong.</p>

                    <h4>Reading a flag</h4>
                    <p>Flags appear on the <strong>player profile</strong>, and they are an <strong>observation, not an accusation</strong>. Members trade with people the corp dislikes for entirely ordinary reasons; the value is in seeing a pattern, not in any single row. A character is rarely on a standings list by name, so where the rating was inherited the badge says whether it came from their <em>corp</em> or their <em>alliance</em>.</p>
                    <p>Each row also freezes <strong>what HR knew when it looked</strong>. EVE's API exposes who someone is affiliated with <em>now</em> and never who they flew for on the day of a transfer, so a standing resolved today cannot honestly be re-applied to a two-year-old donation. Rather than silently rewriting its own history every time an alliance changes hands, a flag records the value it was judged against and when that judgement was made.</p>

                    <h4>Cost, and the first run</h4>
                    <p>A nightly scan writes the findings and every surface reads them. Nothing touches the wallet journal on a page render, which on a real corp is one of the largest tables SeAT holds. Each pass reads only journal rows newer than the last one, so the steady state is a handful of indexed queries per member with a wallet token.</p>
                    <p>The exception is the <strong>first pass after switching it on</strong>, which reads each member's journal from the beginning once. On a large corp that takes a while. Run <code>hr-manager:scan-donations</code> by hand to start it, and pass <code>--limit</code> to spread it over several nights. The tab shows how many members are still on their first pass, so a half-built list is never mistaken for a finished one.</p>

                    <h4>When you change the rules</h4>
                    <p>The scan never revisits a transfer it has already declined to flag, which is what keeps it cheap. So changing what counts <strong>rebuilds</strong> rather than patches: raise a floor or widen which ratings count, and the existing findings are cleared so the next scan re-derives everything under the new rules. Otherwise one list would hold findings from two different sets of criteria with no way to tell which row followed which.</p>
                    <p>Editing the <strong>standings list itself</strong> has the same effect but happens on a different form, so HR cannot detect it. Use <strong>Rescan all history</strong> on the Standings tab after adding entities you want applied to past transfers.</p>
                    <p>If ESI is unreachable mid-scan and a counterparty cannot be identified, the scan <strong>holds its position</strong> for that member and retries on the next pass, rather than moving past transfers it was never able to judge.</p>
                </div>
            </div>

            {{-- ============================================================
                 NEW-MEMBER ONBOARDING  (1.0.1)
                 Sits right after Applications: it is the step immediately
                 after someone is accepted and joins, and the only
                 MEMBER-facing thing HR does — every other notification
                 category is written for staff.
                 ============================================================ --}}
            <div id="onboarding" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-hand-holding-heart"></i> {{ trans('hr-manager::help.onboarding_title') }}
                        <span class="badge ml-1" style="background: rgba(40,167,69,0.28); color: #6ee7b7; border: 1px solid rgba(40,167,69,0.55); font-size: 0.78rem; font-weight: 700; padding: 3px 9px; vertical-align: middle;">1.0.1</span>
                    </h3>
                    <p>{!! trans('hr-manager::help.onboarding_intro') !!}</p>

                    <h4>{{ trans('hr-manager::help.onboarding_who_title') }}</h4>
                    <p>{!! trans('hr-manager::help.onboarding_who_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.onboarding_when_title') }}</h4>
                    <p>{!! trans('hr-manager::help.onboarding_when_body') !!}</p>

                    <h4>{{ trans('hr-manager::help.onboarding_setup_title') }}</h4>
                    <ol>
                        <li>{!! trans('hr-manager::help.onboarding_setup_1') !!}</li>
                        <li>{!! trans('hr-manager::help.onboarding_setup_2') !!}</li>
                        <li>{!! trans('hr-manager::help.onboarding_setup_3') !!}</li>
                        <li>{!! trans('hr-manager::help.onboarding_setup_4') !!}</li>
                    </ol>

                    <h4>{{ trans('hr-manager::help.onboarding_template_title') }}</h4>
                    <p>{!! trans('hr-manager::help.onboarding_template_body') !!}</p>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::help.onboarding_length_note') !!}
                    </div>

                    <div class="info-box">
                        <i class="fas fa-question-circle"></i>
                        {!! trans('hr-manager::help.onboarding_troubleshoot') !!}
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 ACTIVITY TIERS
                 ============================================================ --}}
            <div id="tiers" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-layer-group"></i> {{ trans('hr-manager::help.tiers_title') }}</h3>
                    <p>{!! trans('hr-manager::help.tiers_intro') !!}</p>

                    <div class="info-box info-box-warning">
                        <i class="fas fa-plug"></i>
                        <strong>Tier auto-resolution requires SeAT Connector.</strong> Mapping a Discord role to a tier only auto-resolves with the SeAT Connector framework installed (<code>warlof/seat-connector</code> + a Discord driver). <strong>If you don't use SeAT Connector, you can skip tier mapping entirely</strong> — assign tiers by hand, or leave everyone at the <code>L0 Member</code> default. The classifier and the rest of Corp Health work regardless; the only difference is the tier column reads <em>Unmapped</em> until you set one.
                    </div>

                    <table class="table table-dark">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::help.col_tier') }}</th>
                                <th>{{ trans('hr-manager::help.col_label') }}</th>
                                <th>{{ trans('hr-manager::help.col_default_threshold') }}</th>
                                <th>{{ trans('hr-manager::help.col_description') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><code>L-1</code></td><td>Applicant</td><td>{{ trans('hr-manager::help.no_threshold') }}</td><td>{{ trans('hr-manager::help.tier_applicant_desc') }}</td></tr>
                            <tr><td><code>L0</code></td><td>Member</td><td>90 days</td><td>{{ trans('hr-manager::help.tier_member_desc') }}</td></tr>
                            <tr><td><code>L1</code></td><td>Junior Officer</td><td>30 days</td><td>{{ trans('hr-manager::help.tier_junior_desc') }}</td></tr>
                            <tr><td><code>L2</code></td><td>Senior Officer</td><td>14 days</td><td>{{ trans('hr-manager::help.tier_senior_desc') }}</td></tr>
                            <tr><td><code>L3</code></td><td>Director</td><td>14 days</td><td>{{ trans('hr-manager::help.tier_director_desc') }}</td></tr>
                        </tbody>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::help.tier_resolution_body') !!}
                    </div>
                </div>

                {{-- Why map, what it affects, and WHEN it takes effect. --}}
                <div class="help-card">
                    <h3><i class="fas fa-link"></i> Why map Discord roles to tiers &mdash; and when it takes effect</h3>

                    <p><strong>What the mapping is for.</strong> A tier is really just an <strong>inactivity expectation</strong>. A Director who goes quiet for two weeks is a corp-survival risk; a casual member idle for two weeks is fine. Mapping your Discord roles to tiers lets the classifier hold each person to the <em>right</em> standard automatically &mdash; without a mapping, everyone is measured against the <code>L0 Member</code> default (90 days), so officers and directors who should be watched closely look "active" far too long.</p>

                    <p><strong>What a mapping changes:</strong></p>
                    <ul>
                        <li>The <strong>tier badge</strong> (e.g. <code>L3 Director &middot; 7d</code>) shown on the player and Members views, and the <strong>sort order</strong> of the Access tab.</li>
                        <li>The <strong>inactivity threshold</strong> used to bucket a member into <em>Active / At Risk / Inactive / Dead Weight</em>.</li>
                        <li>Whether a director triggers the <strong>inactive-director alert</strong> (only an <code>L3</code> who is genuinely past their threshold).</li>
                        <li>When someone holds several mapped roles, they take the <strong>highest tier</strong> for their badge but the <strong>strictest threshold</strong> across all of them.</li>
                    </ul>

                    <p><strong>When the plugin applies it &mdash; two different clocks:</strong></p>
                    <ul>
                        <li>The <strong>tier badge is resolved live</strong> on every page load, so a mapping you just saved shows on the badge <em>immediately</em>.</li>
                        <li>The <strong>Active / Inactive bucket</strong> (and the threshold actually applied to it) comes from the stored classification, which only updates when the classifier runs: <strong>nightly at 02:00</strong>, or on demand via <strong>Corp Health &rarr; Run now</strong>. So right after adding a mapping you may briefly see the new tier on the badge while the bucket still reflects the old one, until a classify run reconciles them.</li>
                    </ul>

                    <div class="info-box info-box-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Ran <code>init</code> before setting up your mappings?</strong> Then the stored classifications were computed against the un-mapped (L0) tiers. You do <strong>not</strong> need to run a full <code>init</code> again &mdash; just re-classify, any of:
                        <ul class="mb-0 mt-1">
                            <li><strong>Corp Health &rarr; Run now</strong> &mdash; re-tiers the corp you're viewing, instantly.</li>
                            <li><code>php artisan hr-manager:classify-players</code> &mdash; re-tiers every corp (or add <code>--corporation_id=ID</code> for one).</li>
                            <li><code>php artisan hr-manager:init --corporation="Corp Name"</code> &mdash; re-runs the assessment + classification for just that corp (accepts a name, ticker, or numeric ID). Handy when you also want the assessment cache and profile pre-warm refreshed for that corp, not only the classification.</li>
                            <li>Do nothing &mdash; the <strong>nightly 02:00 run</strong> picks up the mappings on its own.</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 CORP HEALTH
                 ============================================================ --}}
            <div id="corp-health" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-heartbeat"></i> {{ trans('hr-manager::help.corp_health_title') }}</h3>
                    <p>{!! trans('hr-manager::help.corp_health_intro') !!}</p>

                    <h4>{{ trans('hr-manager::help.categories_title') }}</h4>
                    <ul>
                        <li><strong>Active</strong>: {{ trans('hr-manager::help.cat_active_desc') }}</li>
                        <li><strong>At Risk</strong>: {{ trans('hr-manager::help.cat_at_risk_desc') }}</li>
                        <li><strong>Inactive</strong>: {{ trans('hr-manager::help.cat_inactive_desc') }}</li>
                        <li><strong>Dead Weight</strong>: {{ trans('hr-manager::help.cat_dead_weight_desc') }}</li>
                    </ul>

                    <h4>{{ trans('hr-manager::help.wallet_signals_title') }}</h4>
                    <p>{!! trans('hr-manager::help.wallet_signals_intro') !!}</p>
                    <ul>
                        <li><strong>STL</strong> Stalled: {{ trans('hr-manager::help.flag_stalled_desc') }}</li>
                        <li><strong>NEG</strong> Negative contribution: {{ trans('hr-manager::help.flag_negative_desc') }}</li>
                        <li><strong>TAX</strong> Compliance &lt; 50%: {{ trans('hr-manager::help.flag_tax_desc') }}</li>
                        <li><strong>VTX</strong> Compliance &lt; 30%: {{ trans('hr-manager::help.flag_vtx_desc') }}</li>
                        <li><strong>SWD</strong> Silent wallet director: {{ trans('hr-manager::help.flag_swd_desc') }}</li>
                        <li><strong>LYL</strong> Loyalty hold: {{ trans('hr-manager::help.flag_loyalty_desc') }}</li>
                    </ul>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>{{ trans('hr-manager::help.inactive_director_label') }}:</strong>
                        {!! trans('hr-manager::help.inactive_director_body') !!}
                    </div>
                </div>

                {{-- Structure Compliance (provided by Structure Manager) --}}
                <div class="help-card">
                    <h3><i class="fas fa-clipboard-check"></i> Structure Compliance <span style="font-size:0.7rem; opacity:0.7;">(needs Structure Manager)</span></h3>
                    <p>The <strong>Structure Compliance</strong> tab compares your corp's Upwell structures against the alliance-recommended fit (rigs + service modules) for each structure type and security band, verdicting each one <strong>Compliant / Compliant + upgraded / Partial / Non-compliant / No doctrine / No data</strong> with a slot-by-slot diff and Copy / Appraise buttons.</p>
                    <p>This feature is <strong>owned by Structure Manager</strong>: HR reads its report through Manager Core's PluginBridge and renders it here. <strong>Manage doctrines</strong> opens Structure Manager, where the recommended fits are defined. When Structure Manager isn't installed, the tab shows a "Structure Manager required" notice. Director-tier.</p>
                </div>

                {{-- Membership changes (Corp Health → Membership tab) --}}
                <div class="help-card">
                    <h3><i class="fas fa-user-plus"></i> Membership changes <span style="font-size:0.7rem; opacity:0.7;">(director-tier)</span></h3>
                    <p>The <strong>Membership</strong> tab logs corp roster changes detected by <code>hr-manager:detect-membership-changes</code> (every 30 minutes). It is <strong>forward-only</strong>: the first scan of a corp seeds the roster silently, so existing members are never flagged — only later joins and leaves appear. With <strong>Manager Core</strong> installed, joins and <em>voluntary</em> leaves are also picked up ~2 minutes from the director ESI notification feed (<code>CorpAppAcceptMsg</code> / <code>CharLeftCorpMsg</code>) rather than waiting for the diff; a kick sends no EVE notification, so those stay on the 30-minute diff, which dedups the fast path so nothing fires twice.</p>
                    <p>Each join is classified: an <strong>alt of a current member</strong> (the message names the main), a member with a <strong>valid application</strong>, a newcomer who joined with <strong>no valid application</strong> (security), or — most severe — a character with <strong>no SeAT account at all</strong> (<strong>unregistered</strong>: no token, no ESI data, no visibility). The no-application joins form a <strong>review queue</strong> a director acknowledges with an optional note (the tab carries a red unreviewed count). Unregistered members appear in a <strong>Pending registration</strong> list that <strong>auto-clears</strong> the moment they register the character — as their own main, or as an alt of any account. These map to the Member Joined / Member Left / Joined Without Application / Joined Unregistered webhook categories.</p>
                </div>

                {{-- Access depth (Corp Health → Access tab + profile boxes) --}}
                <div class="help-card">
                    <h3><i class="fas fa-key"></i> Access depth <span style="font-size:0.7rem; opacity:0.7;">(director-tier)</span></h3>
                    <p>The <strong>Access</strong> tab answers a single question: <em>who holds real power in this corp, and is any of it sitting on someone who has checked out?</em> It's <strong>one row per registered account, with that person's SeAT access and Discord access side by side</strong>, sorted by activity tier (highest first; accounts with no tier mapping fall back to superuser → in-game Director → HR grant → the rest). <strong>SeAT access</strong> lists everyone with elevated access — <strong>superusers</strong> (the <code>users.admin</code> flag), <strong>in-game Directors</strong> (from the corp director token), explicit <strong>HR Director / Admin</strong> grants, and <strong>every SeAT role each account holds</strong>. Roles are detected from <strong>both</strong> grant paths SeAT honours: a role assigned directly through <em>Access Management</em>, and a role carried by a <strong>squad</strong> the member belongs to. Squad-granted roles are shown in <strong>teal</strong> with a users icon and a "via squad: name" tooltip; directly-assigned roles are gray. A legend spells this out, and the <em>via squad</em> / <em>direct</em> count badges filter the list by grant path — so you can see at a glance whether your access comes through squads or through direct Access Management assignments. HR Director/Admin badges likewise include permissions inherited through a squad — so if you grant access via squads, it shows up here. <strong>Discord access</strong> lists every linked member with <strong>every</strong> Discord role they hold.</p>
                    <p>Both are cross-referenced against the classifier, so the tab <strong>leads with risk call-outs</strong>: a superuser, Director, or sensitive-Discord-role holder who is currently <em>At Risk / Inactive / Dead Weight</em> is the classic dormant-with-power gap, and it's shown first. Every row links to the player profile to act on it. <strong>Click any of the count badges</strong> at the top (superusers, HR directors, sensitive-role holders, and so on) to filter the list down to just those people; click it again or hit <em>Clear filter</em> to reset. Long rosters are paginated (25 / 50 / 100 / 250 / 500 per page). The <strong>Alignment</strong> tab works the same way — click <em>Aligned</em> or <em>Misaligned</em> to filter, with the same pagination.</p>
                    <p><strong>Mark which roles matter</strong> under <em>Settings → Features → Sensitive Discord roles</em>: tick the leadership / FC / wallet roles. Those show <strong>red</strong> in the Discord section (everything else stays neutral), and the same split appears per-person as a <strong>Discord access depth</strong> box on the player profile (beside the <strong>SeAT access depth</strong> box). One honest limit: SeAT only sees <strong>connector-managed</strong> Discord roles — manually-assigned roles and channel permissions are invisible to it, so this is the floor on Discord power, never the ceiling. The Discord section needs <strong>SeAT Connector</strong>; without it the SeAT section still works on its own.</p>
                    <p><strong>Where each Discord role comes from.</strong> HR resolves Discord roles the way SeAT Connector actually grants them — a role (a "set") tied to your <strong>user</strong>, your characters' <strong>corp / alliance</strong>, one of your <strong>SeAT roles</strong>, a <strong>squad</strong> you're in, or a <strong>public</strong> set. Hover any Discord role to see <em>why</em> the account holds it (<em>"via squad: Leadership"</em>, <em>"via SeAT role: Director"</em>, corp / alliance / direct / public); squad-tied roles get a squad icon. Read across a row and you can trace the whole <strong>SeAT squad → SeAT role → Discord role</strong> chain.</p>
                    <p><strong>Filtering, stacking &amp; gaps.</strong> Every count badge is a toggle — including <em>no Discord link</em> and <em>unregistered</em> — and they <strong>stack</strong> (click several to widen the view, click again or <em>Clear filter</em> to reset). <strong>Unregistered characters</strong> (in the corp, but with no SeAT account at all) are listed too, with blinking <em>"No SeAT account"</em> + <em>"No Discord link"</em> badges — so the biggest oversight gap is one click away, not just a number.</p>
                </div>

                {{-- Buyback contribution (Economy card + classifier signal; needs Buyback Manager) --}}
                <div class="help-card">
                    <h3><i class="fas fa-balance-scale"></i> Buyback contribution <span style="font-size:0.7rem; opacity:0.7;">(needs Buyback Manager)</span></h3>
                    <p>With Buyback Manager installed, the <strong>Economy</strong> tab carries a buyback card (ISK credited to the corp + top contributors) and the player profile a buyback panel. Each corp's buyback is valued by a <strong>per-corp policy</strong> (Settings → Buyback Contribution): <strong>Direct corp / Community / Personal</strong> with a weight, and an alt or holding corp's buyback can be credited to your main corp as support. A recent counted contribution also acts as a positive classifier signal — a <code>buyback_hold</code> badge that holds a borderline at-risk member at active, like the blueprint modifier.</p>
                </div>

                {{-- Wallet Insights cluster --}}
                <div class="help-card">
                    <h3><i class="fas fa-coins"></i> {{ trans('hr-manager::help.wi_title') }}</h3>
                    <p>{!! trans('hr-manager::help.wi_intro') !!}</p>

                    <table class="table table-dark">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::help.wi_card_col') }}</th>
                                <th>{{ trans('hr-manager::help.wi_what_col') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><i class="fas fa-fire text-danger"></i> <strong>{{ trans('hr-manager::corp-health.untaxed_earners_heading') }}</strong></td><td>{{ trans('hr-manager::help.wi_untaxed') }}</td></tr>
                            <tr><td><i class="fas fa-exclamation-triangle text-warning"></i> <strong>{{ trans('hr-manager::corp-health.wallet_anomalies_heading') }}</strong></td><td>{{ trans('hr-manager::help.wi_anomalies') }}</td></tr>
                            <tr><td><i class="fas fa-couch"></i> <strong>{{ trans('hr-manager::corp-health.freeloaders_heading') }}</strong></td><td>{{ trans('hr-manager::help.wi_freeloaders') }}</td></tr>
                            <tr><td><i class="fas fa-medal text-success"></i> <strong>{{ trans('hr-manager::corp-health.loyalty_heading') }}</strong></td><td>{{ trans('hr-manager::help.wi_loyalty') }}</td></tr>
                            <tr><td><i class="fas fa-sign-out-alt"></i> <strong>{{ trans('hr-manager::corp-health.outflows_heading') }}</strong></td><td>{{ trans('hr-manager::help.wi_outflows') }}</td></tr>
                        </tbody>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::help.wi_footnote') !!}
                    </div>
                </div>

                {{-- Character roles + FC activity --}}
                <div class="help-card">
                    <h3><i class="fas fa-user-tag"></i> {{ trans('hr-manager::help.roles_title') }}</h3>
                    <p>{!! trans('hr-manager::help.roles_intro') !!}</p>
                    <table class="table table-dark">
                        <thead><tr><th>{{ trans('hr-manager::help.roles_badge_col') }}</th><th>{{ trans('hr-manager::help.roles_signal_col') }}</th></tr></thead>
                        <tbody>
                            <tr><td><i class="fas fa-crosshairs"></i> <strong>Ratter</strong> / <i class="fas fa-scroll"></i> Mission Runner</td><td>{{ trans('hr-manager::help.roles_ratter') }}</td></tr>
                            <tr><td><i class="fas fa-gem"></i> <strong>Miner</strong></td><td>{{ trans('hr-manager::help.roles_miner') }}</td></tr>
                            <tr><td><i class="fas fa-balance-scale"></i> <strong>Trader</strong></td><td>{{ trans('hr-manager::help.roles_trader') }}</td></tr>
                            <tr><td><i class="fas fa-globe"></i> <strong>PI</strong> / <i class="fas fa-industry"></i> Industrialist</td><td>{{ trans('hr-manager::help.roles_industry') }}</td></tr>
                            <tr><td><i class="fas fa-skull-crossbones"></i> <strong>PvPer</strong></td><td>{{ trans('hr-manager::help.roles_pvper') }}</td></tr>
                            <tr><td><i class="fas fa-broadcast-tower"></i> <strong>FC</strong></td><td>{{ trans('hr-manager::help.roles_fc') }}</td></tr>
                        </tbody>
                    </table>
                    <div class="info-box info-box-success">
                        <h4><i class="fas fa-broadcast-tower"></i> {{ trans('hr-manager::help.fc_title') }}</h4>
                        <p>{!! trans('hr-manager::help.fc_body') !!}</p>
                        <p>{!! trans('hr-manager::help.fc_roster') !!}</p>
                    </div>
                    <p class="text-muted"><small>{!! trans('hr-manager::help.roles_standalone') !!}</small></p>
                </div>
            </div>

            {{-- ============================================================
                 PURGE WORKFLOW
                 ============================================================ --}}
            <div id="purge" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-user-times"></i> {{ trans('hr-manager::help.purge_title') }}</h3>
                    <p>{!! trans('hr-manager::help.purge_intro') !!}</p>

                    <h4>{{ trans('hr-manager::help.reminder_ladder_title') }}</h4>
                    <ol class="step-by-step">
                        <li><strong>T-7d</strong> - {{ trans('hr-manager::help.t7_desc') }}</li>
                        <li><strong>T-3d</strong> - {{ trans('hr-manager::help.t3_desc') }}</li>
                        <li><strong>T-48h</strong> - {{ trans('hr-manager::help.t48_desc') }}</li>
                        <li><strong>T-0</strong> - {{ trans('hr-manager::help.t0_desc') }}</li>
                    </ol>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        {!! trans('hr-manager::help.purge_no_auto_kick') !!}
                    </div>
                </div>

                <div class="help-card">
                    <h3><i class="fas fa-people-arrows"></i> Squad cleanup &amp; Discord roles <span style="font-size:0.7rem; opacity:0.7;">(timing)</span></h3>
                    <p>HR <strong>never touches Discord directly</strong>, and it <strong>never kicks anyone in-game</strong> (ESI can't). What it <em>can</em> do at purge time is clear a member's <strong>SeAT squads</strong> — and that is where Discord roles come off. If your <strong>SeAT Connector</strong> binds a Discord role to a SeAT squad, removing the member from that squad makes the Connector drop the bound Discord role automatically — it's SeAT's own native-kick cascade, exactly as if you kicked them from the squad by hand. Only <code>manual</code> / <code>hidden</code> squads are ever removed; <code>auto</code> squads and anything on your never-touch list are left alone.</p>

                    <h4>Two ways squads get cleared — and when</h4>
                    <table class="table table-dark">
                        <thead><tr><th>Mode</th><th>When it happens</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><strong>Manual button</strong><br><small class="text-muted">always available</small></td>
                                <td><strong>Immediately</strong>, when a director clicks <em>Remove from these squads</em> on the player profile or the purge board. Works whether or not auto cleanup is enabled.</td>
                            </tr>
                            <tr>
                                <td><strong>Opt-in auto cleanup</strong><br><small class="text-muted">Settings &rarr; Squad cleanup, off by default</small></td>
                                <td>Fired by <code>hr-manager:dispatch-purge-reminders</code> (every 12h) and stamped once per purge:<br>&bull; <strong>Immediately</strong> once the member is detected as having <strong>left the corp</strong> (no cancellation risk once they're gone); otherwise<br>&bull; at <strong>T-24h</strong> or <strong>T-12h</strong> before the kick date (your choice), so a scheduled purge never leaves stale Discord access behind.</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="info-box info-box-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>The in-game kick is still on you.</strong> Squad cleanup handles Discord (when Connector binds the roles), but ESI cannot remove a character from a corp — a director must do the actual kick. The T-48h / T-0 reminders list every in-corp character and their high-impact roles so nothing is missed.
                    </div>
                    <p class="text-muted"><small>No SeAT Connector, or no Discord roles bound to squads? Then squad cleanup just clears the SeAT squad membership with no Discord effect — you can ignore this entirely. Either way, every removal (manual or automatic) lands on the player's history timeline.</small></p>
                </div>
            </div>

            {{-- ============================================================
                 PLAYERS / MEMBERS
                 ============================================================ --}}
            <div id="players" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-user-friends"></i> {{ trans('hr-manager::help.players_title') }}</h3>
                    <p>{!! trans('hr-manager::help.players_intro') !!}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.players_summary_1') !!}</li>
                        <li>{!! trans('hr-manager::help.players_summary_2') !!}</li>
                        <li>{!! trans('hr-manager::help.players_summary_3') !!}</li>
                        <li>{!! trans('hr-manager::help.players_summary_4') !!}</li>
                        <li><strong>Two badge sets per character</strong>: alongside the <strong>in-corp / not-in-corp</strong> badge, each alt now carries a <strong>token status</strong> badge &mdash; a green key (<em>active</em>), amber (<em>expired / revoked</em>), or grey (<em>missing</em>). Lets a director spot an alt whose data has gone stale because its ESI token died. Characters with a revoked token stay listed (they don't vanish), so the token badge is meaningful.</li>
                    </ul>
                </div>

                <div class="help-card">
                    <h3><i class="fas fa-users"></i> {{ trans('hr-manager::help.members_title') }}</h3>
                    <p>{!! trans('hr-manager::help.members_intro') !!}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.members_features_1') !!}</li>
                        <li>{!! trans('hr-manager::help.members_features_2') !!}</li>
                        <li>{!! trans('hr-manager::help.members_features_3') !!}</li>
                        <li>{!! trans('hr-manager::help.members_features_4') !!}</li>
                        <li><strong>"View in SeAT" deep-links</strong>: one-click <em>Sheet / Wallet / Mail / Assets / Skills</em> buttons under the character header open SeAT's own character pages in a new tab. Unlike the recruiter-access panel on applications, <strong>no temporary grant is attached</strong> &mdash; the Members profile is already director-tier, and SeAT's own permission middleware governs each page on click. Shown for registered characters only.</li>
                    </ul>
                </div>
            </div>

            {{-- ============================================================
                 NOTES
                 ============================================================ --}}
            <div id="notes" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-sticky-note"></i> {{ trans('hr-manager::help.notes_title') }}</h3>
                    <p>{!! trans('hr-manager::help.notes_intro') !!}</p>

                    <h4>{{ trans('hr-manager::help.notes_scopes_title') }}</h4>
                    <ul>
                        <li><strong>Player</strong>: {{ trans('hr-manager::help.notes_scope_player') }}</li>
                        <li><strong>Member</strong>: {{ trans('hr-manager::help.notes_scope_member') }}</li>
                        <li><strong>Application</strong>: {{ trans('hr-manager::help.notes_scope_application') }}</li>
                    </ul>

                    <h4>{{ trans('hr-manager::help.privacy_title') }}</h4>
                    <p>{!! trans('hr-manager::help.privacy_body') !!}</p>
                </div>
            </div>

            {{-- ============================================================
                 NOTIFICATIONS
                 ============================================================ --}}
            <div id="notifications" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-bell"></i> {{ trans('hr-manager::help.notifications_title') }}</h3>
                    <p>{!! trans('hr-manager::help.notifications_intro') !!}</p>

                    <h4>{{ trans('hr-manager::help.toggle_categories_title') }}</h4>
                    <ul>
                        <li>{{ trans('hr-manager::help.toggle_app_submitted') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_app_accepted') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_app_rejected') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_status_change') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_flagged_applicant') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_watchlist_detection') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_intel_match') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_inactive_director') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_handler_note') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_silent_wallet_director') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_dead_weight') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_purge_reminder') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_loa_marked') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_marked_for_purge') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_status_cleared') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_purge_personal') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_token_revoked') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_token_coverage') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_member_joined') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_member_left') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_join_no_application') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_member_unregistered') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_onboarding_welcome') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_wallet_stalled') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_wallet_compliance') }}</li>
                        <li>{{ trans('hr-manager::help.toggle_wallet_milestone') }}</li>
                    </ul>

                    <h4>Wallet-alert cadence</h4>
                    <p>The recurring wallet categories (<em>tax compliance dropped</em> and <em>contributions stalled</em>) describe a <strong>standing condition</strong> that Corp Wallet Manager re-reports every sync cycle. A <strong>Wallet alert cadence</strong> dropdown in <strong>Settings &rarr; Notifications</strong> (beside the Wallet &amp; tax types) controls how often HR re-pings for the same ongoing condition:</p>
                    <ul>
                        <li><strong>Once per event</strong> (default) &mdash; alert once, then stay quiet for the whole episode; a fresh alert fires only after it recovers and reappears.</li>
                        <li><strong>Every 12h / 24h / 3 days / 7 days</strong> &mdash; re-remind on that cadence while the condition persists.</li>
                    </ul>

                    <h4>Inactive-director alert cadence</h4>
                    <p>The <strong>inactive director</strong> alert fires only once a director has actually been inactive <strong>longer than their tier threshold</strong> &mdash; never before it. (A director who is still active but trips a wallet signal is reported through the wallet categories instead, not as "inactive".) An <strong>Inactive director alert cadence</strong> dropdown in <strong>Settings &rarr; Webhooks</strong> controls whether HR keeps reminding you:</p>
                    <ul>
                        <li><strong>Once</strong> (default) &mdash; alert when the director first crosses their threshold, then stay quiet.</li>
                        <li><strong>Daily / every 3 days / weekly / every 2 weeks / monthly</strong> &mdash; re-remind on that cadence for as long as they stay dark.</li>
                    </ul>
                    <p class="text-muted"><small>The reminder clock resets when the director becomes active again, so a later relapse always alerts fresh rather than being muted by the previous episode.</small></p>

                    <h4>
                        <i class="fas fa-comments"></i> {{ trans('hr-manager::help.handler_note_title') }}
                        <span class="badge ml-1" style="background: rgba(40,167,69,0.28); color: #6ee7b7; border: 1px solid rgba(40,167,69,0.55); font-size: 0.78rem; font-weight: 700; padding: 3px 9px; vertical-align: middle;">1.0.1</span>
                    </h4>
                    <p>{!! trans('hr-manager::help.handler_note_body') !!}</p>

                    <h4><i class="fas fa-hand-holding-heart"></i> {{ trans('hr-manager::help.onboarding_title') }}</h4>
                    <p>{!! trans('hr-manager::help.onboarding_notif_pointer') !!}</p>

                    <div class="info-box">
                        <i class="fas fa-bolt"></i>
                        <strong>Fast-poll badge.</strong> The four corp-membership categories (joined / left / joined-without-application / joined-unregistered) carry a <strong>&#9889; Fast-poll</strong> badge plus a status line showing whether Manager Core's ESI fast-poll is live. With it those changes are detected within ~2 minutes; without it &mdash; or without Manager Core at all &mdash; they still fire from the 30-minute roster scan. Fast-poll is optional; these categories work either way.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::help.discord_role_picker_body') !!}
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 INTEGRATIONS
                 ============================================================ --}}
            <div id="integrations" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-plug"></i> {{ trans('hr-manager::help.integrations_title') }}</h3>
                    <p>{!! trans('hr-manager::help.integrations_intro') !!}</p>

                    <table class="table table-dark">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::help.col_plugin') }}</th>
                                <th>{{ trans('hr-manager::help.col_provides') }}</th>
                                <th>{{ trans('hr-manager::help.col_fallback') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><strong>Manager Core</strong></td><td>{{ trans('hr-manager::help.int_mc_provides') }}</td><td>{{ trans('hr-manager::help.int_mc_fallback') }}</td></tr>
                            <tr><td><strong>Mining Manager</strong></td><td>{{ trans('hr-manager::help.int_mm_provides') }}</td><td>{{ trans('hr-manager::help.int_mm_fallback') }}</td></tr>
                            <tr><td><strong>Corp Wallet Manager</strong></td><td>{{ trans('hr-manager::help.int_cwm_provides') }}</td><td>{{ trans('hr-manager::help.int_cwm_fallback') }}</td></tr>
                            <tr><td><strong>Blueprint Manager</strong></td><td>{{ trans('hr-manager::help.int_bp_provides') }}</td><td>{{ trans('hr-manager::help.int_bp_fallback') }}</td></tr>
                            <tr><td><strong>Structure Manager</strong></td><td>{{ trans('hr-manager::help.int_sm_provides') }}</td><td>{{ trans('hr-manager::help.int_sm_fallback') }}</td></tr>
                            <tr><td><strong>Buyback Manager</strong></td><td>Buyback contribution — offers + completed contracts, valued by a per-corp policy, on the player Buyback panel and the Corp Health → Economy card; a recent counted contribution becomes a positive <code>buyback_hold</code> classifier signal</td><td>Panel + card hidden; no buyback signal</td></tr>
                            <tr><td><strong>SeAT Broadcast</strong></td><td>{{ trans('hr-manager::help.int_broadcast_provides') }}</td><td>{{ trans('hr-manager::help.int_broadcast_fallback') }}</td></tr>
                            <tr><td><strong>SeAT Connector</strong></td><td>{{ trans('hr-manager::help.int_connector_provides') }}</td><td>{{ trans('hr-manager::help.int_connector_fallback') }}</td></tr>
                            <tr><td><strong>zKillboard</strong></td><td>{{ trans('hr-manager::help.int_zkill_provides') }}</td><td>{{ trans('hr-manager::help.int_zkill_fallback') }}</td></tr>
                        </tbody>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::help.integrations_principle') !!}
                    </div>
                </div>

                <div class="help-card">
                    <h3><i class="fas fa-project-diagram"></i> What flows where</h3>
                    <p>Manager Core is the hub: its <strong>EventBus</strong> (publish / subscribe) and <strong>PluginBridge</strong> (request / reply capabilities) carry every cross-plugin exchange below. Without Manager Core, HR runs standalone and each of these quietly drops to local-only — nothing errors, the panels just self-hide. The live version of this map, with installed status, is on the admin <strong>Diagnostic &rarr; Ecosystem Map</strong> tab.</p>

                    <h4><i class="fas fa-microchip"></i> Manager Core <span style="font-size:0.7rem; opacity:0.7;">— the hub</span></h4>
                    <p>HR <strong>publishes</strong> <code>hr.application.*</code> (submitted / accepted / rejected / withdrawn / status_changed / joined_corp), <code>hr.player.*</code> (the flagged / recovered / milestone ladder) and <code>hr.purge.*</code> (reminder + executed) so any other plugin can react. It also exposes two capabilities back — <code>hr.getAssessment</code> and <code>hr.getApplicationStatus</code>, both corp-scoped. Everything below rides Manager Core.</p>

                    <h4><i class="fas fa-coins"></i> Corp Wallet Manager</h4>
                    <p><strong>HR receives:</strong> contribution + tax-compliance events (<code>member.contribution.*</code>, <code>member.tax.compliance_dropped</code>, <code>wallet.unusual_recipient_detected</code>) plus per-character lifetime / net-position / tax-compliance and a corp wallet summary over the bridge. <strong>Feeds:</strong> the classifier's wallet-signal layer, the member Wallet Activity + Audit panels, the five Corp Health Wallet Insights cards, and the Economy Financial Pulse.</p>

                    <h4><i class="fas fa-gem"></i> Mining Manager</h4>
                    <p><strong>HR receives:</strong> mining tax + activity events and per-character mining history / ore breakdown over the bridge. <strong>Feeds:</strong> the member assessment mining columns — favourite ores + systems and corp ore-op attendance.</p>

                    <h4><i class="fas fa-scroll"></i> Blueprint Manager</h4>
                    <p><strong>HR receives:</strong> blueprint request events (<code>blueprint.request.*</code>) and per-character / corp blueprint stats over the bridge. <strong>Feeds:</strong> the player Blueprint Activity panel, the Corp Health Blueprint Engagement card, the Industrialist role badge, and a positive classifier modifier.</p>

                    <h4><i class="fas fa-clipboard-check"></i> Structure Manager</h4>
                    <p><strong>HR receives:</strong> the per-corp doctrine-compliance report (<code>compliance.getForCorporation</code>) over the bridge. <strong>Feeds:</strong> the Corp Health &rarr; Structure Compliance tab. Structure Manager owns the doctrines and the compute; HR renders the verdict and adds Copy / Appraise.</p>

                    <h4><i class="fas fa-balance-scale"></i> Buyback Manager</h4>
                    <p><strong>HR receives:</strong> <code>buyback.offer.published</code> + <code>buyback.contract.completed</code> events, accumulated into HR's own table. <strong>HR sends:</strong> a doctrine-fit hand-off to the buyback appraiser from the Structure Compliance tab. <strong>Feeds:</strong> the player Buyback panel, the Corp Health Economy buyback card, the per-corp contribution policy, and the <code>buyback_hold</code> classifier modifier. Run <code>hr-manager:backfill-buyback</code> once to seed history.</p>

                    <h4><i class="fab fa-discord"></i> SeAT Broadcast</h4>
                    <p><strong>HR receives:</strong> <code>pings.broadcast.sent</code> + <code>pings.formup.scheduled</code> events, accumulated for the FC profile. <strong>HR sends:</strong> its <code>hr.*</code> events, which SeAT Broadcast can consume. <strong>Feeds:</strong> the player FC Activity panel, the Corp Health fleet-commander roster + Organizers, and the FC role badge.</p>

                    <h4><i class="fas fa-plug"></i> SeAT Connector + zKillboard <span style="font-size:0.7rem; opacity:0.7;">— direct, no Manager Core</span></h4>
                    <p>These two are read directly rather than through Manager Core. <strong>SeAT Connector</strong> resolves Discord identity + roles for tier auto-resolution and the member sidebar; <strong>zKillboard</strong> supplies the cached PvP card on member profiles and the applicant assessment (no API key required).</p>
                </div>
            </div>

            {{-- ============================================================
                 PERMISSIONS
                 ============================================================ --}}
            <div id="permissions" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-shield-alt"></i> {{ trans('hr-manager::help.permissions_title') }}</h3>
                    <p>{!! trans('hr-manager::help.permissions_intro') !!}</p>

                    <table class="table table-dark">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::help.permission_level') }}</th>
                                <th>{{ trans('hr-manager::help.permission_access') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><span class="badge badge-secondary">View</span></td><td>{{ trans('hr-manager::help.perm_view_desc') }}</td></tr>
                            <tr><td><span class="badge badge-info">Recruiter</span></td><td>{{ trans('hr-manager::help.perm_recruiter_desc') }}</td></tr>
                            <tr><td><span class="badge badge-primary">Personnel Manager</span></td><td>{{ trans('hr-manager::help.perm_personnel_desc') }}</td></tr>
                            <tr><td><span class="badge badge-warning">Director</span></td><td>{{ trans('hr-manager::help.perm_director_desc') }}</td></tr>
                            <tr><td><span class="badge badge-danger">Admin</span></td><td>{{ trans('hr-manager::help.perm_admin_desc') }}</td></tr>
                        </tbody>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::help.coherence_recommendation') !!}
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 COMMANDS
                 ============================================================ --}}
            <div id="commands" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-terminal"></i> {{ trans('hr-manager::help.commands_title') }}</h3>
                    <p>{!! trans('hr-manager::help.commands_intro') !!}</p>

                    <p class="text-muted"><small>Each command's <strong>Options</strong> (the <code>--flags</code> you can pass) and its scheduled cadence (<strong>Runs</strong>) are listed below. Pass options after the signature, e.g. <code>php artisan hr-manager:cleanup --days=30 --dry-run</code>. The crons are registered automatically; you only run these by hand for a one-off or to force a refresh.</small></p>

                    <h4><code>hr-manager:init</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_init') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--check</code> run only the readiness report, load nothing &middot; <code>--force</code> skip the confirmation prompt &middot; <code>--corporation="name|ticker|ID"</code> re-init just one corp (re-applies tier mappings + assessment for that corp; skips the install-wide baseline steps) &nbsp;|&nbsp; <strong>Runs:</strong> on demand (first-run setup)</small></p>

                    <h4><code>hr-manager:cache-assessments</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_cache_assessments') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--corporation_id=ID</code> only this corp &middot; <code>--force</code> refresh even if the cache is still fresh &nbsp;|&nbsp; <strong>Runs:</strong> every 2 hours</small></p>

                    <h4><code>hr-manager:cleanup</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_cleanup') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--days=90</code> delete soft-deleted applications older than N days &middot; <code>--dry-run</code> show what would be deleted &nbsp;|&nbsp; <strong>Runs:</strong> daily 03:00</small></p>

                    <h4><code>hr-manager:classify-players</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_classify') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--corporation_id=ID</code> only classify this corp &nbsp;|&nbsp; <strong>Runs:</strong> nightly 02:00</small></p>

                    <h4><code>hr-manager:dispatch-purge-reminders</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_purge') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> none &nbsp;|&nbsp; <strong>Runs:</strong> every 12 hours (also fires the opt-in T-24h / T-12h squad cleanup)</small></p>

                    <h4><code>hr-manager:detect-corp-joins</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_detect_corp_joins') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--days=90</code> only consider applications accepted within this window &middot; <code>--dry-run</code> show what would be updated &nbsp;|&nbsp; <strong>Runs:</strong> every 30 minutes</small></p>

                    <h4><code>hr-manager:detect-membership-changes</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_detect_membership') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--dry-run</code> report what would change without writing or notifying &nbsp;|&nbsp; <strong>Runs:</strong> every 30 minutes (first scan per corp seeds silently)</small></p>

                    <h4><code>hr-manager:scan-watchlist</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_scan_watchlist') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> none &nbsp;|&nbsp; <strong>Runs:</strong> every 15 minutes</small></p>

                    <h4><code>hr-manager:detect-token-loss</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_detect_token_loss') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> none &nbsp;|&nbsp; <strong>Runs:</strong> every 10 minutes (security-grade, so a higher cadence)</small></p>

                    <h4><code>hr-manager:reconcile-suspected-alts</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_reconcile_suspected_alts') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> none &nbsp;|&nbsp; <strong>Runs:</strong> daily 05:00 (no-op when there are no open claims)</small></p>

                    <h4><code>hr-manager:sync-external-rosters</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_sync_external_rosters') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> none &nbsp;|&nbsp; <strong>Runs:</strong> daily 04:30 when enabled (no-op when off)</small></p>

                    <h4><code>hr-manager:warm-player-profiles</code></h4>
                    <p>{!! trans('hr-manager::help.cmd_warm_player_profiles') !!}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--corporation=&lt;name|ticker|id&gt;</code> warm one corp (preview + confirm) &nbsp;|&nbsp; <code>--all</code> every tracked corp &nbsp;|&nbsp; <code>--no-interaction</code> skip the prompt &nbsp;|&nbsp; <strong>Runs:</strong> every 30 minutes when enabled (no-op when off)</small></p>

                    <h4><code>hr-manager:send-onboarding-welcomes</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_send_onboarding_welcomes') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> none &nbsp;|&nbsp; <strong>Runs:</strong> every 5 minutes (no-op when nothing is due)</small></p>

                    <h4><code>hr-manager:sweep-access-grants</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_sweep_access_grants') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> none &nbsp;|&nbsp; <strong>Runs:</strong> daily 04:00 (backstop — the lifecycle hooks revoke first)</small></p>

                    <h4><code>hr-manager:token-coverage-digest</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_token_coverage') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--corporation_id=ID</code> only digest this corp &nbsp;|&nbsp; <strong>Runs:</strong> weekly, Monday 09:00 (no-op unless a webhook opted in)</small></p>

                    <h4><code>hr-manager:prune-audit-log</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_prune_audit_log') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--days=N</code> override the retention window &nbsp;|&nbsp; <strong>Runs:</strong> daily 04:30 (runs even when the Activity Log is off, so it still drains)</small></p>

                    <h4><code>hr-manager:diagnose</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_diagnose') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> none &nbsp;|&nbsp; <strong>Runs:</strong> on demand (the headless counterpart of the Diagnostic page)</small></p>

                    <h4><code>hr-manager:backfill-buyback</code></h4>
                    <p>{{ trans('hr-manager::help.cmd_backfill_buyback') }}</p>
                    <p class="text-muted cmd-meta"><small><strong>Options:</strong> <code>--dry-run</code> count what would be seeded without writing &nbsp;|&nbsp; <strong>Runs:</strong> on demand (one-time seed; live data flows via the EventBus after)</small></p>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('hr-manager::help.commands_schedule_note') !!}
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 Custom Styling
                 ============================================================ --}}
            <div id="custom-styling" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-paint-brush"></i> {{ trans('hr-manager::help.custom_styling_guide') }}</h3>
                    <p>{{ trans('hr-manager::help.custom_styling_intro') }}</p>

                    <h4>{{ trans('hr-manager::help.css_class_hierarchy') }}</h4>
                    <p>{{ trans('hr-manager::help.css_class_hierarchy_desc') }}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.css_base_class') !!}</li>
                        <li>{!! trans('hr-manager::help.css_diagnostic_class') !!}</li>
                    </ul>

                    <h4>{{ trans('hr-manager::help.css_vars_title') }}</h4>
                    <p>{{ trans('hr-manager::help.css_vars_desc') }}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.css_var_primary') !!}</li>
                        <li>{!! trans('hr-manager::help.css_var_surface') !!}</li>
                        <li>{!! trans('hr-manager::help.css_var_text') !!}</li>
                        <li>{!! trans('hr-manager::help.css_var_status') !!}</li>
                    </ul>

                    <h4>{{ trans('hr-manager::help.css_components_title') }}</h4>
                    <p>{{ trans('hr-manager::help.css_components_desc') }}</p>
                    <ul>
                        <li>{!! trans('hr-manager::help.css_component_card') !!}</li>
                        <li>{!! trans('hr-manager::help.css_component_cardtitle') !!}</li>
                        <li>{!! trans('hr-manager::help.css_component_box') !!}</li>
                        <li>{!! trans('hr-manager::help.css_component_btn') !!}</li>
                    </ul>

                    <h4>{{ trans('hr-manager::help.css_example_title') }}</h4>

                    <h5>{{ trans('hr-manager::help.css_example_vars') }}</h5>
                    <pre><code>{{ trans('hr-manager::help.css_example_vars_code') }}</code></pre>

                    <h5>{{ trans('hr-manager::help.css_example_global') }}</h5>
                    <pre><code>{{ trans('hr-manager::help.css_example_global_code') }}</code></pre>

                    <h5>{{ trans('hr-manager::help.css_example_specific') }}</h5>
                    <pre><code>{{ trans('hr-manager::help.css_example_specific_code') }}</code></pre>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>{{ trans('hr-manager::help.css_where_to_add') }}:</strong> {!! trans('hr-manager::help.css_where_to_add_desc') !!}
                    </div>
                    <div class="info-box">
                        <i class="fas fa-lightbulb"></i>
                        {{ trans('hr-manager::help.custom_styling_note') }}
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 FAQ
                 ============================================================ --}}
            <div id="faq" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-question-circle"></i> {{ trans('hr-manager::help.faq_title') }}</h3>

                    <div class="faq-item">
                        <div class="faq-question"><strong>{{ trans('hr-manager::help.q_no_mc') }}</strong> <i class="fas fa-chevron-down float-right"></i></div>
                        <div class="faq-answer">{!! trans('hr-manager::help.a_no_mc') !!}</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-question"><strong>{{ trans('hr-manager::help.q_director_token') }}</strong> <i class="fas fa-chevron-down float-right"></i></div>
                        <div class="faq-answer">{!! trans('hr-manager::help.a_director_token') !!}</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-question"><strong>{{ trans('hr-manager::help.q_unmapped_tier') }}</strong> <i class="fas fa-chevron-down float-right"></i></div>
                        <div class="faq-answer">{!! trans('hr-manager::help.a_unmapped_tier') !!}</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-question"><strong>{{ trans('hr-manager::help.q_zkill_slow') }}</strong> <i class="fas fa-chevron-down float-right"></i></div>
                        <div class="faq-answer">{!! trans('hr-manager::help.a_zkill_slow') !!}</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-question"><strong>{{ trans('hr-manager::help.q_unregistered_alts') }}</strong> <i class="fas fa-chevron-down float-right"></i></div>
                        <div class="faq-answer">{!! trans('hr-manager::help.a_unregistered_alts') !!}</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-question"><strong>{{ trans('hr-manager::help.q_member_count_mismatch') }}</strong> <i class="fas fa-chevron-down float-right"></i></div>
                        <div class="faq-answer">{!! trans('hr-manager::help.a_member_count_mismatch') !!}</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
$(document).ready(function() {
    // Sidebar navigation
    $('.help-nav .nav-link').on('click', function(e) {
        e.preventDefault();
        const section = $(this).data('section');
        $('.help-nav .nav-link').removeClass('active');
        $(this).addClass('active');
        $('.help-section').removeClass('active');
        $(`#${section}`).addClass('active');
        window.location.hash = section;
        $('.help-content').scrollTop(0);
    });

    // Open section from URL hash
    if (window.location.hash) {
        const hash = window.location.hash.substring(1);
        $(`.help-nav .nav-link[data-section="${hash}"]`).click();
    }

    // FAQ accordion
    $('.faq-question').on('click', function() {
        $(this).closest('.faq-item').toggleClass('open');
    });

    // Search filter — hides .help-card blocks that don't contain the query
    let searchTimeout;
    $('#helpSearch').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val().toLowerCase();
        if (query.length < 2) {
            $('.help-card').show();
            return;
        }
        searchTimeout = setTimeout(() => {
            $('.help-card').each(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(query) > -1);
            });
        }, 200);
    });
});
</script>
@endpush

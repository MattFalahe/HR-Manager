@extends('web::layouts.grids.12')

@section('title', 'HR Manager — Diagnostics')
@section('page_header', 'HR Manager — Diagnostics')

@php
    $tabs = [
        'health'        => ['Health Checks', 'fa-heartbeat'],
        'master'        => ['Master Test', 'fa-vial'],
        'validation'    => ['System Validation', 'fa-plug'],
        'ecosystem'     => ['Ecosystem Map', 'fa-project-diagram'],
        'settings'      => ['Settings Health', 'fa-sliders-h'],
        'integrity'     => ['Data Integrity', 'fa-database'],
        'notifications' => ['Notification Test', 'fa-bell'],
        'trace'         => ['Application Trace', 'fa-route'],
    ];
    $statusMeta = [
        'ok'   => ['#28a745', 'fa-check-circle', 'OK'],
        'warn' => ['#ffc107', 'fa-exclamation-triangle', 'WARN'],
        'fail' => ['#dc3545', 'fa-times-circle', 'FAIL'],
    ];
@endphp

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.1.0">
<style>
    /* Tabs follow the suite-wide diagnostic pattern (CWM / Structure Manager):
       a flat flex underline bar, no Bootstrap nav-tabs. The whole page is
       wrapped in .hr-manager-wrapper.diagnostic-page, which the site theme
       treats as an exempt root, so these rules win WITHOUT !important and the
       page finally matches the other plugins' diagnostics. */
    .hr-manager-wrapper.diagnostic-page .diag-tabs {
        display: flex; flex-wrap: wrap; gap: 0; list-style: none;
        margin: 0 0 1.25rem; padding: 0;
        border-bottom: 2px solid #454d55;
    }
    .hr-manager-wrapper.diagnostic-page .diag-tab {
        display: flex; align-items: center; gap: 0.5rem;
        padding: 0.6rem 1.1rem; color: #8b95a5;
        font-weight: 500; font-size: 0.9rem; text-decoration: none;
        border-bottom: 3px solid transparent; transition: all 0.15s;
    }
    .hr-manager-wrapper.diagnostic-page .diag-tab:hover {
        color: #c2c7d0; border-bottom-color: #3a4049; text-decoration: none;
    }
    .hr-manager-wrapper.diagnostic-page .diag-tab.active {
        color: #818cf8; border-bottom-color: #667eea;
    }
    .diag-tab-intro {
        background: rgba(102,126,234,0.08); border-left: 3px solid #667eea;
        border-radius: 4px; padding: 10px 14px; margin-bottom: 16px; font-size: 0.85rem; color: #c2c7d0;
        line-height: 1.65;
    }
    /* The three labelled parts (What this tab does / When to use / Heads up) each
       sit on their own row via a <br>; line-height above gives the rows breathing space. */
    .diag-tab-intro strong { color: #fff; }
    .diag-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); }
    .diag-row:last-child { border-bottom: none; }
    .diag-badge { min-width: 58px; text-align: center; font-size: 0.7rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; }
    .diag-label { flex: 0 0 280px; color: #e6e9ef; font-size: 0.88rem; font-weight: 500; }
    .diag-msg { flex: 1; color: #9aa1ad; font-size: 0.84rem; }
    .diag-summary span { font-weight: 700; }
    /* The wrapper now supplies the btn-hr-* styling, but we keep a local copy
       as a harmless fallback so Send test / Trace never render bare. */
    .btn-hr-primary {
        background: linear-gradient(135deg, var(--hr-primary-start, #667eea) 0%, var(--hr-primary-end, #764ba2) 100%);
        border: none; color: var(--hr-text-white, #fff);
    }
    .btn-hr-primary:hover { background: linear-gradient(135deg, #5568d3 0%, #6a3d8f 100%); color: #fff; }
    .btn-hr-secondary {
        background-color: var(--hr-dark-card, #23262d);
        border: 1px solid var(--hr-border, #2c3138);
        color: var(--hr-text-light, #d1d1d1);
    }
    .btn-hr-secondary:hover { background-color: #2c3138; border-color: rgba(102,126,234,0.5); color: #fff; }
</style>
@endpush

@section('full')
<div class="hr-manager-wrapper diagnostic-page">
  <div class="card card-dark diag-card">
    <div class="card-body">
    <div class="diag-tabs">
        @foreach($tabs as $key => $meta)
            <a class="diag-tab {{ $activeTab === $key ? 'active' : '' }}" href="{{ route('hr-manager.diagnostic', ['diag_tab' => $key]) }}">
                <i class="fas {{ $meta[1] }}"></i> {{ $meta[0] }}
            </a>
        @endforeach
    </div>
    <div class="diag-body">

        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

        <p class="text-muted" style="font-size: 0.85rem;">
            Admin-only health, integration and data checks for HR Manager. Read-only (except the notification test). Reach this page directly at <code>/hr-manager/diagnostic</code> — it is deliberately not in the sidebar.
        </p>

        {{-- ============================ HEALTH ============================ --}}
        @if($activeTab === 'health')
            <div class="diag-tab-intro">
                <strong>What this tab does:</strong> fast, always-on checks of the basics — tables, Manager Core, EventBus wiring, schedules, webhooks.
               <br><strong>When to use:</strong> first stop when something looks broken.
               <br><strong>Heads up:</strong> WARN is usually "optional thing absent" (e.g. Manager Core not installed); FAIL means a hard dependency is missing.
            </div>
            <div class="diag-summary mb-2" style="font-size: 0.9rem;">
                <span style="color:#28a745;">{{ $summary['ok'] }} OK</span> &nbsp;
                <span style="color:#ffc107;">{{ $summary['warn'] }} WARN</span> &nbsp;
                <span style="color:#dc3545;">{{ $summary['fail'] }} FAIL</span>
            </div>
            <div class="card card-dark mb-0">
                @foreach($health as $c)
                    @php $m = $statusMeta[$c['status']] ?? $statusMeta['ok']; @endphp
                    <div class="diag-row">
                        <span class="diag-badge" style="background: {{ $m[0] }}22; color: {{ $m[0] }};"><i class="fas {{ $m[1] }}"></i> {{ $m[2] }}</span>
                        <span class="diag-label">{{ $c['label'] }}</span>
                        <span class="diag-msg">{{ $c['message'] }}</span>
                    </div>
                @endforeach
            </div>

        {{-- ========================= MASTER TEST ========================= --}}
        @elseif($activeTab === 'master')
            <div class="diag-tab-intro">
                <strong>What this tab does:</strong> runs every check on this page at once — health, system validation, settings and data integrity — for a single combined verdict.
               <br><strong>When to use:</strong> a one-click "is HR healthy end to end?" before a release, after an upgrade, or when filing a bug report.
               <br><strong>Heads up:</strong> it's the heaviest tab (it computes all the others); WARN is normal when optional sibling plugins are absent.
            </div>
            <div class="diag-summary mb-2" style="font-size: 0.95rem;">
                <span style="color:#28a745;">{{ $master['summary']['ok'] }} OK</span> &nbsp;
                <span style="color:#ffc107;">{{ $master['summary']['warn'] }} WARN</span> &nbsp;
                <span style="color:#dc3545;">{{ $master['summary']['fail'] }} FAIL</span>
            </div>
            @foreach($master['groups'] as $groupName => $rows)
                <h5 class="mt-3" style="color:#e6e9ef;">{{ $groupName }} <span class="text-muted" style="font-size:0.75rem; font-weight:normal;">({{ count($rows) }})</span></h5>
                <div class="card card-dark mb-0">
                    @foreach($rows as $c)
                        @php $m = $statusMeta[$c['status']] ?? $statusMeta['ok']; @endphp
                        <div class="diag-row">
                            <span class="diag-badge" style="background: {{ $m[0] }}22; color: {{ $m[0] }};"><i class="fas {{ $m[1] }}"></i> {{ $m[2] }}</span>
                            <span class="diag-label" style="flex-basis: 320px;">{{ $c['label'] }}</span>
                            <span class="diag-msg">{{ $c['message'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endforeach

        {{-- ========================== VALIDATION ========================= --}}
        @elseif($activeTab === 'validation')
            <div class="diag-tab-intro">
                <strong>What this tab does:</strong> verifies HR's cross-plugin contract with Manager Core — capabilities it consumes, EventBus subscriptions it owns, and topics it publishes (which must be registered in MC or they're silently dropped).
               <br><strong>When to use:</strong> when CWM/MM/Broadcast data isn't flowing, or after a Manager Core upgrade.
               <br><strong>Heads up:</strong> a published-topic FAIL means a publish site outran the MC registry.
            </div>
            <h5 class="mt-1" style="color:#e6e9ef;">Suite plugins detected</h5>
            <p class="text-muted" style="font-size:0.8rem; margin-bottom:6px;">Which sibling plugins are installed alongside HR. A WARN here just means that (optional) plugin isn't installed, which explains any "not registered" rows below.</p>
            <div class="card card-dark mb-0">
                @foreach($validation['plugins'] as $c)
                    @php $m = $statusMeta[$c['status']] ?? $statusMeta['ok']; @endphp
                    <div class="diag-row">
                        <span class="diag-badge" style="background: {{ $m[0] }}22; color: {{ $m[0] }};"><i class="fas {{ $m[1] }}"></i> {{ $m[2] }}</span>
                        <span class="diag-label" style="flex-basis: 280px;">{{ $c['label'] }}</span>
                        <span class="diag-msg">{{ $c['message'] }}</span>
                    </div>
                @endforeach
            </div>

            @foreach(['capabilities' => 'Consumed capabilities (via Manager Core bridge)', 'direct' => 'Direct-query integrations', 'subscriptions' => 'EventBus subscriptions (HR-owned)', 'topics' => 'Published topics (must be MC-registered)'] as $k => $title)
                <h5 class="mt-3" style="color:#e6e9ef;">{{ $title }}</h5>
                <div class="card card-dark mb-0">
                    @foreach($validation[$k] as $c)
                        @php $m = $statusMeta[$c['status']] ?? $statusMeta['ok']; @endphp
                        <div class="diag-row">
                            <span class="diag-badge" style="background: {{ $m[0] }}22; color: {{ $m[0] }};"><i class="fas {{ $m[1] }}"></i> {{ $m[2] }}</span>
                            <span class="diag-label" style="flex-basis: 360px;">{{ $c['label'] }}</span>
                            <span class="diag-msg">{{ $c['message'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endforeach

        {{-- ========================== ECOSYSTEM ========================= --}}
        @elseif($activeTab === 'ecosystem')
            <div class="diag-tab-intro">
                <strong>What this tab does:</strong> maps the data HR exchanges with each sibling plugin — what it receives, what it publishes, and which HR screens that feeds.
               <br><strong>When to use:</strong> to understand how the suite fits together, or to see at a glance which integrations are live on this server.
               <br><strong>Heads up:</strong> everything cross-plugin flows through Manager Core — without it, HR runs standalone and these rows fall back to local-only.
            </div>
            @if($ecosystem['fast_poll'])
                <div class="alert" style="background:#1b3a2b; border:1px solid #28a745; color:#cde6d8; font-size:0.85rem;">
                    <i class="fas fa-bolt" style="color:#28a745;"></i> <strong>Fast-poll active.</strong> Manager Core's ESI notification poll is wired in, so corp joins and voluntary leaves are picked up within ~2 minutes instead of waiting on the slower roster sync.
                </div>
            @endif
            @foreach($ecosystem['flows'] as $f)
                @php $eb = $f['installed'] ? ['#28a745','fa-check-circle','Installed'] : ['#6c757d','fa-circle','Not installed']; @endphp
                <div class="card card-dark mb-2">
                    <div class="card-body" style="padding:0.75rem 1rem;">
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                            <span class="diag-badge" style="background:{{ $eb[0] }}22; color:{{ $eb[0] }};"><i class="fas {{ $eb[1] }}"></i> {{ $eb[2] }}</span>
                            <strong style="color:#e6e9ef; font-size:0.95rem;">{{ $f['label'] }}</strong>
                            <span class="text-muted" style="font-size:0.78rem;">{{ $f['detail'] }}</span>
                        </div>
                        @php
                            $eco = [
                                ['receives', 'HR receives', '#5b8def', 'fa-arrow-down'],
                                ['sends', 'HR sends', '#b07cff', 'fa-arrow-up'],
                                ['surfaces', 'Feeds', '#e6a23c', 'fa-window-restore'],
                            ];
                        @endphp
                        @foreach($eco as $row)
                            @if(!empty($f[$row[0]]))
                                <div style="display:flex; gap:8px; margin:3px 0; font-size:0.82rem;">
                                    <span style="flex:0 0 96px; color:{{ $row[2] }};"><i class="fas {{ $row[3] }}"></i> {{ $row[1] }}</span>
                                    <span style="color:#c7ccd6;">
                                        @foreach($f[$row[0]] as $line)
                                            {{ $line }}@if(!$loop->last)<br>@endif
                                        @endforeach
                                    </span>
                                </div>
                            @endif
                        @endforeach
                        @if(empty($f['receives']) && empty($f['sends']))
                            <div class="text-muted" style="font-size:0.8rem;">No active data exchange.</div>
                        @endif
                    </div>
                </div>
            @endforeach

        {{-- =========================== SETTINGS ========================== --}}
        @elseif($activeTab === 'settings')
            <div class="diag-tab-intro">
                <strong>What this tab does:</strong> checks operator configuration — tier mappings, recruitment landings, templates, and webhook URLs (HTTPS-only).
               <br><strong>When to use:</strong> after editing settings, or when notifications/recruitment behave unexpectedly.
               <br><strong>Heads up:</strong> a non-HTTPS webhook FAIL means that webhook will be rejected at send time.
            </div>
            <div class="card card-dark mb-0">
                @foreach($settings as $c)
                    @php $m = $statusMeta[$c['status']] ?? $statusMeta['ok']; @endphp
                    <div class="diag-row">
                        <span class="diag-badge" style="background: {{ $m[0] }}22; color: {{ $m[0] }};"><i class="fas {{ $m[1] }}"></i> {{ $m[2] }}</span>
                        <span class="diag-label" style="flex-basis: 320px;">{{ $c['label'] }}</span>
                        <span class="diag-msg">{{ $c['message'] }}</span>
                    </div>
                @endforeach
            </div>

        {{-- ========================== INTEGRITY ========================== --}}
        @elseif($activeTab === 'integrity')
            <div class="diag-tab-intro">
                <strong>What this tab does:</strong> data-consistency checks — classifier freshness, assessment cache, CWM signal traffic, orphan rows, FC accumulation.
               <br><strong>When to use:</strong> when the dashboard looks stale or empty, to tell "no data yet" from "a job hasn't run."
               <br><strong>Heads up:</strong> a stale classifier WARN usually means the nightly cron hasn't run.
            </div>
            <div class="card card-dark mb-0">
                @foreach($integrity as $c)
                    @php $m = $statusMeta[$c['status']] ?? $statusMeta['ok']; @endphp
                    <div class="diag-row">
                        <span class="diag-badge" style="background: {{ $m[0] }}22; color: {{ $m[0] }};"><i class="fas {{ $m[1] }}"></i> {{ $m[2] }}</span>
                        <span class="diag-label" style="flex-basis: 320px;">{{ $c['label'] }}</span>
                        <span class="diag-msg">{{ $c['message'] }}</span>
                    </div>
                @endforeach
            </div>

        {{-- ======================== NOTIFICATIONS ======================== --}}
        @elseif($activeTab === 'notifications')
            <div class="diag-tab-intro">
                <strong>What this tab does:</strong> sends a real test message to a configured webhook so you can confirm Discord/Slack delivery end to end.
               <br><strong>When to use:</strong> after setting up a webhook, or when alerts aren't arriving.
               <br><strong>Heads up:</strong> this posts a live message to the channel.
            </div>
            @if($webhooks->isEmpty())
                <p class="text-muted">No active webhooks configured. Add one under <a href="{{ route('hr-manager.settings.index') }}">Settings</a> first.</p>
            @else
                <table class="table table-hover">
                    <thead><tr><th>Webhook</th><th>Type</th><th></th></tr></thead>
                    <tbody>
                        @foreach($webhooks as $wh)
                            <tr>
                                <td><strong>{{ $wh->name ?? ('#' . $wh->id) }}</strong></td>
                                <td>{{ ucfirst($wh->type ?? 'discord') }}</td>
                                <td>
                                    <form method="POST" action="{{ route('hr-manager.diagnostic.test-notification') }}">
                                        @csrf
                                        <input type="hidden" name="webhook_id" value="{{ $wh->id }}">
                                        <button type="submit" class="btn btn-sm btn-hr-secondary"><i class="fas fa-paper-plane"></i> Send test</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

        {{-- =========================== TRACE ============================= --}}
        @elseif($activeTab === 'trace')
            <div class="diag-tab-intro">
                <strong>What this tab does:</strong> walks a single application through HR's pipeline — template, answers, recruiters, status transitions, applicant resolution — showing what's present at each step.
               <br><strong>When to use:</strong> when one application behaved oddly (stuck status, missing handler, no event).
               <br><strong>Heads up:</strong> read-only; it changes nothing.
            </div>
            <form method="GET" action="{{ route('hr-manager.diagnostic') }}" class="form-inline mb-3">
                <input type="hidden" name="diag_tab" value="trace">
                <input type="number" name="application_id" class="form-control mr-2" placeholder="Application ID" value="{{ request('application_id') }}">
                <button type="submit" class="btn btn-hr-secondary"><i class="fas fa-route"></i> Trace</button>
            </form>
            @if($traceResult !== null)
                @if(empty($traceResult['found']))
                    <p class="text-danger">{{ $traceResult['message'] }}</p>
                @else
                    <div class="card card-dark mb-0">
                        @foreach($traceResult['steps'] as $s)
                            @php $m = $statusMeta[$s['status']] ?? $statusMeta['ok']; @endphp
                            <div class="diag-row">
                                <span class="diag-badge" style="background: {{ $m[0] }}22; color: {{ $m[0] }};"><i class="fas {{ $m[1] }}"></i> {{ $m[2] }}</span>
                                <span class="diag-label">{{ $s['label'] }}</span>
                                <span class="diag-msg">{{ $s['detail'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    @if(!empty($traceResult['timeline']))
                        <h5 class="mt-3" style="color:#e6e9ef;"><i class="fas fa-stream" style="color:#667eea;"></i> Timeline</h5>
                        <p class="text-muted" style="font-size:0.8rem; margin-bottom:6px;">Everything recorded against this application, oldest first: status changes, recruiter assignments and notes, with who did it and when.</p>
                        <div class="card card-dark mb-0">
                            @foreach($traceResult['timeline'] as $ev)
                                <div class="diag-row" style="align-items:flex-start;">
                                    <span style="flex:0 0 120px; color:#7f8694; font-size:0.78rem; white-space:nowrap;">{{ $ev['abs'] }}</span>
                                    <span style="flex:0 0 20px; text-align:center; color:#667eea;"><i class="fas {{ $ev['icon'] }}"></i></span>
                                    <span style="flex:1; font-size:0.85rem;">
                                        <span style="color:#e6e9ef; font-weight:500;">{{ $ev['title'] }}</span>
                                        @if($ev['admin'])<span class="badge ml-1" style="background:#ffc107; color:#1a1a1a; font-weight:600;" title="SeAT superuser (global admin)">ADMIN</span>@endif
                                        @if($ev['private'])<span class="badge ml-1" style="background:#6c757d; color:#fff;">private</span>@endif
                                        @if($ev['actor'])<span style="color:#9aa1ad;"> by <strong style="color:#c2c7d0;">{{ $ev['actor'] }}</strong></span>@endif
                                        @if($ev['detail'])<div style="color:#9aa1ad; font-size:0.82rem; margin-top:2px;">{{ $ev['detail'] }}</div>@endif
                                    </span>
                                    <span style="flex:0 0 auto; color:#6c757d; font-size:0.75rem; white-space:nowrap;">{{ $ev['rel'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            @endif
        @endif

    </div>
    </div>
  </div>
</div>
@endsection

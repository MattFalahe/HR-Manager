@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::recruit.hydrating_heading'))
@section('page_header', trans('hr-manager::recruit.hydrating_heading'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
<style>
    .hydrate-card { max-width: 720px; margin: 2rem auto; }
    .hydrate-card .card-body { padding: 3rem 2rem; }
    /* Branded icon cycle: gracefully cross-fades the plugin's three
       identities (the scales of judgement, the two faces of recruiting,
       the people it manages). Each icon owns a third of a 7.5s loop with a
       soft fade + scale, staggered by animation-delay. Pure CSS, no JS. */
    .hr-brand-cycle {
        position: relative;
        display: inline-block;
        width: 1.3em;
        height: 1.3em;
        font-size: 5rem;
        line-height: 1.3em;
        animation: hr-brand-bob 3.2s ease-in-out infinite;
    }
    .hr-brand-cycle i {
        position: absolute;
        top: 0; left: 0; right: 0;
        text-align: center;
        color: var(--hr-primary, #667eea);
        opacity: 0;
        animation: hr-brand-fade 7.5s ease-in-out infinite;
    }
    .hr-brand-cycle .bc-2 { animation-delay: 2.5s; }
    .hr-brand-cycle .bc-3 { animation-delay: 5s; }
    @keyframes hr-brand-fade {
        0%   { opacity: 0; transform: scale(0.82); }
        6%   { opacity: 1; transform: scale(1); }
        27%  { opacity: 1; transform: scale(1); }
        33%  { opacity: 0; transform: scale(0.82); }
        100% { opacity: 0; transform: scale(0.82); }
    }
    @keyframes hr-brand-bob {
        0%, 100% { transform: translateY(0); }
        50%      { transform: translateY(-6px); }
    }
    .hydrate-progress {
        height: 6px;
        background: rgba(255,255,255,0.08);
        border-radius: 3px;
        overflow: hidden;
        margin: 1.5rem auto;
        max-width: 400px;
    }
    .hydrate-progress-bar {
        height: 100%;
        background: linear-gradient(90deg, var(--hr-primary, #667eea), var(--hr-accent, #764ba2));
        width: 30%;
        animation: hydrate-slide 2.4s ease-in-out infinite;
        border-radius: 3px;
    }
    @keyframes hydrate-slide {
        0%   { margin-left: 0%;   width: 30%; }
        50%  { margin-left: 35%;  width: 50%; }
        100% { margin-left: 100%; width: 30%; }
    }
    .signal-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 8px;
        margin: 2rem auto;
        max-width: 600px;
    }
    .signal-chip {
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 6px;
        padding: 10px 12px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
        color: var(--hr-text-muted, #8b95a5);
        transition: all 0.3s;
    }
    .signal-chip.loading { color: var(--hr-text-white, #fff); }
    .signal-chip.loading i { color: var(--hr-primary, #667eea); animation: spin 1.2s linear infinite; }
    .signal-chip.done { border-color: rgba(34, 197, 94, 0.4); color: rgba(34, 197, 94, 0.95); }
    .signal-chip.done i { color: rgb(34, 197, 94); }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .hamster-status {
        color: var(--hr-text-muted, #8b95a5);
        font-style: italic;
        margin-top: 1rem;
        min-height: 1.4em;
    }
    .hydrate-timeout-note {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid rgba(255,255,255,0.08);
        font-size: 0.85rem;
        color: var(--hr-text-muted, #8b95a5);
    }
</style>
@endpush

@section('full')
<div class="hr-manager-wrapper">
    <div class="card card-dark hydrate-card">
        <div class="card-body text-center">
            {{-- Branded icon cycle (scales of judgement -> the two faces of
                 recruiting -> the people HR manages). Cross-fades gracefully
                 in pure CSS; aria-hidden since it's decorative. --}}
            <div class="hr-brand-cycle" aria-hidden="true">
                <i class="fas fa-balance-scale bc-1"></i>
                <i class="fas fa-theater-masks bc-2"></i>
                <i class="fas fa-users bc-3"></i>
            </div>
            <h3 class="mt-3" style="color: var(--hr-text-white, #fff);">
                {{ trans('hr-manager::recruit.hydrating_heading') }}
            </h3>
            <p style="color: var(--hr-text-light, #c9d1d9);">
                {{ trans('hr-manager::recruit.hydrating_body') }}
            </p>

            <div class="hydrate-progress" aria-hidden="true">
                <div class="hydrate-progress-bar"></div>
            </div>

            {{-- Per-signal chips. Server emits which signals are still
                 missing; the poll updates DOM classes when they go from
                 missing → done. --}}
            <div class="signal-grid" id="hydrate-signals">
                @foreach(['security_status', 'skill_points', 'character_age', 'corporation_history', 'affiliation'] as $signal)
                    @php $isMissing = in_array($signal, $missing, true); @endphp
                    <div class="signal-chip {{ $isMissing ? 'loading' : 'done' }}"
                         data-signal="{{ $signal }}">
                        <i class="fas {{ $isMissing ? 'fa-sync-alt' : 'fa-check-circle' }}"></i>
                        <span>{{ trans('hr-manager::recruit.hydrating_signal_' . $signal) }}</span>
                    </div>
                @endforeach
            </div>

            <div class="hamster-status" id="hamster-status">
                {{ trans('hr-manager::recruit.hydrating_status_start') }}
            </div>

            <div class="hydrate-timeout-note">
                <i class="fas fa-info-circle"></i>
                {{ trans('hr-manager::recruit.hydrating_timeout_note') }}
            </div>

            <div id="hydrate-stalled" style="display: none; margin-top: 1.5rem;">
                {{-- Out-of-coffee banner: the hamsters can't reach the CCP
                     devs because the coffee has run out. Playful framing
                     for what is genuinely "SeAT's worker queue couldn't
                     hydrate the data we need". Offers a Manual Review
                     button that bypasses the hydrating gate. --}}
                <div class="alert" style="text-align: left; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.3); color: #c9d1d9;">
                    <div style="text-align: center; margin-bottom: 1rem;">
                        <i class="fas fa-mug-hot" style="font-size: 3rem; color: rgba(245, 158, 11, 0.9); display: block; margin-bottom: 0.5rem;"></i>
                        <strong style="font-size: 1.15rem; color: var(--hr-text-white, #fff);">
                            {{ trans('hr-manager::recruit.hydrating_stalled_heading') }}
                        </strong>
                    </div>
                    <p class="mb-3">{{ trans('hr-manager::recruit.hydrating_stalled_body') }}</p>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px; justify-content: center;">
                        <a href="{{ route('hr-manager.recruit.apply', ['ticker' => $ticker, 'slug' => $slug]) }}?manual_review=1"
                           class="btn btn-hr-primary">
                            <i class="fas fa-user-edit"></i> {{ trans('hr-manager::recruit.hydrating_stalled_manual_review') }}
                        </a>
                        <button type="button" class="btn btn-hr-secondary" onclick="window.location.reload()">
                            <i class="fas fa-redo"></i> {{ trans('hr-manager::recruit.hydrating_stalled_retry') }}
                        </button>
                        <a href="{{ route('hr-manager.recruit.show', ['ticker' => $ticker, 'slug' => $slug]) }}"
                           class="btn btn-hr-secondary">
                            <i class="fas fa-arrow-left"></i> {{ trans('hr-manager::recruit.hydrating_stalled_back') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@php
    // Build the JSON-serialised values in a single @php block before
    // the script — Blade's @json() directive choked on the multi-line
    // nested-array literal previously inline here (parser miscounted
    // brackets, blew up at compile time with "Unclosed '['"). Plain
    // json_encode inside {!! !!} is more reliable.
    // Use a RELATIVE path (third arg false) for the poll URL — when
    // SeAT is behind a reverse proxy without TRUSTED_PROXIES /
    // forced HTTPS configured, route() emits http:// while the
    // browser loaded the page over https://. The page's CSP
    // (connect-src 'self') then blocks the fetch as a cross-origin
    // request. Path-only URLs always inherit the page's scheme +
    // host so this can never mismatch.
    $hydrateUrl = route('hr-manager.recruit.hydrate', ['ticker' => $ticker, 'slug' => $slug], false);
    $statusMessages = [
        trans('hr-manager::recruit.hydrating_status_1'),
        trans('hr-manager::recruit.hydrating_status_2'),
        trans('hr-manager::recruit.hydrating_status_3'),
        trans('hr-manager::recruit.hydrating_status_4'),
        trans('hr-manager::recruit.hydrating_status_5'),
        trans('hr-manager::recruit.hydrating_status_6'),
    ];
@endphp
<script>
(function () {
    // -----------------------------------------------------------------
    // Polls the hydrate endpoint every 3s, updates per-signal chips
    // as items hydrate, reloads the page when everything is ready.
    // After 60 polls (3 minutes) gives up and shows the stalled
    // banner with a manual retry button.
    // -----------------------------------------------------------------

    const POLL_INTERVAL_MS = 3000;
    const MAX_POLLS = 60; // ~3 minutes
    const HYDRATE_URL = {!! json_encode($hydrateUrl, JSON_UNESCAPED_SLASHES) !!};

    // Rotating status messages (operator-localisable via the lang file).
    const MESSAGES = {!! json_encode($statusMessages, JSON_UNESCAPED_UNICODE) !!};

    let pollCount = 0;
    const statusEl = document.getElementById('hamster-status');
    const stalledEl = document.getElementById('hydrate-stalled');

    function updateSignals(missing) {
        document.querySelectorAll('.signal-chip').forEach(chip => {
            const sig = chip.getAttribute('data-signal');
            const stillMissing = missing.indexOf(sig) !== -1;
            if (stillMissing) {
                chip.classList.add('loading');
                chip.classList.remove('done');
                const icon = chip.querySelector('i');
                if (icon) { icon.className = 'fas fa-sync-alt'; }
            } else {
                chip.classList.remove('loading');
                chip.classList.add('done');
                const icon = chip.querySelector('i');
                if (icon) { icon.className = 'fas fa-check-circle'; }
            }
        });
    }

    function rotateMessage() {
        const msg = MESSAGES[pollCount % MESSAGES.length];
        if (statusEl) statusEl.textContent = msg;
    }

    function poll() {
        if (pollCount >= MAX_POLLS) {
            if (stalledEl) stalledEl.style.display = 'block';
            return;
        }
        pollCount++;
        rotateMessage();

        fetch(HYDRATE_URL, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        .then(r => r.json())
        .then(data => {
            if (data && Array.isArray(data.missing)) {
                updateSignals(data.missing);
            }
            if (data && data.ready) {
                // All eligibility signals hydrated — reload back to the
                // apply page which will now render the form (or show
                // genuine rule failures, the ones that aren't a
                // data-loading artifact).
                window.location.reload();
                return;
            }
            setTimeout(poll, POLL_INTERVAL_MS);
        })
        .catch(() => {
            // Network blip — keep trying until MAX_POLLS so a
            // transient failure doesn't abandon a slow-but-working
            // worker queue.
            setTimeout(poll, POLL_INTERVAL_MS);
        });
    }

    // Start polling after a short visual settle so the user sees the
    // animation before the first request fires.
    setTimeout(poll, 1500);
})();
</script>
@endsection

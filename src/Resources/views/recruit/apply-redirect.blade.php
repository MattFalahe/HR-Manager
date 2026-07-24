<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trans('hr-manager::recruit.apply_redirect_title') }}</title>
    <meta name="robots" content="noindex">
    @php $navigateUrlRelative = $navigateUrlRelative ?? $applyUrlRelative; @endphp
    <meta http-equiv="refresh" content="3;url={{ $navigateUrlRelative }}">
    <style>
        body {
            background: #0d1117;
            color: #c9d1d9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            text-align: center;
            padding: 3rem 2rem;
            max-width: 480px;
        }
        .spinner {
            display: inline-block;
            width: 48px;
            height: 48px;
            border: 4px solid rgba(255,255,255,0.1);
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.9s linear infinite;
            margin-bottom: 1.5rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        h2 { color: #fff; font-weight: 600; margin: 0 0 0.5rem; font-size: 1.4rem; }
        p  { color: #8b95a5; margin: 0; font-size: 0.95rem; line-height: 1.5; }
        .fallback {
            margin-top: 2rem;
            font-size: 0.85rem;
        }
        .fallback a {
            color: #667eea;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner" aria-hidden="true"></div>
        <h2>{{ trans('hr-manager::recruit.apply_redirect_heading') }}</h2>
        <p>{{ trans('hr-manager::recruit.apply_redirect_body') }}</p>
        <p class="fallback">
            {{ trans('hr-manager::recruit.apply_redirect_fallback_pre') }}
            <a href="{{ $navigateUrlRelative }}">{{ trans('hr-manager::recruit.apply_redirect_fallback_link') }}</a>.
        </p>
    </div>
{{-- Cookie cleanup. See partials/cookie-prune-script.blade.php for the
     mechanism. Runs immediately on page load; the redirect below waits
     120ms so the writes have settled before navigation. --}}
@include('hr-manager::recruit.partials.cookie-prune-script')
<script>
(function() {
    // Small delay lets the cookie cleanup above settle in the browser
    // store before we navigate. 120ms is imperceptible but reliable.
    // Use the RELATIVE path so the browser inherits the actual page
    // scheme (https) — route() can emit http behind a misconfigured
    // proxy, and navigating https→http would block or warn.
    setTimeout(function() {
        window.location.replace({!! json_encode($navigateUrlRelative, JSON_UNESCAPED_SLASHES) !!});
    }, 120);
})();
</script>
</body>
</html>

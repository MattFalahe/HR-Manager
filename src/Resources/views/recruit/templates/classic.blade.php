@php
    $theme = $landing->theme_json ?? [];
    $primary = $theme['primary'] ?? '#667eea';
    $accent  = $theme['accent']  ?? '#764ba2';
    // Hero image is served via a dedicated public route, not Storage::url(),
    // so it works on installs where `php artisan storage:link` was never
    // run (including SeAT's default Docker stack with no host-mounted
    // public dir).
    $hero    = $landing->hero_image_path
        ? route('hr-manager.recruit.hero', ['ticker' => $landing->corp_ticker, 'slug' => $landing->slug])
        : null;
    $corpName = $landing->corp_name ?? ('Corporation #' . $landing->corporation_id);
    $corpId   = $landing->corporation_id;
    $applyUrl = route('hr-manager.recruit.click-apply', ['ticker' => $landing->corp_ticker, 'slug' => $landing->slug]);
    // When the body markdown is empty (or whitespace-only) we skip the
    // content band entirely and let the hero swell to fill the viewport
    // — keeps the page from showing a sparse grey strip below the hero
    // when the director put all their copy in the headline editor.
    $hasBody = !empty(trim(strip_tags((string) ($landing->body_markdown ?? ''))));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $landing->title }} - {{ trans('hr-manager::recruit.recruiting') }}</title>
    <meta property="og:title" content="{{ $landing->title }}">
    <meta property="og:description" content="{{ Str::limit(strip_tags(\Illuminate\Support\Str::markdown($landing->headline ?: ($landing->body_markdown ?? ''))), 200) }}">
    @if($hero)
        <meta property="og:image" content="{{ url($hero) }}">
    @endif
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --hr-primary: {{ $primary }};
            --hr-accent: {{ $accent }};
        }
        html, body { height: 100%; }
        body {
            background: #0d1117;
            color: #c9d1d9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .hero {
            background: linear-gradient(135deg, var(--hr-primary), var(--hr-accent));
            padding: 6rem 2rem 5rem;
            text-align: center;
            position: relative;
            min-height: 55vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            @if($hero) background-image: linear-gradient(135deg, rgba(0,0,0,0.6), rgba(0,0,0,0.3)), url('{{ $hero }}'); background-size: cover; background-position: center; @endif
        }
        /* When there's no body content, the hero swells to fill the
           page so the bottom doesn't show as a sparse grey strip. */
        .hero.hero-full {
            flex: 1 1 auto;
            min-height: calc(100vh - 70px); /* leave the footer visible */
        }
        .hero h1 {
            color: white;
            font-size: 3rem;
            font-weight: 700;
            margin: 0 0 1rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.4);
        }
        .hero .headline {
            color: white;
            font-size: 1.4rem;
            opacity: 0.95;
            margin-bottom: 2rem;
            text-shadow: 0 1px 2px rgba(0,0,0,0.4);
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        /* Headline is now Markdown — reset whatever the parser emits so it
           sits inside the hero band like the original single-line subtitle
           did, but supports multi-paragraph + alignment + lists. */
        .hero .headline p { margin: 0 0 0.6rem; }
        .hero .headline p:last-child { margin-bottom: 0; }
        .hero .headline h1,
        .hero .headline h2,
        .hero .headline h3 { color: white; margin: 0.5rem 0; }
        .hero .headline ul,
        .hero .headline ol { display: inline-block; text-align: left; margin: 0.5rem 0; }
        .hero .headline a { color: white; text-decoration: underline; }
        .hero .headline blockquote {
            border-left: 3px solid rgba(255,255,255,0.6);
            padding-left: 1rem;
            margin: 0.5rem 0;
            opacity: 0.9;
        }
        .hero .headline center { display: block; }
        .hero .headline hr {
            border: 0;
            border-top: 1px solid rgba(255,255,255,0.3);
            margin: 1rem auto;
            max-width: 60%;
        }
        .hero .corp-portrait {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            border: 4px solid white;
            margin-bottom: 1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .apply-cta {
            display: inline-block;
            background: white;
            color: var(--hr-primary);
            font-weight: 700;
            font-size: 1.2rem;
            padding: 1rem 2.5rem;
            border-radius: 999px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .apply-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.3);
            text-decoration: none;
            color: var(--hr-primary);
        }
        .content {
            max-width: 800px;
            margin: 0 auto;
            padding: 4rem 2rem;
            line-height: 1.7;
            font-size: 1.05rem;
            flex: 1 0 auto;
            width: 100%;
            box-sizing: border-box;
        }
        .content h1, .content h2, .content h3 { color: white; margin-top: 2rem; }
        .content a { color: var(--hr-primary); }
        .content code {
            background: rgba(255,255,255,0.08);
            padding: 2px 6px;
            border-radius: 3px;
            color: #ffd580;
        }
        .content blockquote {
            border-left: 4px solid var(--hr-primary);
            padding-left: 1rem;
            color: #8b95a5;
            margin: 1.5rem 0;
        }
        .footer {
            border-top: 1px solid rgba(255,255,255,0.08);
            padding: 1.5rem 2rem;
            text-align: center;
            color: #8b95a5;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .login-note {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 6px;
            padding: 1rem;
            margin-top: 2rem;
            color: #8b95a5;
            font-size: 0.9rem;
            text-align: center;
        }
        /* "Recruiting for X [TICKER]" pill rendered inside the hero
           band so it stays visible when the body content section is
           skipped (no body markdown). Different palette to the
           content-section login-note above so it reads against the
           hero gradient/image. */
        .hero-recruiting-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 2rem;
            padding: 0.65rem 1.25rem;
            background: rgba(0,0,0,0.35);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 999px;
            color: rgba(255,255,255,0.92);
            font-size: 0.9rem;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .hero-recruiting-pill code {
            background: rgba(255,255,255,0.12);
            padding: 1px 6px;
            border-radius: 3px;
            color: #ffd580;
        }
    </style>
</head>
<body>

<section class="hero {{ $hasBody ? '' : 'hero-full' }}">
    <img src="https://images.evetech.net/corporations/{{ $corpId }}/logo?size=128"
         class="corp-portrait" alt="{{ $corpName }}">
    <h1>{{ $landing->title }}</h1>
    @if($landing->headline)
        <div class="headline">{!! \Illuminate\Support\Str::markdown($landing->headline) !!}</div>
    @endif
    <form method="POST" action="{{ $applyUrl }}" style="display:inline;">
        @csrf
        <button type="submit" class="apply-cta"><i class="fas fa-paper-plane"></i> {{ trans('hr-manager::recruit.apply_now') }}</button>
    </form>
    <p style="margin-top: 1rem; color: rgba(255,255,255,0.85); font-size: 0.9rem;">
        <i class="fas fa-info-circle"></i> {{ trans('hr-manager::recruit.login_required_to_apply') }}
    </p>

    {{-- "Recruiting for X [TICKER]" pill. Always rendered inside the
         hero so it stays visible even when the body markdown is empty
         and the content band is skipped. --}}
    <div class="hero-recruiting-pill">
        <i class="fab fa-discord" style="color: #5865F2;"></i>
        Recruiting for <strong>{{ $corpName }}</strong>
        @if($landing->corp_ticker)
            <code>[{{ $landing->corp_ticker }}]</code>
        @endif
    </div>
</section>

@if($hasBody)
    {{-- Only rendered when the body markdown has real content. When
         empty, the hero swells to fill the page via .hero-full above
         so we don't show a sparse grey strip under the hero. --}}
    <div class="content">
        {!! \Illuminate\Support\Str::markdown($landing->body_markdown) !!}
    </div>
@endif

<footer class="footer">
    {!! trans('hr-manager::recruit.footer_credit') !!}
</footer>

{{-- Cookie prune fires on landing-page load. By the time the visitor
     clicks Apply Now, accumulated browser cookies (Cloudflare bot
     tokens, analytics, stale XSRF rotations) have been cleared, so
     the POST to /click-apply doesn't blow past the proxy's header
     limit. The HttpOnly SeAT session cookie + remember_* cookies
     are invisible to JS so they survive. --}}
@include('hr-manager::recruit.partials.cookie-prune-script')

</body>
</html>

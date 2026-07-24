@php
    $theme = $landing->theme_json ?? [];
    $primary = $theme['primary'] ?? '#667eea';
    $corpName = $landing->corp_name ?? ('Corporation #' . $landing->corporation_id);
    $corpId   = $landing->corporation_id;
    $applyUrl = route('hr-manager.recruit.click-apply', ['ticker' => $landing->corp_ticker, 'slug' => $landing->slug]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $landing->title }}</title>
    <meta property="og:title" content="{{ $landing->title }}">
    <meta property="og:description" content="{{ Str::limit(strip_tags(\Illuminate\Support\Str::markdown($landing->headline ?: ($landing->body_markdown ?? ''))), 200) }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        body { background: #fff; color: #1a1a1a; font-family: Georgia, 'Times New Roman', serif; line-height: 1.7; padding: 4rem 1rem; }
        .wrap { max-width: 720px; margin: 0 auto; }
        h1 { font-size: 2.4rem; margin-bottom: 0.5rem; }
        .headline { color: #555; font-style: italic; margin-bottom: 2rem; }
        /* Headline is Markdown — strip nested-p default margins so it sits
           tight under the title. */
        .headline p { margin: 0 0 0.5rem; }
        .headline p:last-child { margin-bottom: 0; }
        .headline h1, .headline h2, .headline h3 { color: #1a1a1a; margin: 0.4rem 0; font-style: normal; }
        .headline center { display: block; }
        .apply { display: inline-block; background: {{ $primary }}; color: white; padding: 0.8rem 2rem; border-radius: 4px; text-decoration: none; font-weight: 600; border: none; cursor: pointer; }
        .apply:hover { color: white; opacity: 0.9; }
        .footer { margin-top: 3rem; padding-top: 2rem; border-top: 1px solid #e5e5e5; color: #888; font-size: 0.85rem; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>{{ $landing->title }}</h1>
    @if($landing->headline)<div class="headline">{!! \Illuminate\Support\Str::markdown($landing->headline) !!}</div>@endif
    @if($landing->body_markdown){!! \Illuminate\Support\Str::markdown($landing->body_markdown) !!}@endif
    <p style="margin-top: 2rem;">
        <form method="POST" action="{{ $applyUrl }}" style="display:inline;">
            @csrf
            <button class="apply" type="submit">{{ trans('hr-manager::recruit.apply_now') }}</button>
        </form>
    </p>
    <p style="color: #888; font-size: 0.85rem; margin-top: 1rem;">{{ trans('hr-manager::recruit.login_required_to_apply') }}</p>
    <div class="footer">{{ $corpName }} @if($landing->corp_ticker)[{{ $landing->corp_ticker }}]@endif · {!! trans('hr-manager::recruit.footer_credit') !!}</div>
</div>
{{-- See classic.blade.php for context — pre-emptive cookie cleanup
     so the Apply POST doesn't hit the proxy's header limit. --}}
@include('hr-manager::recruit.partials.cookie-prune-script')
</body>
</html>

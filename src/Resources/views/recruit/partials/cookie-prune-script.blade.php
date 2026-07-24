{{--
    Shared client-side cookie prune script.

    Runs immediately on script load. Iterates document.cookie and clears
    everything that isn't on the keep-list, trying every plausible
    (path, domain) combination so attributes set with different scopes
    still get cleared.

    Caller must pass `$sessionName` (the value of config('session.cookie'))
    so we don't accidentally nuke SeAT's session cookie — the name is
    install-specific (slug(APP_NAME) . '_session', usually seat_session).

    Embedded on:
      - recruit/templates/classic.blade.php — public landing, runs
        BEFORE the Apply Now button is even clicked, so the POST to
        /click-apply goes out with already-minimal cookies
      - recruit/templates/minimal.blade.php — same
      - recruit/apply-redirect.blade.php — defence in depth between
        click-apply and the SSO chain
      - recruit/hydrating.blade.php — after SSO callback, before the
        applicant ever reaches the apply form

    HttpOnly cookies (the SeAT session cookie + remember_*) are invisible
    to JS so they survive untouched — the user stays logged in.
--}}
<script>
(function () {
    var SESSION_COOKIE_NAME = {!! json_encode($sessionName ?? config('session.cookie', 'laravel_session')) !!};
    var KEEP_EXACT = [SESSION_COOKIE_NAME, 'laravel_session', 'XSRF-TOKEN'];
    var KEEP_PATTERNS = [/_session$/, /^remember_/];

    function isKept(name) {
        if (KEEP_EXACT.indexOf(name) !== -1) return true;
        for (var i = 0; i < KEEP_PATTERNS.length; i++) {
            if (KEEP_PATTERNS[i].test(name)) return true;
        }
        return false;
    }

    function clearCookie(name) {
        var host = location.hostname;
        var bare = host.replace(/^www\./, '');
        // Cookies are deleted by re-setting them with Max-Age=0 and
        // matching (Path, Domain) attributes. Browsers silently ignore
        // writes where the attributes don't match the original cookie,
        // so we try the most common combinations (root + segments of
        // the current path, plus bare/dot-prefixed domains). Brute
        // force is the only reliable approach since the browser
        // doesn't expose the original attributes.
        var pathSegments = location.pathname.split('/').filter(Boolean);
        var paths = ['/'];
        // Build cumulative path prefixes: /a, /a/b, /a/b/c
        for (var i = 0; i < pathSegments.length && i < 4; i++) {
            paths.push('/' + pathSegments.slice(0, i + 1).join('/'));
        }
        var domains = ['', host, '.' + host, bare, '.' + bare];
        paths.forEach(function (path) {
            domains.forEach(function (domain) {
                var attr = name + '=; Max-Age=0; Path=' + path;
                if (domain) attr += '; Domain=' + domain;
                document.cookie = attr;
            });
        });
    }

    try {
        var raw = document.cookie || '';
        raw.split(';').forEach(function (c) {
            var name = c.split('=')[0].trim();
            if (!name || isKept(name)) return;
            clearCookie(name);
        });
    } catch (e) {
        // Cookies inaccessible (3rd-party iframe sandbox, restrictive
        // CSP) — silently no-op. The page still works; only the
        // cookie cleanup is skipped.
    }
})();
</script>

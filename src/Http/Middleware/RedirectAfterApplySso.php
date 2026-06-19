<?php

namespace HrManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Catches a SeAT post-SSO redirect that overshot the recruitment apply
 * page and steers the user back.
 *
 * Why this exists: SeAT's web layout fires an AJAX poll to
 * `/queue/short-status` every few seconds for users with the
 * `global.queue_manager` perm. That endpoint is inside SeAT's
 * `auth`-protected route group. If the poll happens to fire mid-SSO
 * roundtrip when the user's session is briefly unauthenticated, the
 * AJAX request triggers SeAT's auth middleware which overwrites
 * `session('url.intended')` with `/queue/short-status` — the URL of
 * the AJAX endpoint, not the page the user was actually on. SeAT's
 * SSO callback then `redirect()->intended()`s the user to that
 * AJAX endpoint, where their browser shows the bare JSON response.
 *
 * Mechanism: when an applicant clicks Apply Now we stash
 *   session('hr_apply_redirect_url')        = the apply URL
 *   session('hr_apply_redirect_expires_at') = now + 5 minutes
 *
 * This middleware (registered on the global `web` group via the
 * service provider) checks both values on every request. If the
 * user landed on a known SeAT default landing page (/, /home,
 * /queue/short-status) within the 5-minute window, redirect them
 * to the stored apply URL and clear the session keys.
 *
 * Outside the window — or on any URL that isn't a known SeAT
 * default landing — the middleware does nothing. We never hijack
 * a deliberate navigation; the only cases that match are the
 * "you definitely didn't mean to go here" ones.
 */
class RedirectAfterApplySso
{
    /**
     * SeAT default landing paths the SSO callback might dump us on
     * when the intended URL has been clobbered. Matched against the
     * request path (no query string). Keep this list narrow — every
     * entry is a URL we KNOW the applicant didn't deliberately type.
     */
    private const SEAT_DEFAULT_LANDINGS = [
        '/',
        'home',
        'queue/short-status',
    ];

    public function handle(Request $request, Closure $next)
    {
        $target = $request->session()->get('hr_apply_redirect_url');
        $debug = (bool) config('hr-manager.recruitment.apply_sso_debug', false);

        // Fast path: no apply-in-progress flag, nothing to do.
        if (!$target) {
            if ($debug && $this->isSeatDefaultLanding($request)) {
                // Specifically log when we ARE on a default landing
                // and have NO target — this is the symptom of the
                // session keys not surviving the SSO roundtrip.
                Log::info('[HR Manager] RedirectAfterApplySso: on SeAT default landing but no session target', [
                    'path'       => $request->path(),
                    'session_id' => $request->session()->getId(),
                    'authed'     => auth()->check(),
                ]);
            }
            return $next($request);
        }

        $expiresAt = (int) $request->session()->get('hr_apply_redirect_expires_at', 0);

        if ($debug) {
            Log::info('[HR Manager] RedirectAfterApplySso: hit', [
                'path'        => $request->path(),
                'method'      => $request->method(),
                'target'      => $target,
                'expires_at'  => $expiresAt,
                'now'         => now()->timestamp,
                'is_landing'  => $this->isSeatDefaultLanding($request),
                'session_id'  => $request->session()->getId(),
            ]);
        }

        // Window expired — clear the session keys and let the
        // request through normally.
        if ($expiresAt > 0 && now()->timestamp > $expiresAt) {
            $request->session()->forget(['hr_apply_redirect_url', 'hr_apply_redirect_expires_at']);
            return $next($request);
        }

        // Already on the apply page (intended URL preserved correctly,
        // or middleware redirected us here on a previous request) —
        // clear the flag and continue.
        if ($this->isApplyTarget($request, $target)) {
            $request->session()->forget(['hr_apply_redirect_url', 'hr_apply_redirect_expires_at']);
            return $next($request);
        }

        // Only intervene when the request landed on one of the known
        // SeAT default pages. Anywhere else we assume the user
        // navigated deliberately and leave them alone.
        if (!$this->isSeatDefaultLanding($request)) {
            return $next($request);
        }

        // Don't redirect on POSTs / AJAX / non-HTML — those are
        // background traffic, never the user's browser address bar.
        if (!$request->isMethod('GET') || $request->ajax() || $request->wantsJson()) {
            return $next($request);
        }

        // Steer them to the apply page and clear the flag so we don't
        // loop on subsequent requests.
        $request->session()->forget(['hr_apply_redirect_url', 'hr_apply_redirect_expires_at']);
        Log::info('[HR Manager] RedirectAfterApplySso: rescuing user to apply URL', [
            'from' => $request->fullUrl(),
            'to'   => $target,
        ]);
        return redirect()->to($target);
    }

    private function isApplyTarget(Request $request, string $target): bool
    {
        $currentBase = rtrim($request->url(), '/');
        $targetBase  = rtrim(strtok($target, '?'), '/');
        return $currentBase === $targetBase;
    }

    private function isSeatDefaultLanding(Request $request): bool
    {
        $path = trim($request->path(), '/');
        // Request::path() returns '/' as '' so '/' lookups need that
        // shape; everything else is the trimmed string.
        if ($path === '' && in_array('/', self::SEAT_DEFAULT_LANDINGS, true)) {
            return true;
        }
        return in_array($path, self::SEAT_DEFAULT_LANDINGS, true);
    }
}

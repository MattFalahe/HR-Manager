<?php

namespace HrManager\Http\Middleware;

use Closure;
use HrManager\Services\AuditService;
use Illuminate\Http\Request;

/**
 * Logs internal activity to the HR audit log with no per-controller call sites,
 * for BOTH reads and writes. Attached to the `/hr-manager` route group only
 * (never the public `/recruit` funnel).
 *
 *   - GET  → a "view" row (which page / record was opened).
 *   - POST/PUT/PATCH/DELETE → a generic "action" row, unless the request failed
 *     or the route logs itself with richer context (AuditService::SELF_LOGGED_ROUTES).
 *
 * Runs AFTER $next so a denied request (the inner `can:` middleware throws) is
 * never logged. Writes are gated on success — see writeFailed(): a 4xx/5xx, an
 * explicit `error` flash, or a validation `errors` bag all mean "don't log".
 * The whole thing is wrapped so a logging failure can never affect the response,
 * and the AuditService methods no-op when the feature is off.
 */
class AuditViewLogger
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;

            if ($request->isMethod('GET')) {
                if ($status >= 200 && $status < 300) {
                    app(AuditService::class)->logView($request);
                }
            } elseif (!$this->writeFailed($request, $status)) {
                app(AuditService::class)->logAction($request);
            }
        } catch (\Throwable $e) {
            // Auditing is best-effort; never let it break the response.
        }

        return $response;
    }

    /**
     * Did this mutating request fail? A 4xx/5xx response, an explicit `error`
     * flash, or a non-empty validation `errors` bag all count as failure, so a
     * rejected or invalid action is never recorded as if it happened.
     */
    private function writeFailed(Request $request, int $status): bool
    {
        if ($status >= 400) {
            return true;
        }
        try {
            if ($request->hasSession()) {
                $session = $request->session();
                if ($session->get('error')) {
                    return true;
                }
                $errors = $session->get('errors');
                if ($errors && method_exists($errors, 'any') && $errors->any()) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // If we can't tell, err toward logging (below).
        }
        return false;
    }
}

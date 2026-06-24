<?php

namespace HrManager\Http\Controllers;

use HrManager\Models\Application;
use HrManager\Models\RecruitmentLanding;
use HrManager\Services\ApplicationService;
use HrManager\Services\EligibilityService;
use HrManager\Services\RecruitmentService;
use HrManager\Services\RecruitmentSsoService;
use HrManager\Services\SeatConnectorService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Public + auth-gated recruitment landing flow.
 *
 *   show()         - unauthenticated. Renders the landing page template.
 *                    Records a page view + lazy publish-status check.
 *   clickApply()   - unauthenticated POST. Marks the visit as a click,
 *                    redirects to apply() which triggers SeAT auth if needed.
 *   apply()        - auth-required. Runs eligibility check + renders form.
 *   submitApply()  - auth-required POST. Creates application via existing
 *                    ApplicationService. Stamps landing_id for attribution.
 *   applied()      - auth-required. Confirmation page with mode-specific
 *                    post-submission CTA (Discord invite / SeAT Connector /
 *                    custom).
 */
class PublicRecruitmentController extends Controller
{
    public function show(Request $request, string $ticker, string $slug, RecruitmentService $service)
    {
        $landing = $service->resolveByTickerAndSlug($ticker, $slug);
        if (!$landing || !$landing->is_published) {
            abort(404);
        }

        $service->recordView($landing, $request, clickedApply: false);

        $template = $this->resolveTemplate($landing->template_key);

        // Pass the configured session cookie name so the cookie-prune
        // partial embedded in the landing template can keep-list it.
        // Without this, the prune script would nuke the SeAT session
        // cookie because SeAT's name (slug(APP_NAME) . '_session') is
        // not 'laravel_session'.
        $sessionName = config('session.cookie', 'laravel_session');

        return view($template, compact('landing', 'sessionName'));
    }

    public function clickApply(Request $request, string $ticker, string $slug, RecruitmentService $service)
    {
        $landing = $service->resolveByTickerAndSlug($ticker, $slug);
        if (!$landing || !$landing->is_published) {
            abort(404);
        }

        // Record the click. The actual redirect happens via the apply route,
        // which auth middleware kicks the visitor through SeAT login if needed.
        $service->recordView($landing, $request, clickedApply: true);

        // Absolute URL for the session stash (the middleware compares
        // against $request->fullUrl()), relative URL for the JS
        // location.replace below so it inherits the browser's actual
        // scheme even when route() emits http:// behind a
        // mis-configured proxy.
        $applyUrl         = route('hr-manager.recruit.apply', ['ticker' => $ticker, 'slug' => $slug]);
        $applyUrlRelative = route('hr-manager.recruit.apply', ['ticker' => $ticker, 'slug' => $slug], false);

        // Stash a 5-minute redirect-back-to-apply hint that the
        // RedirectAfterApplySso middleware picks up if SeAT's post-SSO
        // intended URL ends up clobbered (e.g. by the queue/short-status
        // AJAX poll from the home layout, see the middleware docblock).
        // Honoured ONLY when the user lands on a known SeAT default
        // landing page within the window — never hijacks deliberate
        // navigation.
        $request->session()->put('hr_apply_redirect_url', $applyUrl);
        $request->session()->put('hr_apply_redirect_expires_at', now()->addMinutes(5)->timestamp);

        // SSO scope-profile steering. When the operator chose a recruitment
        // SSO profile AND the visitor isn't logged in yet, send the first
        // login through /eve/profile/{name} so the applicant grants the
        // scopes that profile requests (instead of SeAT's default profile,
        // which is all the plain auth-middleware login would use). We set
        // url.intended so SeAT's post-SSO redirect()->intended() returns to
        // the apply form; the hr_apply_redirect backstop above still covers
        // the account-confirmation branch that lands on home. Authenticated
        // visitors skip SSO entirely (already have tokens), and with no
        // profile chosen this is a no-op — navigate straight to apply.
        $navigateUrlRelative = $applyUrlRelative;
        $profileName = app(RecruitmentSsoService::class)->routingProfileName();
        if ($profileName !== null && !auth()->check()) {
            $request->session()->put('url.intended', $applyUrl);
            $navigateUrlRelative = route('seatcore::auth.eve.profile', ['profile' => $profileName], false);
        }

        $request->session()->save();

        if (config('hr-manager.recruitment.apply_sso_debug')) {
            \Illuminate\Support\Facades\Log::info('[HR Manager] clickApply: session stashed', [
                'apply_url'  => $applyUrl,
                'expires_at' => $request->session()->get('hr_apply_redirect_expires_at'),
                'session_id' => $request->session()->getId(),
                'cookies'    => array_keys($request->cookies->all()),
            ]);
        }

        // Render a tiny interstitial that prunes browser-accessible
        // cookies before the SSO chain begins. SeAT installs accumulate
        // stale cookies (XSRF rotations, Cloudflare bot tokens,
        // analytics, etc) that push the request headers over the front
        // proxy's per-request limit on the EVE SSO redirect, returning
        // a 400 "Request Too Long".
        //
        // The keep-list must include SeAT's ACTUAL session cookie name,
        // which defaults to slug(APP_NAME) . '_session' — usually
        // 'seat_session', NOT 'laravel_session'. Clearing the real
        // session cookie blows away our redirect hint and logs the
        // user out — both critical to the post-SSO recovery path.
        return response()->view('hr-manager::recruit.apply-redirect', [
            'applyUrl'            => $applyUrl,
            'applyUrlRelative'    => $applyUrlRelative,
            'navigateUrlRelative' => $navigateUrlRelative,
            'sessionName'         => config('session.cookie', 'laravel_session'),
        ]);
    }

    public function apply(Request $request, string $ticker, string $slug, RecruitmentService $service, EligibilityService $eligibility)
    {
        $landing = $service->resolveByTickerAndSlug($ticker, $slug);
        if (!$landing || !$landing->is_published) {
            abort(404);
        }

        $user = auth()->user();
        $characterId = $user->main_character_id;
        if (!$characterId) {
            return view('hr-manager::recruit.apply-no-character', compact('landing'));
        }

        $template = $landing->defaultTemplate;
        if (!$template || !$template->is_active) {
            return view('hr-manager::recruit.apply-no-template', compact('landing'));
        }
        $template->load('questions');

        // Just-after-SSO race: SeAT's character info / skills / corp
        // history jobs may not have run yet, so eligibility evaluation
        // would falsely reject the candidate ("you have unknown SP"
        // etc). Detect missing tables and render the hydrating screen
        // instead of running eligibility against half-loaded data.
        //
        // The ?manual_review=1 escape hatch (linked from the hydrating
        // screen's stalled banner after the 3-minute timeout) bypasses
        // this gate so applicants whose data SeAT can't load can still
        // submit for human review.
        $manualReview = $request->boolean('manual_review');
        $readiness = $eligibility->dataReady($landing, $characterId);
        if (!$readiness['ready'] && !$manualReview) {
            // Fire the hydration jobs ONCE per session — re-firing on
            // every refresh would queue duplicate ESI work. The
            // hydration_triggered flag is cleared by the success-path
            // redirect on a fully-hydrated apply visit.
            $sessionKey = "hr-manager.hydration_triggered.{$characterId}.{$landing->id}";
            if (!session()->has($sessionKey)) {
                $eligibility->triggerHydration($characterId);
                session()->put($sessionKey, now()->timestamp);
            }
            return view('hr-manager::recruit.hydrating', [
                'landing'    => $landing,
                'missing'    => $readiness['missing'],
                'ticker'     => $ticker,
                'slug'       => $slug,
            ]);
        }

        // Data ready — clear the per-session retrigger flag so a
        // FUTURE apply visit on this character refires hydration if
        // SeAT data goes stale again.
        session()->forget("hr-manager.hydration_triggered.{$characterId}.{$landing->id}");

        $eligibilityResult = $eligibility->evaluate($landing, $characterId, $user->id);

        // Detect "manual review needed because SeAT data didn't finish
        // loading" so the apply view can render distinct copy (and
        // auto-tick the override checkbox) instead of treating it like
        // a normal rule violation.
        $hasDataMissingFailure = false;
        foreach ($eligibilityResult['failures'] ?? [] as $f) {
            if (!empty($f['data_missing'])) {
                $hasDataMissingFailure = true;
                break;
            }
        }

        $applicationService = app(ApplicationService::class);
        $hasPending = $applicationService->hasPendingApplication($characterId);

        // Surface a "Link Discord via SeAT Connector" button when the
        // require_seat_connector rule failed AND warlof/seat-connector
        // is installed. Falls back to the landing's discord_invite_url
        // when Connector isn't installed but the operator configured a
        // server invite. Both open in a new tab so the applicant keeps
        // the form state and can come back to refresh after linking.
        $connectorAvailable = app(SeatConnectorService::class)->isAvailable();
        $connectorLinkUrl = $connectorAvailable ? $this->connectorIdentitiesUrl() : null;

        // Characters already linked to this applicant's SeAT account.
        // Surfaced on the form so the applicant can see what recruiters
        // will see, and link more alts (the "Link another character"
        // button) for a complete assessment. Main character sorts first.
        $linkedCharacters = $this->resolveLinkedCharacters((int) $user->id, (int) $characterId);

        // Returning-member check: characters HR already associates with this
        // human that they have NOT re-authed on this apply (registered before,
        // e.g. while previously a member). Drives the "unauthed characters
        // found" warning so they re-add everything. Empty for a fresh applicant.
        $unauthedCharacters = app(\HrManager\Services\PlayerIdentityResolver::class)
            ->unauthedKnownCharacters((int) $characterId, array_column($linkedCharacters, 'character_id'));

        return view('hr-manager::recruit.apply', compact(
            'landing',
            'template',
            'eligibilityResult',
            'hasPending',
            'manualReview',
            'hasDataMissingFailure',
            'connectorAvailable',
            'connectorLinkUrl',
            'linkedCharacters',
            'unauthedCharacters'
        ));
    }

    /**
     * Characters attached to a SeAT user, main first. Read straight from
     * refresh_tokens (the canonical char↔user link) joined to
     * character_infos for display names. Returns a plain array of
     * ['character_id','name','is_main'].
     */
    private function resolveLinkedCharacters(int $userId, int $mainCharacterId): array
    {
        try {
            return DB::table('refresh_tokens as rt')
                ->where('rt.user_id', $userId)
                ->whereNull('rt.deleted_at')
                ->leftJoin('character_infos as ci', 'ci.character_id', '=', 'rt.character_id')
                ->get(['rt.character_id', 'ci.name'])
                ->map(function ($r) use ($mainCharacterId) {
                    return [
                        'character_id' => (int) $r->character_id,
                        'name'         => $r->name ?: ('Character #' . $r->character_id),
                        'is_main'      => (int) $r->character_id === $mainCharacterId,
                    ];
                })
                ->sortByDesc('is_main')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [[
                'character_id' => $mainCharacterId,
                'name'         => auth()->user()->name ?? ('Character #' . $mainCharacterId),
                'is_main'      => true,
            ]];
        }
    }

    /**
     * Kick off SeAT's add-character SSO from the apply form. Stores the
     * apply URL as the intended target so SeAT's post-SSO
     * redirect()->intended() returns the applicant to the form with the
     * freshly-linked alt now attached.
     */
    public function linkCharacter(Request $request, string $ticker, string $slug, RecruitmentService $service)
    {
        $landing = $service->resolveByTickerAndSlug($ticker, $slug);
        if (!$landing || !$landing->is_published) {
            abort(404);
        }

        session()->put('url.intended', route('hr-manager.recruit.apply', [
            'ticker' => $landing->corp_ticker,
            'slug'   => $landing->slug,
        ]));

        // Route through the chosen scope profile when set (SeAT validates
        // the name); falls back to the plain /eve add-character flow. Note
        // SeAT reuses the main character's scopes for an already-authed
        // link, so the profile mostly matters for the first application
        // login — this keeps the entry point consistent regardless.
        $profileName = app(RecruitmentSsoService::class)->routingProfileName();

        return $profileName !== null
            ? redirect()->route('seatcore::auth.eve.profile', ['profile' => $profileName])
            : redirect()->route('seatcore::auth.eve');
    }

    /**
     * JSON endpoint the hydrating screen polls every few seconds.
     * Returns `{ready: bool, missing: [...]}` — the screen reloads
     * the apply page when ready becomes true.
     */
    public function checkHydration(Request $request, string $ticker, string $slug, RecruitmentService $service, EligibilityService $eligibility)
    {
        $landing = $service->resolveByTickerAndSlug($ticker, $slug);
        if (!$landing || !$landing->is_published) {
            return response()->json(['ready' => false, 'missing' => [], 'error' => 'landing_not_found'], 404);
        }
        $user = auth()->user();
        $characterId = $user->main_character_id;
        if (!$characterId) {
            return response()->json(['ready' => false, 'missing' => [], 'error' => 'no_main_character'], 200);
        }
        $readiness = $eligibility->dataReady($landing, $characterId);
        return response()->json($readiness);
    }

    public function submitApply(Request $request, string $ticker, string $slug, RecruitmentService $service, EligibilityService $eligibility)
    {
        $landing = $service->resolveByTickerAndSlug($ticker, $slug);
        if (!$landing || !$landing->is_published) {
            abort(404);
        }

        $request->validate([
            'answers' => 'required|array',
            'eligibility_override' => 'nullable|boolean',
        ]);

        $user = auth()->user();
        $characterId = $user->main_character_id;
        if (!$characterId) {
            return redirect()->back()->with('error', 'Link an EVE character before applying.');
        }

        $applicationService = app(ApplicationService::class);
        if ($applicationService->hasPendingApplication($characterId)) {
            return redirect()->back()->with('error', trans('hr-manager::applications.apply_already_pending'));
        }

        $template = $landing->defaultTemplate;
        if (!$template) {
            return redirect()->back()->with('error', 'This landing has no form template configured.');
        }

        // Stale-tab guard: if a user had the apply form open from a
        // previous visit and only NOW submits, character data might
        // still not be loaded. Reroute to the hydrating screen instead
        // of evaluating eligibility against half-loaded data and
        // wrongly rejecting them — UNLESS they ticked the manual-
        // review override, in which case let it through (data-missing
        // failures will be recorded for the recruiter to action).
        $readiness = $eligibility->dataReady($landing, $characterId);
        if (!$readiness['ready'] && !$request->boolean('eligibility_override')) {
            return redirect()->route('hr-manager.recruit.apply', [
                'ticker' => $ticker,
                'slug'   => $slug,
            ]);
        }

        // Eligibility — passes go through normally; failures require the
        // operator-permitted escape hatch checkbox per the v1.0.0 design.
        $eligibilityResult = $eligibility->evaluate($landing, $characterId, $user->id);
        if (!$eligibilityResult['passed'] && !$request->boolean('eligibility_override')) {
            return redirect()->back()->with('error', trans('hr-manager::recruit.eligibility_failed'));
        }

        $application = $applicationService->submitApplication(
            $characterId,
            $template->id,
            $landing->corporation_id, // corp comes from landing, not applicant's affiliation
            $request->answers,
            $user->id
        );

        // Mark eligibility result + landing attribution on the application
        $application->update([
            'eligibility_passed'   => $eligibilityResult['passed'],
            'eligibility_failures' => $eligibilityResult['failures'],
            'landing_id'           => $landing->id,
        ]);

        $landing->incrementCounters(['application_count']);

        return redirect()->route('hr-manager.recruit.applied', [
            'ticker'        => $ticker,
            'slug'          => $slug,
            'applicationId' => $application->id,
        ]);
    }

    public function applied(string $ticker, string $slug, int $applicationId, RecruitmentService $service)
    {
        $landing = $service->resolveByTickerAndSlug($ticker, $slug);
        if (!$landing) {
            abort(404);
        }

        $application = Application::findOrFail($applicationId);

        // Only the applicant can see their own confirmation
        if ((int) $application->character_id !== (int) auth()->user()->main_character_id) {
            abort(403);
        }

        // The Connector identity page lives on this same SeAT instance, so
        // derive the URL from the framework's named route by default (with
        // hand-built fallbacks). The optional config override stays for
        // unusual reverse-proxy setups, but with it empty (the default) the
        // seat_connector mode works out of the box instead of silently
        // rendering no button.
        $seatConnectorUrl = $this->connectorIdentitiesUrl();

        // Only surface the Connector CTA when the connector is actually
        // installed; otherwise the link would 404. When the operator picked
        // seat_connector mode but it's absent, the view falls back to the
        // neutral "a recruiter will be in touch" copy.
        $connectorAvailable = app(SeatConnectorService::class)->isAvailable();

        return view('hr-manager::recruit.applied', compact(
            'landing',
            'application',
            'seatConnectorUrl',
            'connectorAvailable'
        ));
    }

    /**
     * Resolve the SeAT Connector identity-page URL.
     *
     * Precedence:
     *   1. The configured base override (hr-manager.recruitment.
     *      seat_connector_base_url) for unusual reverse-proxy setups.
     *   2. The framework's named route `seat-connector.identities` when the
     *      Connector is installed — uses its real registered path rather
     *      than a hand-built string.
     *   3. A hand-built `/seat-connector/identities` on this install as the
     *      final fallback.
     *
     * Mirrors the named-route-with-fallback pattern used for SeAT character
     * deep links, so a future Connector route change is picked up for free.
     */
    private function connectorIdentitiesUrl(): string
    {
        $configured = trim((string) config('hr-manager.recruitment.seat_connector_base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/') . '/seat-connector/identities';
        }

        if (Route::has('seat-connector.identities')) {
            return route('seat-connector.identities');
        }

        return url('/seat-connector/identities');
    }

    /**
     * Stream a landing's hero image directly from the configured upload
     * disk. Sidesteps `php artisan storage:link` entirely: some Docker
     * stacks (including SeAT's default) mount over `public/storage` or
     * never run the link command, leaving Storage::url() pointing at a
     * 404. Streaming via the framework works regardless of the symlink
     * state and lets us add proper cache headers + a not-found fallback.
     */
    public function hero(string $ticker, string $slug, RecruitmentService $service)
    {
        $landing = $service->resolveByTickerAndSlug($ticker, $slug);
        if (!$landing || !$landing->hero_image_path) {
            abort(404);
        }
        // Note: no `is_published` gate. The image is not sensitive (it's
        // the hero asset that will be public anyway), and gating it would
        // break the admin's preview thumbnail on draft landings. Anyone
        // who can enumerate ticker+slug can see it; the public landing
        // page itself is where the publish gate is enforced.

        $disk = config('hr-manager.recruitment.upload_disk', 'public');
        try {
            if (!Storage::disk($disk)->exists($landing->hero_image_path)) {
                abort(404);
            }
            $response = Storage::disk($disk)->response(
                $landing->hero_image_path,
                null,
                [
                    'Cache-Control' => 'public, max-age=86400',
                    'X-Robots-Tag'  => 'noindex',
                ]
            );
            return $response;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] hero image stream failed: ' . $e->getMessage());
            abort(404);
        }
    }

    /**
     * Map a template_key to its Blade view path. Publishable so operators can
     * override by copying to resources/views/vendor/hr-manager/recruit/.
     */
    private function resolveTemplate(string $key): string
    {
        $valid = in_array($key, RecruitmentLanding::ALL_TEMPLATES, true)
            ? $key
            : RecruitmentLanding::TEMPLATE_CLASSIC;
        return "hr-manager::recruit.templates.{$valid}";
    }
}

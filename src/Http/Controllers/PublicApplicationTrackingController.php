<?php

namespace HrManager\Http\Controllers;

use HrManager\Models\Application;
use HrManager\Models\Setting;
use HrManager\Services\ApplicationService;
use Illuminate\Routing\Controller;

/**
 * Public, no-auth applicant-facing progress page.
 *
 * Renders a curated, low-noise summary of an application keyed off the
 * unguessable tracking_token. Applicants can bookmark / share the URL
 * without ever logging into SeAT.
 *
 * What this view shows:
 *   - Character portrait + name
 *   - "Applied to: [TICKER] CorpName"
 *   - Current status badge
 *   - Status timeline (transitions + timestamps only — no comments)
 *   - Decided + Joined-corp timestamps when set
 *   - The applicant's own submitted answers (so they can verify what
 *     they sent)
 *
 * What it never shows:
 *   - Notes (private or public)
 *   - Recruiter comments attached to status transitions
 *   - Handler roster / internal collaboration data
 *   - Eligibility internals
 */
class PublicApplicationTrackingController extends Controller
{
    public function track(string $token)
    {
        // Token length sanity — short strings can't possibly match the
        // 48-char base62 slugs we generate, so reject without hitting
        // the DB to make brute-force more expensive.
        if (strlen($token) < 16) {
            abort(404);
        }

        $application = Application::with([
            'character',
            'answers',
            'statusHistory',
            'landing',
        ])
            ->where('tracking_token', $token)
            ->firstOrFail();

        // Resolve corp name via SeAT's CorporationInfo without trusting
        // the model relation chain (corporation_id lives on the app,
        // not joined via a belongsTo).
        $corporationName = \Seat\Eveapi\Models\Corporation\CorporationInfo::where(
            'corporation_id',
            $application->corporation_id
        )->value('name');

        $corporationTicker = \Seat\Eveapi\Models\Corporation\CorporationInfo::where(
            'corporation_id',
            $application->corporation_id
        )->value('ticker');

        return view('hr-manager::recruit.track', [
            'application'        => $application,
            'corporationName'    => $corporationName,
            'corporationTicker'  => $corporationTicker,
            // Offer the self-withdraw button only when the operator allows it
            // AND the application is still open (a closed app can't transition).
            'canWithdraw'        => $this->withdrawalAllowed()
                && in_array($application->status, ['applied', 'under_review', 'interview'], true),
        ]);
    }

    /**
     * Applicant self-withdrawal, initiated from the tracking page. Gated by the
     * `allow_withdrawal` setting and authenticated solely by the unguessable
     * tracking token (no SeAT login). Flips the application to 'withdrawn' via
     * the service so the same close-side effects (notify, event, access revoke)
     * fire as a recruiter-driven withdrawal.
     */
    public function withdraw(string $token, ApplicationService $applications)
    {
        if (strlen($token) < 16) {
            abort(404);
        }

        if (!$this->withdrawalAllowed()) {
            abort(403);
        }

        $application = Application::where('tracking_token', $token)->firstOrFail();

        $ok = $applications->applicantWithdraw($application);

        return redirect()
            ->route('hr-manager.recruit.track', $token)
            ->with(
                $ok ? 'success' : 'error',
                trans('hr-manager::recruit.' . ($ok ? 'withdraw_done' : 'withdraw_failed'))
            );
    }

    /** The `allow_withdrawal` setting (Settings → General). */
    private function withdrawalAllowed(): bool
    {
        return (bool) Setting::getValue(
            'allow_withdrawal',
            config('hr-manager.applications.allow_withdrawal', true)
        );
    }
}

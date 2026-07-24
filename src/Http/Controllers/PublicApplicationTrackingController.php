<?php

namespace HrManager\Http\Controllers;

use HrManager\Models\Application;
use HrManager\Models\Setting;
use HrManager\Services\ApplicationService;
use Illuminate\Http\Request;
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
            // Applicant-facing timeline excludes voided (director-corrected)
            // transitions, so a mistaken status never shows to the applicant.
            'publicStatusHistory',
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

        // Outstanding step: link Discord via SeAT Connector. Surfaced only when
        // the Connector is installed, the applicant's account has NO linked
        // Discord identity, and the application is still live (a rejected /
        // withdrawn applicant isn't joining, so nothing to nudge).
        $connector = app(\HrManager\Services\SeatConnectorService::class);
        $connectorAvailable = $connector->isAvailable();
        $discordLinked = false;
        if ($connectorAvailable) {
            $identity = $connector->getIdentityForCharacter((int) $application->character_id);
            $discordLinked = !empty($identity['available']) && !empty($identity['connector_id']);
        }
        $showDiscordStep = $connectorAvailable
            && !$discordLinked
            && !in_array($application->status, ['rejected', 'withdrawn'], true);

        return view('hr-manager::recruit.track', [
            'application'        => $application,
            'corporationName'    => $corporationName,
            'corporationTicker'  => $corporationTicker,
            'showDiscordStep'    => $showDiscordStep,
            'connectorLinkUrl'   => $showDiscordStep ? $connector->identitiesUrl() : null,
            // Offer the self-withdraw button only when the operator allows it
            // AND the application is still open (a closed app can't transition).
            'canWithdraw'        => $this->withdrawalAllowed()
                && in_array($application->status, ['applied', 'under_review', 'interview'], true),
            // Whether the withdraw form should ask for a reason, and whether it's
            // mandatory (Settings → General).
            'reasonEnabled'      => $this->reasonEnabled(),
            'reasonRequired'     => $this->reasonRequired(),
        ]);
    }

    /**
     * Applicant self-withdrawal, initiated from the tracking page. Gated by the
     * `allow_withdrawal` setting and authenticated solely by the unguessable
     * tracking token (no SeAT login). Flips the application to 'withdrawn' via
     * the service so the same close-side effects (notify, event, access revoke)
     * fire as a recruiter-driven withdrawal.
     */
    public function withdraw(string $token, Request $request, ApplicationService $applications)
    {
        if (strlen($token) < 16) {
            abort(404);
        }

        if (!$this->withdrawalAllowed()) {
            abort(403);
        }

        $application = Application::where('tracking_token', $token)->firstOrFail();

        $reason = trim((string) $request->input('withdraw_reason', ''));

        // When the operator requires a reason, an empty submission bounces back
        // to the tracking page with a message rather than silently withdrawing.
        if ($this->reasonEnabled() && $this->reasonRequired() && $reason === '') {
            return redirect()
                ->route('hr-manager.recruit.track', $token)
                ->with('error', trans('hr-manager::recruit.withdraw_reason_required'));
        }

        // Only forward a reason when the feature is on — ignore any stray field
        // an installation with the feature off might receive.
        $ok = $applications->applicantWithdraw(
            $application,
            $this->reasonEnabled() ? ($reason !== '' ? $reason : null) : null
        );

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

    /** Ask the applicant for a withdrawal reason (Settings → General). */
    private function reasonEnabled(): bool
    {
        return (bool) Setting::getValue(
            'withdrawal_reason_enabled',
            config('hr-manager.applications.withdrawal_reason_enabled', false)
        );
    }

    /** Make the withdrawal reason mandatory (only meaningful when enabled). */
    private function reasonRequired(): bool
    {
        return (bool) Setting::getValue(
            'withdrawal_reason_required',
            config('hr-manager.applications.withdrawal_reason_required', false)
        );
    }
}

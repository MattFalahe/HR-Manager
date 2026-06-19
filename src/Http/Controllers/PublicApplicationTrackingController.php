<?php

namespace HrManager\Http\Controllers;

use HrManager\Models\Application;
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
        ]);
    }
}

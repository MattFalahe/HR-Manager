<?php

namespace HrManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\Application;
use HrManager\Models\ApplicationHandler;
use HrManager\Models\Note;
use HrManager\Models\Setting;
use HrManager\Services\ApplicantAccessService;
use HrManager\Services\ApplicationService;
use HrManager\Services\CrossPluginDataService;
use HrManager\Services\IntelService;
use HrManager\Services\WatchlistService;

class ApplicationController extends Controller
{
    use ScopesCorporationAccess;

    public function index(Request $request)
    {
        $query = Application::with(['character', 'handlers.mainCharacter'])
            ->orderBy('submitted_at', 'desc');

        $this->scopeToAllowedCorps($query);

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('character', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $applications = $query->paginate(25);

        $countQuery = Application::selectRaw('status, count(*) as cnt')->groupBy('status');
        $this->scopeToAllowedCorps($countQuery);
        $statusCounts = $countQuery->pluck('cnt', 'status')->toArray();

        foreach (['applied', 'under_review', 'interview', 'accepted', 'rejected', 'withdrawn'] as $s) {
            $statusCounts[$s] = $statusCounts[$s] ?? 0;
        }

        $staleDays = max(1, (int) Setting::getValue('stale_days', config('hr-manager.applications.stale_days', 7)));

        return view('hr-manager::applications.index', compact('applications', 'statusCounts', 'staleDays'));
    }

    public function show(int $id)
    {
        $application = Application::with([
            'character',
            'template',
            'answers',
            'statusHistory',
            'landing',
            'handlers.mainCharacter',
        ])->findOrFail($id);

        $this->assertCanAccessCorp($application->corporation_id);

        $userId = auth()->user()->id;

        $notes = Note::where('noteable_type', 'application')
            ->where('noteable_id', $application->id)
            ->where(function ($q) use ($userId) {
                $q->where('is_private', false)->orWhere('author_id', $userId);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $applicationService = app(ApplicationService::class);
        $availableStatuses = $applicationService->getAvailableStatuses($application->status);

        // Re-applicant detection: previously accepted apps from the
        // same character_id for the same corp. When found, we pull
        // their lifetime contribution via CWM's bridge so the recruiter
        // can judge "should we accept this person back" at a glance.
        $priorHistory = $this->buildPriorApplicantHistory($application);

        // Watchlist match — applicant's corp + alliance context drives
        // the scope check. Alliance-scoped entries from ANY corp in
        // the alliance match (Matt's spec). Plus we pull the full
        // history (cleared + expired entries) so the recruiter sees
        // the audit trail even when there's no active entry today.
        $applicantCorpId = (int) ($application->corporation_id ?? 0) ?: null;
        $applicantAllianceId = $applicantCorpId
            ? (int) (DB::table('corporation_infos')->where('corporation_id', $applicantCorpId)->value('alliance_id') ?? 0)
            : null;
        $applicantAllianceId = $applicantAllianceId ?: null;

        $watchlistService = app(WatchlistService::class);
        $watchlistMatch = $watchlistService->findMatch(
            (int) $application->character_id,
            $this->getAllowedCorpIds(),
            $applicantCorpId,
            $applicantAllianceId
        );
        $watchlistHistory = $watchlistService->findHistory(
            (int) $application->character_id,
            $this->getAllowedCorpIds(),
            $applicantCorpId,
            $applicantAllianceId
        );
        // Cleared entries surface separately as audit context.
        $clearedHistory = $watchlistHistory->filter(
            fn($e) => $e->status === \HrManager\Models\WatchlistEntry::STATUS_CLEARED
        );
        // ALL active matches (the applicant's main + linked alts can each be
        // listed), so the banner can name every flagged character, not just
        // the single most-relevant one findMatch() returns.
        $activeMatches = $watchlistHistory->filter(function ($e) {
            return $e->status === \HrManager\Models\WatchlistEntry::STATUS_ACTIVE
                && ($e->expires_at === null || (is_object($e->expires_at) && $e->expires_at->isFuture()));
        })->values();

        // Intel notes about this applicant, filtered by viewer
        // visibility (director sees all; recruiter sees only the
        // notes flagged recruiter_visible when the install setting
        // is enabled).
        $intelService = app(IntelService::class);
        $viewerTier = auth()->user()->can('hr-manager.admin') ? 'admin'
            : (auth()->user()->can('hr-manager.director') ? 'director' : 'recruiter');
        $intelNotes = $intelService->notesForCharacter(
            (int) $application->character_id,
            (int) auth()->user()->id,
            $this->getAllowedCorpIds(),
            $viewerTier
        );

        // Resolve actor user_ids (status-history "changed_by" + note authors)
        // to names so the timeline shows people, not "User #12".
        $actorIds = $application->statusHistory->pluck('changed_by')
            ->merge($notes->pluck('author_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $userNames = app(\HrManager\Services\NameResolutionService::class)->getUserNames($actorIds);
        // SeAT superusers among the actors / note authors, so the notes panel
        // can badge them ADMIN.
        $noteAuthorAdmins = empty($actorIds) ? [] : \Illuminate\Support\Facades\DB::table('users')
            ->whereIn('id', $actorIds)
            ->where('admin', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Automated applicant assessment: corp-history intel (hopping / NPC
        // parking), age, security status, watchlist cross-check, zKill PvP and
        // (progressively) skill points, composed into a single recruiter-facing
        // verdict. Reads public/internal data + already-synced ESI, so it always
        // renders something even on a minimal scope profile.
        $assessment = app(\HrManager\Services\ApplicantAssessmentService::class)
            ->assess((int) $application->character_id, $this->getAllowedCorpIds());

        return view('hr-manager::applications.show', compact(
            'application',
            'notes',
            'availableStatuses',
            'priorHistory',
            'watchlistMatch',
            'activeMatches',
            'clearedHistory',
            'intelNotes',
            'userNames',
            'noteAuthorAdmins',
            'assessment'
        ));
    }

    /**
     * Look up prior accepted applications from this character to this
     * corp, summarize lifetime contribution from CWM if available.
     * Returns null when no prior history exists so the view can skip
     * the card entirely (first-time applicants see nothing extra).
     */
    private function buildPriorApplicantHistory(Application $application): ?array
    {
        $prior = Application::where('character_id', $application->character_id)
            ->where('corporation_id', $application->corporation_id)
            ->where('id', '!=', $application->id)
            ->whereIn('status', ['accepted'])
            ->whereNull('deleted_at')
            ->orderByDesc('decided_at')
            ->get(['id', 'status', 'submitted_at', 'decided_at', 'joined_corp_at']);

        if ($prior->isEmpty()) {
            return null;
        }

        // Pull lifetime contribution + percentile if CWM is available.
        // Both calls are graceful no-ops on absent plugins.
        $cross = app(CrossPluginDataService::class);
        $lifetime = $cross->getCharacterLifetimeSummary(
            (int) $application->character_id,
            (int) $application->corporation_id
        );
        $percentile = $cross->getCharacterContributionPercentile(
            (int) $application->character_id,
            (int) $application->corporation_id,
            'last_3_months'
        );

        return [
            'prior_apps' => $prior,
            'count'      => $prior->count(),
            'lifetime'   => $lifetime,
            'percentile' => $percentile,
        ];
    }

    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status'  => 'required|string|in:applied,under_review,interview,accepted,rejected,withdrawn',
            'comment' => 'nullable|string|max:1000',
        ]);

        $application = Application::findOrFail($id);
        $this->assertCanAccessCorp($application->corporation_id);

        $applicationService = app(ApplicationService::class);

        if ($applicationService->requiresDirector($request->status)) {
            if (!auth()->user()->can('hr-manager.director')) {
                return redirect()->back()->with('error', 'Director permission required for this action.');
            }
        }

        $success = $applicationService->updateStatus(
            $application,
            $request->status,
            auth()->user()->id,
            $request->comment
        );

        if (!$success) {
            return redirect()->back()->with('error', 'Invalid status transition.');
        }

        return redirect()->route('hr-manager.applications.show', $id)
            ->with('success', 'Status updated successfully.');
    }

    public function destroy(int $id)
    {
        $application = Application::findOrFail($id);
        $this->assertCanAccessCorp($application->corporation_id);

        $application->delete();

        return redirect()->route('hr-manager.applications.index')
            ->with('success', 'Application deleted.');
    }

    /**
     * Add the current user as a handler. Idempotent — adding twice is a
     * no-op. Optional role_label can be supplied (or updated on rejoin).
     */
    public function joinAsHandler(Request $request, int $id)
    {
        $request->validate([
            'role_label' => 'nullable|string|max:64',
        ]);

        $application = Application::findOrFail($id);
        $this->assertCanAccessCorp($application->corporation_id);

        $user = auth()->user();
        ApplicationHandler::updateOrCreate(
            [
                'application_id' => $application->id,
                'user_id'        => $user->id,
            ],
            [
                'character_id' => $user->main_character_id,
                'role_label'   => $request->role_label,
                'joined_at'    => now(),
            ]
        );

        // Grant temporary SeAT access for the applicant's character
        // data (wallet/mail/skills/etc.) — wrapped in try/catch so a
        // failed grant doesn't block the join.
        try {
            app(ApplicantAccessService::class)->grant($application, $user->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] access grant on join failed: ' . $e->getMessage());
        }

        return redirect()->route('hr-manager.applications.show', $id)
            ->with('success', trans('hr-manager::applications.handler_joined'));
    }

    /**
     * Manually (re)grant the current viewer temporary access to the
     * applicant's character data. For the case where a handler joined
     * before the feature was enabled (the per-join grant is not
     * retroactive) — no leave/re-join dance needed.
     */
    public function grantAccess(int $id)
    {
        $application = Application::findOrFail($id);
        $this->assertCanAccessCorp($application->corporation_id);

        $user = auth()->user();
        $svc = app(ApplicantAccessService::class);

        if (!$svc->isFeatureEnabled()) {
            return redirect()->back()->with('error', trans('hr-manager::applications.access_feature_off'));
        }
        // Only a handler on this application can grant themselves access.
        if (!ApplicationHandler::where('application_id', $application->id)->where('user_id', $user->id)->exists()) {
            return redirect()->back()->with('error', trans('hr-manager::applications.access_not_handler'));
        }

        $grant = $svc->grant($application, $user->id);

        return $grant
            ? redirect()->back()->with('success', trans('hr-manager::applications.access_granted_now'))
            : redirect()->back()->with('error', trans('hr-manager::applications.access_grant_failed'));
    }

    /**
     * Queue an ESI re-sync of the data the applicant assessment reads (skills,
     * implants, corp roles, contacts, plus public info / corp history), so a
     * recruiter can pull fresh numbers when a signal still shows "not synced
     * yet". The jobs run on SeAT's queue; the next page load shows the result.
     */
    public function refreshAssessment(int $id)
    {
        $application = Application::findOrFail($id);
        $this->assertCanAccessCorp($application->corporation_id);

        app(\HrManager\Services\ApplicantAssessmentService::class)
            ->refresh((int) $application->character_id);

        return redirect()
            ->route('hr-manager.applications.show', $application->id)
            ->with('success', trans('hr-manager::applications.assess_refresh_queued'));
    }

    /**
     * Remove the current user as a handler. Directors can pass
     * ?user_id=N to remove someone else; recruiters can only remove
     * themselves.
     */
    public function leaveAsHandler(Request $request, int $id)
    {
        $application = Application::findOrFail($id);
        $this->assertCanAccessCorp($application->corporation_id);

        $targetUserId = (int) $request->input('user_id', auth()->user()->id);

        // Only directors can remove other handlers.
        if ($targetUserId !== (int) auth()->user()->id
            && !auth()->user()->can('hr-manager.director')) {
            return redirect()->back()->with('error', 'Director permission required to remove another handler.');
        }

        ApplicationHandler::where('application_id', $application->id)
            ->where('user_id', $targetUserId)
            ->delete();

        // Revoke that user's temporary SeAT access for this applicant.
        // Other handlers on the same application keep their grants;
        // we only detach this one user. If they were the last handler,
        // the access service drops the role entirely.
        try {
            app(ApplicantAccessService::class)->revoke(
                (int) $application->id,
                $targetUserId,
                'handler_left'
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] access revoke on leave failed: ' . $e->getMessage());
        }

        return redirect()->route('hr-manager.applications.show', $id)
            ->with('success', trans('hr-manager::applications.handler_removed'));
    }

    /**
     * Update the current user's role label (or, for directors, someone
     * else's). Used by the small inline rename UI on the Handlers card.
     */
    public function updateHandlerRole(Request $request, int $id)
    {
        $request->validate([
            'user_id'    => 'required|integer',
            'role_label' => 'nullable|string|max:64',
        ]);

        $application = Application::findOrFail($id);
        $this->assertCanAccessCorp($application->corporation_id);

        $targetUserId = (int) $request->input('user_id');

        if ($targetUserId !== (int) auth()->user()->id
            && !auth()->user()->can('hr-manager.director')) {
            return redirect()->back()->with('error', 'Director permission required to edit another handler.');
        }

        ApplicationHandler::where('application_id', $application->id)
            ->where('user_id', $targetUserId)
            ->update(['role_label' => $request->role_label]);

        return redirect()->route('hr-manager.applications.show', $id)
            ->with('success', trans('hr-manager::applications.handler_updated'));
    }
}

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
use HrManager\Models\WatchlistEntry;
use HrManager\Services\ApplicantAccessService;
use HrManager\Services\ApplicationService;
use HrManager\Services\AuditService;
use HrManager\Services\CrossPluginDataService;
use HrManager\Services\IntelService;
use HrManager\Services\NameResolutionService;
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
        // The transition an "undo last change" would void (or null when nothing
        // is revertable) — drives the director-only revert control + accepted warning.
        $revertTarget = $applicationService->revertableLastTransition($application);

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
        // Intel across the WHOLE applying account (main + registered alts), so a
        // note recorded against a flagged alt surfaces too — matching the
        // alt-aware watchlist banner. Resolve the account via shared token owner.
        $intelAccountCharIds = [(int) $application->character_id];
        $intelAccUserId = \Illuminate\Support\Facades\DB::table('refresh_tokens')
            ->where('character_id', $application->character_id)->whereNull('deleted_at')->value('user_id');
        if ($intelAccUserId) {
            $intelAccountCharIds = array_merge($intelAccountCharIds, \Illuminate\Support\Facades\DB::table('refresh_tokens')
                ->where('user_id', $intelAccUserId)->whereNull('deleted_at')->pluck('character_id')->all());
        }
        $intelAccountCharIds = array_values(array_unique(array_map('intval', $intelAccountCharIds)));

        // Director deep-link card: SeAT native-page links for EVERY character on
        // the applying account (main + alts), not just the applied character. A
        // director uses their own permissions (no grant), so this always covers
        // the whole account and is resolved live here — unlike the grant-scoped
        // recruiter panel, it never needs a re-sync. Built only for director/admin
        // viewers since the card itself is director-gated.
        $directorLinkCharacters = [];
        if (in_array($viewerTier, ['director', 'admin'], true)) {
            $mainCharId = (int) $application->character_id;
            $dlNames = app(\HrManager\Services\NameResolutionService::class)
                ->getCharacterNamesWithFallback($intelAccountCharIds);
            foreach ($intelAccountCharIds as $cid) {
                $directorLinkCharacters[] = [
                    'character_id' => $cid,
                    'name'         => $dlNames[$cid] ?? ('Character #' . $cid),
                    'is_applicant' => $cid === $mainCharId,
                ];
            }
        }

        $intelNotes = $intelService->notesForCharacters(
            $intelAccountCharIds,
            (int) auth()->user()->id,
            $this->getAllowedCorpIds(),
            $viewerTier
        );
        // Names for the notes' characters, so the banner can label an alt's note.
        $intelCharNames = $intelNotes->isEmpty() ? [] : app(\HrManager\Services\NameResolutionService::class)
            ->getCharacterNamesWithFallback($intelNotes->pluck('character_id')->map(fn ($c) => (int) $c)->unique()->all());

        // Resolve actor user_ids (status-history "changed_by" + note authors)
        // to names so the timeline shows people, not "User #12".
        $actorIds = $application->statusHistory->pluck('changed_by')
            ->merge($application->statusHistory->pluck('voided_by'))
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
            ->assess((int) $application->character_id, $this->getAllowedCorpIds(), $applicantCorpId, $applicantAllianceId);

        // Acceptance guard: the HIGH blacklist entry (if any) that blocks moving
        // this applicant to "accepted" — drives the override UI on the Change
        // Status panel. Null when the guard is off or nothing blocks.
        $blacklistBlock = $this->blockingBlacklistEntry($application);

        return view('hr-manager::applications.show', compact(
            'application',
            'notes',
            'availableStatuses',
            'revertTarget',
            'priorHistory',
            'watchlistMatch',
            'activeMatches',
            'clearedHistory',
            'intelNotes',
            'intelCharNames',
            'userNames',
            'noteAuthorAdmins',
            'assessment',
            'directorLinkCharacters',
            'blacklistBlock'
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

        // Accept / reject is the hire/decline decision — allowed for the
        // Personnel Manager tier (EVE's recruitment role) or Director / Admin
        // above it. Other transitions (interview, under review) stay at recruiter.
        if ($applicationService->requiresDirector($request->status)) {
            if (!$this->canDecideApplications()) {
                return redirect()->back()->with('error', trans('hr-manager::applications.decide_permission_required'));
            }
        }

        // Acceptance guard: a HIGH-severity blacklist match on the applying
        // account blocks the move to "accepted" unless the director clears the
        // entry OR consciously overrides with a written justification. The
        // application still flows (intel gathering) — only the final approval is
        // gated, with a documented, auditable exception path for the cases where
        // a member is let in on special conditions.
        $comment       = $request->comment;
        $overrideEntry = null;
        $overrideReason = null;
        if ($request->status === 'accepted') {
            $overrideEntry = $this->blockingBlacklistEntry($application);
            if ($overrideEntry) {
                $reason = trim((string) $request->input('blacklist_override_reason', ''));
                if (!$request->boolean('blacklist_override') || mb_strlen($reason) < 3) {
                    return redirect()->back()
                        ->with('error', trans('hr-manager::applications.accept_blocked_blacklist'))
                        ->withInput();
                }
                $overrideReason = mb_substr($reason, 0, 1000);
                // Fold the justification into the status comment so it lands on
                // the application timeline right beside the transition.
                $comment = trans('hr-manager::applications.accept_override_prefix') . ' ' . $overrideReason
                    . ($comment ? "\n" . $comment : '');
            }
        }

        $success = $applicationService->updateStatus(
            $application,
            $request->status,
            auth()->user()->id,
            $comment
        );

        if (!$success) {
            return redirect()->back()->with('error', 'Invalid status transition.');
        }

        // Audit trail: every decision on an application (the full "application
        // process" the director wants accountable). Snapshot the character name
        // so the row survives a later purge of the application.
        $charName = app(NameResolutionService::class)->getCharacterName((int) $application->character_id);
        app(AuditService::class)->action('application.status_change', AuditService::CAT_DECISION, [
            'target_type'    => 'application',
            'target_id'      => (int) $application->id,
            'target_label'   => $charName,
            'corporation_id' => $application->corporation_id ? (int) $application->corporation_id : null,
            'summary'        => 'Application status → ' . $request->status,
            'context'        => [
                'new_status' => $request->status,
                'comment'    => $comment ? mb_substr($comment, 0, 200) : null,
            ],
        ]);

        if ($overrideEntry && $overrideReason !== null) {
            $this->recordBlacklistOverride($application, $overrideEntry, $overrideReason);

            // Security-category audit: a HIGH-severity blacklist match was
            // consciously overridden to accept — the exact accountability the
            // override workflow exists for.
            app(AuditService::class)->action('blacklist.override', AuditService::CAT_SECURITY, [
                'target_type'    => 'application',
                'target_id'      => (int) $application->id,
                'target_label'   => $charName,
                'corporation_id' => $application->corporation_id ? (int) $application->corporation_id : null,
                'summary'        => 'Overrode HIGH blacklist match to accept',
                'context'        => [
                    'reason'            => mb_substr($overrideReason, 0, 300),
                    'blacklist_entry_id' => $overrideEntry->id ?? null,
                ],
            ]);
        }

        return redirect()->route('hr-manager.applications.show', $id)
            ->with('success', $overrideReason !== null
                ? trans('hr-manager::applications.accept_override_done')
                : 'Status updated successfully.');
    }

    /**
     * Undo the last status change on an application, treating it as an accidental
     * mistake: the erroneous transition is voided (hidden from the applicant's
     * tracking page, kept internally) and the status is restored to what it was
     * before. Director-only, and a written reason is required (an accountable
     * correction, recorded in the audit trail). Reverting an 'accepted' does NOT
     * roll back its side effects (onboarding / access) — the view warns first.
     */
    public function revertStatus(Request $request, int $id)
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $application = Application::findOrFail($id);
        $this->assertCanAccessCorp($application->corporation_id);

        if (!auth()->user()->can('hr-manager.director')) {
            return redirect()->back()->with('error', trans('hr-manager::applications.revert_permission_required'));
        }

        $reason  = trim((string) $request->input('reason', ''));
        $actorId = (int) auth()->user()->id;

        $result = app(ApplicationService::class)->revertLastStatus($application, $actorId, $reason);

        if ($result === false) {
            return redirect()->back()->with('error', trans('hr-manager::applications.revert_nothing'));
        }

        // Audit: an accountable correction — a director undid a status the
        // applicant may have seen and hid it from them.
        app(AuditService::class)->action('application.status_reverted', AuditService::CAT_DECISION, [
            'actor_user_id'  => $actorId,
            'target_type'    => 'application',
            'target_id'      => (int) $application->id,
            'corporation_id' => $application->corporation_id ? (int) $application->corporation_id : null,
            'summary'        => "Reverted application status: {$result['voided']} -> {$result['restored']}",
            'context'        => [
                'voided'   => $result['voided'],
                'restored' => $result['restored'],
                'reason'   => mb_substr($reason, 0, 300),
            ],
        ]);

        return redirect()->route('hr-manager.applications.show', $id)
            ->with('success', trans('hr-manager::applications.revert_success', [
                'voided'   => trans('hr-manager::applications.status_' . $result['voided']),
                'restored' => trans('hr-manager::applications.status_' . $result['restored']),
            ]));
    }

    /**
     * Can the current user accept / reject applications? The Personnel Manager
     * tier (EVE's recruitment role) grants the hire/decline decision; Director
     * and Admin sit above it.
     */
    private function canDecideApplications(): bool
    {
        $user = auth()->user();
        return $user->can('hr-manager.personnel')
            || $user->can('hr-manager.director')
            || $user->can('hr-manager.admin');
    }

    /**
     * The active HIGH-severity blacklist entry that blocks accepting this
     * applicant (checked across the whole applying account), or null when the
     * guard is off or nothing blocks. Scoped to the viewer's corps.
     */
    private function blockingBlacklistEntry(Application $application): ?WatchlistEntry
    {
        $enabled = (bool) Setting::getValue(
            'blacklist_accept_guard_enabled',
            config('hr-manager.applications.blacklist_accept_guard_enabled', true)
        );
        if (!$enabled) {
            return null;
        }

        $applicantCorpId = (int) ($application->corporation_id ?? 0) ?: null;
        $applicantAllianceId = $applicantCorpId
            ? ((int) (DB::table('corporation_infos')->where('corporation_id', $applicantCorpId)->value('alliance_id') ?? 0) ?: null)
            : null;

        return app(WatchlistService::class)->firstBlockingBlacklist(
            $this->accountCharacterIds($application),
            WatchlistEntry::SEVERITY_HIGH,
            $this->getAllowedCorpIds(),
            $applicantCorpId,
            $applicantAllianceId
        );
    }

    /** Every character id on the applying account (main + live-token alts). */
    private function accountCharacterIds(Application $application): array
    {
        $ids = [(int) $application->character_id];
        $userId = DB::table('refresh_tokens')
            ->where('character_id', $application->character_id)
            ->whereNull('deleted_at')->value('user_id');
        if ($userId) {
            $ids = array_merge($ids, DB::table('refresh_tokens')
                ->where('user_id', $userId)->whereNull('deleted_at')->pluck('character_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Audit an accept-despite-blacklist override: history event + security ping. */
    private function recordBlacklistOverride(Application $application, WatchlistEntry $entry, string $reason): void
    {
        $subjectUserId = (int) (DB::table('refresh_tokens')
            ->where('character_id', $application->character_id)
            ->whereNull('deleted_at')->value('user_id') ?? 0) ?: null;

        try {
            app(\HrManager\Services\HistoryEventService::class)->record(
                'hr.player.blacklist_override',
                [
                    'application_id'           => $application->id,
                    'blacklist_entry_id'       => $entry->id,
                    'blacklisted_character_id' => (int) $entry->character_id,
                    'severity'                 => $entry->severity,
                    'reason'                   => $reason,
                ],
                [
                    'user_id'         => $subjectUserId,
                    'character_id'    => (int) $application->character_id,
                    'corporation_id'  => (int) $application->corporation_id,
                    'occurred_at'     => now(),
                    'idempotency_key' => 'bl-override:' . $application->id . ':' . $entry->id,
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] blacklist override history failed: ' . $e->getMessage());
        }

        try {
            app(\HrManager\Services\NotificationService::class)
                ->notifyBlacklistOverride($application, $entry, $reason, (int) auth()->user()->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] blacklist override notification failed: ' . $e->getMessage());
        }
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
     * Lazy-loaded zKillboard PvP breakdown for the whole applying account:
     * rolling 1 / 3 / 6 / 12-month kill windows per character, with the most
     * active PvP character flagged. Fired on demand from the application panel
     * (a button), never on page render, since it makes one zKill fetch per
     * character. Returns the rendered partial.
     */
    public function pvpBreakdown(int $id)
    {
        $application = Application::findOrFail($id);
        $this->assertCanAccessCorp($application->corporation_id);

        $charIds = $this->accountCharacterIds($application);
        $names = app(\HrManager\Services\NameResolutionService::class)
            ->getCharacterNamesWithFallback($charIds);

        $characters = [];
        foreach ($charIds as $cid) {
            $characters[$cid] = $names[$cid] ?? ('Character #' . $cid);
        }

        $breakdown = app(\HrManager\Services\ZkillService::class)->getAccountPvpBreakdown($characters);

        return view('hr-manager::applications.partials._pvp_breakdown', [
            'breakdown'           => $breakdown,
            'applyingCharacterId' => (int) $application->character_id,
        ]);
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

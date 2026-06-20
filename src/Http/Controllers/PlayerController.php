<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\CharacterIdentityMapping;
use HrManager\Models\Note;
use HrManager\Models\PlayerIdentity;
use HrManager\Models\PlayerStatus;
use HrManager\Services\AssessmentService;
use HrManager\Services\CharacterRoleClassifier;
use HrManager\Services\CharacterTitleService;
use HrManager\Services\HistoryEventService;
use HrManager\Services\NameResolutionService;
use HrManager\Services\PlayerIdentityResolver;
use HrManager\Services\PlayerService;
use HrManager\Services\PurgeService;
use HrManager\Services\TierService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class PlayerController extends Controller
{
    use ScopesCorporationAccess;

    public function index(Request $request)
    {
        $allowedCorps = $this->getAllowedCorpIds();
        $corporationId = $this->resolveCorporationContext($request, $allowedCorps);
        $this->assertCanAccessCorp($corporationId);

        $players = app(PlayerService::class)
            ->indexForCorporation($corporationId, $request->input('search'), 50);

        $corporations = $this->corporationPickerOptions($allowedCorps);
        $tierAuto = app(TierService::class)->autoResolutionAvailable();

        return view('hr-manager::players.index', compact(
            'players', 'corporationId', 'corporations', 'tierAuto'
        ));
    }

    /**
     * Canonical player profile. URL param {id} is the SeAT user_id —
     * the single ID space every first-party link passes and the whole
     * pipeline (getPlayerSummary, history) works in. The
     * PlayerIdentity is resolved FROM the user (get-or-materialize) for
     * the alts/mappings grid; it is not the URL key. See
     * resolveIdentityOrRedirect for why user_id-first avoids the
     * identity/user id collision.
     *
     * A stale PlayerIdentity.id link 301-redirects to the canonical
     * user_id URL.
     *
     * Per-corp scoping still applies — every action below routes
     * through the same allowed-corps check.
     */
    public function show(Request $request, int $id, PlayerIdentityResolver $resolver)
    {
        $allowedCorps = $this->getAllowedCorpIds();
        $corporationId = $this->resolveCorporationContext($request, $allowedCorps);
        $this->assertCanAccessCorp($corporationId);

        [$identity, $userId, $redirect] = $this->resolveIdentityOrRedirect(
            $request,
            $id,
            $resolver,
            'hr-manager.players.show'
        );
        if ($redirect) return $redirect;

        $playerService = app(PlayerService::class);
        $summary = $playerService->getPlayerSummary($userId, $corporationId);

        if (!$summary['user']) {
            abort(404, 'Player not found.');
        }

        $characterIds = $summary['characters']->pluck('character_id')->all();
        $this->assertPlayerInAllowedCorp($characterIds, $allowedCorps);

        $viewerId = auth()->user()->id;
        $notes = $playerService->notesForPlayer($userId, $characterIds, $viewerId);
        $history = app(HistoryEventService::class)->timelineForPlayer($userId, $characterIds, 100);

        // Resolve note authors (SeAT user_id) to their main-character name so
        // the notes list never shows a bare "User #2", and flag which authors
        // are SeAT superusers so the view can badge them ADMIN.
        $noteAuthorIds = $notes->pluck('author_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $noteAuthorNames = app(NameResolutionService::class)->getUserNames($noteAuthorIds);
        $noteAuthorAdmins = empty($noteAuthorIds) ? [] : \Illuminate\Support\Facades\DB::table('users')
            ->whereIn('id', $noteAuthorIds)
            ->where('admin', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $corporations = $this->corporationPickerOptions($allowedCorps);
        $tierAuto = app(TierService::class)->autoResolutionAvailable();

        // In-game titles + roles per alt.
        $titleService = app(CharacterTitleService::class);
        $titleSnapshot = $titleService->snapshotForUser($characterIds, $corporationId);

        // Ensure the identity mirrors the full SeAT account before we
        // render its mapping list — otherwise it shows only the alts
        // that happened to be individually looked up, not all of them.
        // Idempotent + cheap; only inserts mappings for not-yet-mapped
        // linked characters.
        if (($identity->seat_user_id ?? 0) > 0) {
            $resolver->forSeatUser((int) $identity->seat_user_id);
            $identity->refresh();
        }

        // Identity-aware data: load the FULL mapping list (current +
        // historical) so the alts grid can show the audit trail of
        // ownership changes. The PlayerIdentity hadOne-many relation
        // is loaded eagerly with assignedByUser so we don't N+1.
        $identity->load(['mappings' => function ($q) {
            $q->orderByRaw('effective_to IS NULL DESC, effective_from DESC');
        }, 'mappings.assignedByUser']);
        $identityCharIds = $identity->mappings->pluck('character_id')->all();
        $identityCharNames = app(NameResolutionService::class)->getCharacterNames($identityCharIds);

        // Per-alt role badges — what each character on this account is
        // used for (ratting / mining / PI / industry). Lets a director
        // see "this human has a ratting main, a mining alt, and a PI
        // farm" at a glance. Keyed by character_id.
        $classifier = app(CharacterRoleClassifier::class);
        $roleProfiles = [];
        foreach ($characterIds as $cid) {
            $profile = $classifier->classify((int) $cid, $corporationId);
            if (!empty($profile['has_data'])) {
                $roleProfiles[(int) $cid] = $profile;
            }
        }

        // FC activity — human-level (broadcasts are sent by the SeAT
        // user, not a character). From HR's EventBus-accumulated table.
        $fcActivity = app(\HrManager\Services\FcActivityService::class)->getForUser($userId);

        // Blueprint Manager engagement (optional — only when MC + Blueprint
        // Manager are installed). Aggregated across the player's characters.
        $blueprintActivity = app(\HrManager\Services\BlueprintActivityService::class)
            ->getForPlayer($characterIds, $corporationId);

        // Access depth: in-game corp roles/titles + SeAT account access, with
        // the sensitive access flagged and off-balance indicators surfaced.
        $accessDepth = app(\HrManager\Services\AccessDepthService::class)
            ->forPlayer($characterIds, $corporationId, $userId);

        // Discord identity + assigned roles (via seat-connector). Account-level,
        // so it belongs on the player view; empty when the player is
        // unregistered or the connector framework isn't installed.
        $discord = ($userId !== null && $userId > 0)
            ? app(\HrManager\Services\SeatConnectorService::class)->getIdentityForUser((int) $userId)
            : ['available' => false, 'roles' => []];

        // SeAT squad memberships (account-level). Shown so a director can see
        // what groups the player carries and clear them as part of a purge.
        $squads = ($userId !== null && $userId > 0)
            ? app(\HrManager\Services\SeatSquadService::class)->squadsForUser((int) $userId)
            : [];

        return view('hr-manager::players.show', compact(
            'summary', 'notes', 'history', 'corporationId',
            'corporations', 'tierAuto', 'titleSnapshot',
            'identity', 'identityCharNames', 'roleProfiles', 'fcActivity',
            'blueprintActivity', 'accessDepth', 'discord', 'squads',
            'noteAuthorNames', 'noteAuthorAdmins'
        ));
    }

    /**
     * Reassign a character to a different identity (account-takeover
     * workflow). Director-only. Moved from PlayerIdentityController
     * during the Player+Identity surface merge.
     *
     * Target identifier is flexible: numeric identity id, `u:N` for
     * SeAT user id, or a character name to resolve.
     */
    public function reassignCharacter(Request $request, int $id, int $characterId, PlayerIdentityResolver $resolver)
    {
        if (!auth()->user()->can('hr-manager.director')) {
            abort(403, 'Director permission required.');
        }

        $request->validate([
            'target' => 'required|string|min:1|max:96',
            'reason' => 'nullable|string|max:1000',
        ]);

        $target = trim((string) $request->input('target'));
        $targetIdentity = null;

        if (ctype_digit($target)) {
            $targetIdentity = PlayerIdentity::find((int) $target);
        } elseif (str_starts_with($target, 'u:') && ctype_digit(substr($target, 2))) {
            $targetIdentity = $resolver->forSeatUser((int) substr($target, 2));
        } else {
            $resolved = app(NameResolutionService::class)->getIdFromCharacterName($target);
            if (!empty($resolved['character_id'])) {
                $targetIdentity = $resolver->forCharacter((int) $resolved['character_id']);
            }
        }

        if (!$targetIdentity) {
            return redirect()->back()->with('error', trans('hr-manager::identity.reassign_target_not_found'));
        }

        $ok = $resolver->reassignCharacter(
            $characterId,
            (int) $targetIdentity->id,
            (int) auth()->user()->id,
            CharacterIdentityMapping::REASON_ACCOUNT_TAKEOVER,
            $request->input('reason')
        );

        if (!$ok) {
            return redirect()->back()->with('error', trans('hr-manager::identity.reassign_failed'));
        }

        // Redirect to the TARGET player profile so the director sees
        // where the character landed. The show route keys on user_id, so
        // redirect to the target identity's SeAT user; if the target is
        // an unlinked identity (no SeAT user) fall back to this page.
        $targetUserId = (int) ($targetIdentity->seat_user_id ?? 0);
        $redirectId = $targetUserId > 0 ? $targetUserId : (int) $id;
        return redirect()->route('hr-manager.players.show', $this->urlArgs($request, $redirectId))
            ->with('success', trans('hr-manager::identity.reassign_done'));
    }

    /**
     * Merge identity FROM into identity INTO (this player). Director-only.
     * Moved from PlayerIdentityController during the surface merge.
     */
    public function mergeIdentity(Request $request, int $id, PlayerIdentityResolver $resolver)
    {
        if (!auth()->user()->can('hr-manager.director')) {
            abort(403, 'Director permission required.');
        }

        $request->validate([
            'from'  => 'required|integer',
            'notes' => 'nullable|string|max:1000',
        ]);

        // {id} is the SeAT user_id of THIS player; resolve its identity as
        // the merge target. `from` is the OTHER identity's id (picked from
        // the identity selector), a genuine PlayerIdentity.id.
        $userId = $this->resolveUserId($id);
        $into = $resolver->forSeatUser($userId);
        if (!$into) abort(404, 'Identity not found.');

        // Guard self-merge here (the old `different:id` rule compared an
        // identity.id against a user_id — different ID spaces, unreliable).
        if ((int) $request->from === (int) $into->id) {
            return redirect()->back()->with('error', trans('hr-manager::identity.merge_failed'));
        }

        $ok = $resolver->mergeIdentities(
            (int) $into->id,
            (int) $request->from,
            (int) auth()->user()->id,
            $request->input('notes')
        );

        if (!$ok) {
            return redirect()->back()->with('error', trans('hr-manager::identity.merge_failed'));
        }

        return redirect()->route('hr-manager.players.show', $this->urlArgs($request, $userId))
            ->with('success', trans('hr-manager::identity.merge_done'));
    }

    /**
     * Shared resolver. The route {id} is the SeAT user_id — that's what
     * every first-party link in HR passes (Players list, Corp Health
     * cross-links, Members → Player) and what this controller's whole
     * pipeline works in. We resolve user_id FIRST, deliberately.
     *
     * Why not PlayerIdentity.id? Both `users.id` and
     * `hr_manager_player_identities.id` are small auto-increment PKs, so
     * their value ranges OVERLAP. Identity rows are materialized lazily,
     * so a list view can't reliably emit identity.id links anyway. If we
     * resolved identity-first, a `user_id=N` link would silently match a
     * DIFFERENT person's identity #N once the table filled in — the
     * "click RVA Mitchell, see Asuramaru" bug, which got worse over time
     * as identities accumulated. user_id-first removes the ambiguity.
     *
     * Legacy fallback: an id that is NOT a valid user but IS a valid
     * identity (an old identity.id bookmark) 301-redirects to the
     * canonical user_id URL. Returns [identity, userId, ?redirect].
     */
    private function resolveIdentityOrRedirect(
        Request $request,
        int $id,
        PlayerIdentityResolver $resolver,
        string $canonicalRoute
    ): array {
        // Primary: {id} is a SeAT user_id.
        if (\Seat\Web\Models\User::find($id)) {
            $identity = $resolver->forSeatUser($id); // get-or-materialize
            return [$identity, (int) $id, null];
        }

        // Fallback: maybe it's a PlayerIdentity.id (stale link / old
        // bookmark). Redirect to the canonical user_id URL so the page
        // and every form on it operate in one ID space.
        $identity = PlayerIdentity::find($id);
        $legacyUserId = (int) ($identity->seat_user_id ?? 0);
        if ($identity && $legacyUserId > 0) {
            return [
                null,
                $legacyUserId,
                redirect()->route($canonicalRoute, $this->urlArgs($request, $legacyUserId), 301),
            ];
        }

        abort(404, 'Player not found.');
    }

    /**
     * Build route args preserving the corp_id query string.
     */
    private function urlArgs(Request $request, int $id): array
    {
        $args = ['id' => $id];
        if ($request->filled('corporation_id')) {
            $args['corporation_id'] = (int) $request->input('corporation_id');
        }
        return $args;
    }

    /**
     * Action-endpoint shorthand. The route {id} is a SeAT user_id (same
     * canonical space as show()). Resolve user_id FIRST so a POST from a
     * page reached via user_id targets the right player; fall back to
     * treating {id} as a PlayerIdentity.id only when it isn't a valid
     * user (stale link). Aborts 404 if neither resolves.
     */
    private function resolveUserId(int $id): int
    {
        if (\Seat\Web\Models\User::find($id)) {
            return $id;
        }
        $identity = PlayerIdentity::find($id);
        $userId = (int) ($identity->seat_user_id ?? 0);
        if ($identity && $userId > 0) {
            return $userId;
        }
        abort(404, 'Player not found.');
    }

    /**
     * Best-effort immediate webhook for a player-status change
     * (loa_marked / marked_for_purge / status_cleared). Fired inline so
     * the team hears about it within the request; isolated in try/catch
     * so a webhook hiccup never blocks or fails the director's action.
     */
    private function notifyStatus(PlayerStatus $status, string $event): void
    {
        try {
            app(\HrManager\Services\NotificationService::class)
                ->notifyPlayerStatusChange($status, $event, (int) auth()->user()->id);
        } catch (\Throwable $e) {
            Log::warning('[HR] player-status notification failed: ' . $e->getMessage());
        }
    }

    public function markLoa(Request $request, int $id)
    {
        $userId = $this->resolveUserId($id);
        $request->validate([
            'corporation_id' => 'required|integer',
            'loa_until'      => 'nullable|date|after:today',
            'reason'         => 'nullable|string|max:500',
        ]);

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);
        $this->assertPlayerExistsInCorp($userId, $corporationId);

        $status = PlayerStatus::updateOrCreate(
            ['user_id' => $userId, 'corporation_id' => $corporationId],
            [
                'status'              => PlayerStatus::STATUS_LOA,
                'loa_until'           => $request->loa_until,
                'purge_scheduled_for' => null,
                'reason'              => $request->reason,
                'status_set_by'       => auth()->user()->id,
                'status_set_at'       => now(),
            ]
        );

        app(HistoryEventService::class)->record('hr.player.loa_marked', [
            'loa_until' => $request->loa_until,
            'reason'    => $request->reason,
        ], [
            'user_id'        => $userId,
            'corporation_id' => $corporationId,
            'occurred_at'    => now(),
        ]);

        $this->notifyStatus($status, 'loa_marked');

        return redirect()->route('hr-manager.players.show', [
            'id' => $id, 'corporation_id' => $corporationId,
        ])->with('success', trans('hr-manager::players.loa_marked'));
    }

    public function markForPurge(Request $request, int $id)
    {
        $userId = $this->resolveUserId($id);
        $request->validate([
            'corporation_id'      => 'required|integer',
            'purge_scheduled_for' => 'nullable|date|after_or_equal:today',
            'reason'              => 'nullable|string|max:500',
        ]);

        if (!auth()->user()->can('hr-manager.director')) {
            abort(403, 'Director permission required.');
        }

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);
        $this->assertPlayerExistsInCorp($userId, $corporationId);

        $status = PlayerStatus::updateOrCreate(
            ['user_id' => $userId, 'corporation_id' => $corporationId],
            [
                'status'              => PlayerStatus::STATUS_MARKED_FOR_PURGE,
                'loa_until'           => null,
                'purge_scheduled_for' => $request->purge_scheduled_for,
                'reason'              => $request->reason,
                'status_set_by'       => auth()->user()->id,
                'status_set_at'       => now(),
            ]
        );

        app(HistoryEventService::class)->record('hr.purge.scheduled', [
            'scheduled_for' => $request->purge_scheduled_for,
            'reason'        => $request->reason,
        ], [
            'user_id'        => $userId,
            'corporation_id' => $corporationId,
            'occurred_at'    => now(),
        ]);

        $this->notifyStatus($status, 'marked_for_purge');

        $message = trans('hr-manager::players.purge_marked');

        return redirect()->route('hr-manager.players.show', [
            'id' => $id, 'corporation_id' => $corporationId,
        ])->with('success', $message);
    }

    /**
     * Remove the player from all their SeAT squads (purge cleanup). Director
     * only. Mirrors SeAT's native squad kick, so the core SquadMemberObserver
     * fires and any Connector-managed Discord roles cascade off. Records one
     * history-timeline event per squad removed.
     */
    public function removeSquads(Request $request, int $id)
    {
        $userId = $this->resolveUserId($id);
        $request->validate(['corporation_id' => 'required|integer']);

        if (!auth()->user()->can('hr-manager.director')) {
            abort(403, 'Director permission required.');
        }

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);
        $this->assertPlayerExistsInCorp($userId, $corporationId);

        $removed = app(\HrManager\Services\SeatSquadService::class)->removeUserFromAllSquads($userId);

        foreach ($removed as $squad) {
            app(HistoryEventService::class)->record('hr.squad.removed', [
                'squad_id'   => $squad['id'],
                'squad_name' => $squad['name'],
            ], [
                'user_id'        => $userId,
                'corporation_id' => $corporationId,
                'occurred_at'    => now(),
            ]);
        }

        if (empty($removed)) {
            return redirect()->route('hr-manager.players.show', [
                'id' => $id, 'corporation_id' => $corporationId,
            ])->with('info', trans('hr-manager::players.squads_none_removed'));
        }

        return redirect()->route('hr-manager.players.show', [
            'id' => $id, 'corporation_id' => $corporationId,
        ])->with('success', trans('hr-manager::players.squads_removed', ['count' => count($removed)]));
    }

    public function clearStatus(Request $request, int $id)
    {
        $userId = $this->resolveUserId($id);
        $request->validate(['corporation_id' => 'required|integer']);

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);

        $status = PlayerStatus::where('user_id', $userId)
            ->where('corporation_id', $corporationId)
            ->first();

        if (!$status) {
            return redirect()->back()->with('success', trans('hr-manager::players.status_already_active'));
        }

        // Cancelling a purge schedule requires director (recruiters can clear LOA)
        if ($status->status === PlayerStatus::STATUS_MARKED_FOR_PURGE
            && !auth()->user()->can('hr-manager.director')) {
            return redirect()->back()
                ->with('error', trans('hr-manager::players.cancel_purge_director_required'));
        }

        $wasPurge = ($status->status === PlayerStatus::STATUS_MARKED_FOR_PURGE);

        $status->update([
            'status'              => PlayerStatus::STATUS_ACTIVE,
            'loa_until'           => null,
            'purge_scheduled_for' => null,
            'reason'              => null,
            'status_set_by'       => auth()->user()->id,
            'status_set_at'       => now(),
        ]);

        app(HistoryEventService::class)->record($wasPurge ? 'hr.purge.cancelled' : 'hr.player.status_cleared', [], [
            'user_id'        => $userId,
            'corporation_id' => $corporationId,
            'occurred_at'    => now(),
        ]);

        $this->notifyStatus($status, 'status_cleared');

        return redirect()->route('hr-manager.players.show', [
            'id' => $id, 'corporation_id' => $corporationId,
        ])->with('success', trans('hr-manager::players.status_cleared'));
    }

    /**
     * Director-only: mark a scheduled purge as actually executed (the human
     * has performed the in-game kick + Discord-role-removal). Records the
     * history event, publishes hr.purge.executed, archives the status row.
     */
    public function markPurgeExecuted(Request $request, int $id, PurgeService $purge)
    {
        $userId = $this->resolveUserId($id);
        $request->validate(['corporation_id' => 'required|integer']);

        if (!auth()->user()->can('hr-manager.director')) {
            abort(403, 'Director permission required.');
        }

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);

        $status = PlayerStatus::where('user_id', $userId)
            ->where('corporation_id', $corporationId)
            ->first();

        if (!$status || $status->status !== PlayerStatus::STATUS_MARKED_FOR_PURGE) {
            return redirect()->back()->with('error', trans('hr-manager::players.no_purge_to_execute'));
        }

        $purge->markExecuted($status, auth()->user()->id);

        return redirect()->route('hr-manager.players.show', [
            'id' => $id, 'corporation_id' => $corporationId,
        ])->with('success', trans('hr-manager::players.purge_executed'));
    }

    public function refreshAssessments(Request $request, int $id)
    {
        $userId = $this->resolveUserId($id);
        $request->validate(['corporation_id' => 'required|integer']);

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);

        try {
            $service = app(AssessmentService::class);
            $characters = app(PlayerService::class)->charactersForUser($userId);
            foreach ($characters as $char) {
                $service->buildAssessment((int) $char->character_id, $corporationId);
            }
            return redirect()->route('hr-manager.players.show', [
                'id' => $id, 'corporation_id' => $corporationId,
            ])->with('success', trans('hr-manager::players.assessments_refreshed'));
        } catch (\Throwable $e) {
            Log::error('[HR Manager] PlayerController::refreshAssessments failed', [
                'user_id' => $userId, 'error' => $e->getMessage(),
            ]);
            return redirect()->back()->with('error', trans('hr-manager::players.refresh_failed'));
        }
    }

    public function addNote(Request $request, int $id)
    {
        $userId = $this->resolveUserId($id);
        $request->validate([
            'corporation_id' => 'required|integer',
            'content'        => 'required|string|max:5000',
            'is_private'     => 'nullable|boolean',
        ]);

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);
        $this->assertPlayerExistsInCorp($userId, $corporationId);

        Note::create([
            'noteable_type' => 'player',
            'noteable_id'   => $userId,
            'author_id'     => auth()->user()->id,
            'content'       => $request->content,
            'is_private'    => !empty($request->is_private),
        ]);

        return redirect()->route('hr-manager.players.show', [
            'id' => $id, 'corporation_id' => $corporationId,
        ])->with('success', trans('hr-manager::notes.note_created'));
    }

    // -----------------------------------------------------------------

    private function resolveCorporationContext(Request $request, ?array $allowedCorps): int
    {
        if ($request->filled('corporation_id')) {
            $requested = (int) $request->corporation_id;
            if ($allowedCorps === null || in_array($requested, $allowedCorps)) {
                return $requested;
            }
            abort(403, 'You do not have access to that corporation.');
        }

        // Land on the viewer's own corp first when they hit the page without
        // an explicit corporation_id — preferable to alphabetical-first.
        $ownCorp = $this->defaultCorporationId($allowedCorps);
        if ($ownCorp !== null) {
            return $ownCorp;
        }

        if ($allowedCorps === null) {
            $first = DB::table('character_affiliations')->value('corporation_id');
            if ($first) return (int) $first;
            abort(404, 'No corporations available.');
        }

        if (empty($allowedCorps)) {
            abort(403, 'No corporation access.');
        }

        return (int) $allowedCorps[0];
    }

    private function corporationPickerOptions(?array $allowedCorps)
    {
        $query = CorporationInfo::orderBy('name')->select(['corporation_id', 'name', 'ticker']);
        if ($allowedCorps !== null) {
            if (empty($allowedCorps)) return collect();
            $query->whereIn('corporation_id', $allowedCorps);
        }
        return $query->get();
    }

    private function assertPlayerInAllowedCorp(array $characterIds, ?array $allowedCorps): void
    {
        if ($allowedCorps === null) return;
        if (empty($characterIds)) abort(403, 'Player has no tracked characters.');

        $matches = DB::table('character_affiliations')
            ->whereIn('character_id', $characterIds)
            ->whereIn('corporation_id', $allowedCorps)
            ->exists();

        if (!$matches) abort(403, 'No accessible character on this player.');
    }

    private function assertPlayerExistsInCorp(int $userId, int $corporationId): void
    {
        $exists = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $userId)
            ->where('character_affiliations.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->exists();

        if (!$exists) abort(404, 'Player has no character in this corporation.');
    }
}

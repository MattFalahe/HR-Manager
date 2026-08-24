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

        // Identities folded into another human. SeAT still sees two accounts,
        // so both keep a row here — the badge says which one is the shell and
        // where its characters actually live, instead of leaving a director to
        // wonder why the person they merged is still listed twice.
        $mergedInto = [];
        if (\Illuminate\Support\Facades\Schema::hasColumn('hr_manager_player_identities', 'merged_into_id')) {
            $userIds = collect($players)->pluck('id')->map(fn ($i) => (int) $i)->all();
            if (!empty($userIds)) {
                $mergedInto = \HrManager\Models\PlayerIdentity::whereIn('seat_user_id', $userIds)
                    ->whereNotNull('merged_into_id')
                    ->with('mergedInto:id,primary_name,seat_user_id')
                    ->get()
                    ->keyBy('seat_user_id')
                    ->map(fn ($i) => [
                        'name'    => $i->mergedInto->primary_name ?? null,
                        'user_id' => $i->mergedInto->seat_user_id ?? null,
                    ])
                    ->all();
            }
        }

        return view('hr-manager::players.index', compact(
            'players', 'corporationId', 'corporations', 'tierAuto', 'mergedInto'
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

        // Heavy read-only panels (per-alt summary, role profiles, blueprint /
        // buyback, in-game titles, role alignment) come from the profile
        // warmer: a pre-warmed bundle when one exists (instant), otherwise built
        // + cached on the spot for the first viewer. The mutable panels a
        // director acts on (access depth, Discord, squads, notes, history) stay
        // live further down and are never bundled.
        $bundle = app(\HrManager\Services\PlayerProfileWarmer::class)->getBundle($userId, $corporationId);
        if ($bundle === null) {
            abort(404, 'Player not found.');
        }
        $summary           = $bundle['summary'];
        $characterIds      = $bundle['characterIds'];
        $titleSnapshot     = $bundle['titleSnapshot'];
        $roleProfiles      = $bundle['roleProfiles'];
        $fcActivity        = $bundle['fcActivity'];
        $blueprintActivity = $bundle['blueprintActivity'];
        $buyback           = $bundle['buyback'];
        $roleAlignment     = $bundle['roleAlignment'];

        $this->assertPlayerInAllowedCorp($characterIds, $allowedCorps);

        $viewerId = auth()->user()->id;
        $notes = $playerService->notesForPlayer($userId, $characterIds, $viewerId);
        $history = app(HistoryEventService::class)->timelineForPlayer($userId, $characterIds, 100);

        // Resolve the actor (who took each action) on the history timeline to a
        // main-character name. NULL actor = automated (rendered as "HR").
        $historyActorIds = $history->pluck('actor_user_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $historyActorNames = empty($historyActorIds)
            ? []
            : app(NameResolutionService::class)->getUserNames($historyActorIds);

        // Resolve the SUBJECT character (who each event is ABOUT). Per-alt
        // signals like wallet-stalled carry the specific character_id, so a
        // player with many alts otherwise sees a stack of identical-looking
        // rows with no way to tell which character each refers to.
        $historySubjectIds = $history->pluck('character_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $historySubjectNames = empty($historySubjectIds)
            ? []
            : app(NameResolutionService::class)->getCharacterNamesWithFallback($historySubjectIds);

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

        // Access depth: in-game corp roles/titles + SeAT account access, with
        // the sensitive access flagged and off-balance indicators surfaced.
        $accessDepth = app(\HrManager\Services\AccessDepthService::class)
            ->forPlayer($characterIds, $corporationId, $userId);

        // Discord identity + assigned roles (via seat-connector). Account-level,
        // so it belongs on the player view; empty when the player is
        // unregistered or the connector framework isn't installed.
        $connector = app(\HrManager\Services\SeatConnectorService::class);
        $discord = ($userId !== null && $userId > 0)
            ? $connector->getIdentityForUser((int) $userId)
            : ['available' => false, 'roles' => []];

        // Discord access depth — the account's Discord roles split by the
        // operator-curated "sensitive" list. Pure read over $discord, so it
        // only renders when the connector is installed and the user is mapped.
        $discordAccess = $connector->accessDepthForIdentity($discord);

        // Whether the SeAT Connector framework is installed at all (distinct
        // from whether THIS account is linked). Lets the header show a muted
        // "not on Discord" chip for an unlinked account instead of rendering
        // nothing — so the feature is visibly present, not seemingly missing.
        $connectorInstalled = $connector->isAvailable();

        // SeAT squad memberships (account-level). Shown so a director can see
        // what groups the player carries and clear them as part of a purge.
        $squads = ($userId !== null && $userId > 0)
            ? app(\HrManager\Services\SeatSquadService::class)->squadsForUser((int) $userId)
            : [];

        // Activity breakdown — the raw numbers behind the role badges, summed
        // across every alt. Batched, so it's cheap even for a many-alt account.
        $playerActivity = app(\HrManager\Services\PlayerActivityService::class)
            ->forCharacters($characterIds, $corporationId, null, $userId);

        // Mining engagement — ore output + moon mining + op attendance (only
        // when the corp actually runs ops), summed across alts.
        $miningEngagement = app(\HrManager\Services\CrossPluginDataService::class)
            ->getMiningEngagement($characterIds, $corporationId, (int) config('hr-manager.performance.activity_window_months', 6));

        // PvP activity — the account's most active PvP characters. Cache-peek
        // only (no cold zKill fetch on the page); characters warm as their own
        // view is opened. Top 3 with PvP data, ready to drill into.
        $pvpCharMap = $summary['characters']->pluck('name', 'character_id')->toArray();
        $pvpBreakdown = app(\HrManager\Services\ZkillService::class)->getAccountPvpBreakdown($pvpCharMap, true);
        $topPvp = array_slice(
            array_values(array_filter($pvpBreakdown['characters'] ?? [], fn ($r) => !empty($r['has_pvp']))),
            0, 3
        );

        // ACTIVE blacklist entries anywhere on this account. The profile is the
        // human view and it named every status a director might act on — tier,
        // LOA, purge, wallet flags — except the one that most obviously should
        // stop them: that the person is blacklisted. Deliberately blacklist-only
        // and active-only; a cleared entry is history (it's on the timeline) and
        // whitelist standing isn't a warning.
        // Hoisted: the alt-flag block below reads the same character set, and a
        // failure in the blacklist lookup must not leave it undefined.
        $blCharIds = collect($summary['alt_summaries'] ?? [])
            ->pluck('character_id')->map(fn ($c) => (int) $c)->filter()->all();

        $activeBlacklist = collect();
        try {
            if (!empty($blCharIds) && \Illuminate\Support\Facades\Schema::hasTable('hr_manager_watchlist_entries')) {
                $blQuery = \HrManager\Models\WatchlistEntry::whereIn('character_id', $blCharIds)
                    ->where('list_type', \HrManager\Models\WatchlistEntry::TYPE_BLACKLIST)
                    ->active()
                    ->orderByDesc('added_at');
                $this->applyWatchlistScopeVisibility($blQuery, $allowedCorps);
                $activeBlacklist = $blQuery->get();

                // Resolve scope corp names inline so the banner can name the
                // corp instead of printing a bare id.
                $blScopeIds = $activeBlacklist->pluck('scope_corporation_id')->filter()->unique()->all();
                $blCorpNames = !empty($blScopeIds)
                    ? \Seat\Eveapi\Models\Corporation\CorporationInfo::whereIn('corporation_id', $blScopeIds)
                        ->pluck('name', 'corporation_id')->toArray()
                    : [];
                $activeBlacklist = $activeBlacklist->map(function ($e) use ($blCorpNames) {
                    $e->scope_corp_name = $e->scope_corporation_id
                        ? ($blCorpNames[$e->scope_corporation_id] ?? ('Corp #' . $e->scope_corporation_id))
                        : null;
                    return $e;
                });
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] player blacklist lookup failed: ' . $e->getMessage());
        }

        // Suspected-alt claims touching this person, either end. Shown whether
        // or not anyone here is blacklisted: a claim that this player is the
        // alt of a blacklisted character is exactly the thing a recruiter needs
        // told, and it is information rather than an accusation — so it reads
        // as a flag, not as the red blacklist banner.
        $altFlags = collect();
        try {
            if (!empty($blCharIds) && \Illuminate\Support\Facades\Schema::hasTable('hr_manager_suspected_alt_links')) {
                $links = \HrManager\Models\SuspectedAltLink::whereIn('suspected_character_id', $blCharIds)
                    ->orWhereIn('main_character_id', $blCharIds)
                    ->orderByDesc('asserted_at')
                    ->get();

                // Is the character on the OTHER end of each claim blacklisted?
                // That is what turns "these might be the same person" into
                // something worth acting on.
                $counterparts = $links->map(fn ($l) => in_array((int) $l->suspected_character_id, $blCharIds, true)
                    ? (int) $l->main_character_id
                    : (int) $l->suspected_character_id)->unique()->filter()->all();

                $flaggedCounterparts = [];
                if (!empty($counterparts)) {
                    $cpQuery = \HrManager\Models\WatchlistEntry::whereIn('character_id', $counterparts)
                        ->where('list_type', \HrManager\Models\WatchlistEntry::TYPE_BLACKLIST)
                        ->active();
                    $this->applyWatchlistScopeVisibility($cpQuery, $allowedCorps);
                    $flaggedCounterparts = $cpQuery->pluck('character_id')->map(fn ($c) => (int) $c)->all();
                }

                $altFlags = $links->map(function ($l) use ($blCharIds, $flaggedCounterparts) {
                    $thisIsSuspected = in_array((int) $l->suspected_character_id, $blCharIds, true);
                    $otherId   = $thisIsSuspected ? (int) $l->main_character_id : (int) $l->suspected_character_id;
                    $otherName = $thisIsSuspected
                        ? ($l->main_character_name ?: ('#' . $l->main_character_id))
                        : ($l->suspected_character_name ?: ('#' . $l->suspected_character_id));

                    return [
                        'state'                 => $l->state,
                        // Direction matters for the wording: "this player may be
                        // an alt of X" reads very differently from "X may be an
                        // alt of this player".
                        'this_is_suspected'     => $thisIsSuspected,
                        'other_character_id'    => $otherId,
                        'other_character_name'  => $otherName,
                        'other_blacklisted'     => in_array($otherId, $flaggedCounterparts, true),
                        'resolution_note'       => $l->resolution_note,
                    ];
                });
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] player alt-flag lookup failed: ' . $e->getMessage());
        }

        // Watchlist coverage gap: when SOME of this account's characters are on
        // the watchlist, name the ones that aren't. A director who listed the
        // alts they knew about can't otherwise notice the ones they missed —
        // the account only became visible when the person authenticated.
        $altCoverage = ['listed' => [], 'uncovered' => []];
        try {
            $seedCharId = (int) (auth()->user() && $summary['user']
                ? ($summary['user']->main_character_id ?? 0)
                : 0);
            if ($seedCharId <= 0) {
                $seedCharId = (int) (collect($summary['alt_summaries'] ?? [])->first()['character_id'] ?? 0);
            }
            if ($seedCharId > 0) {
                $altCoverage = app(\HrManager\Services\SuspectedAltService::class)->coverageGap($seedCharId);
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] alt coverage gap failed: ' . $e->getMessage());
        }

        // Who performed the merge, for the shell banner. Resolved separately
        // because merged_by is a SeAT user id, not a character.
        // Leftover from a merge made BEFORE merge tracking existed: this
        // identity holds no characters, and a soft-deleted identity for the
        // same SeAT account is sitting behind it. That's the signature of the
        // old behaviour, where the merged-away identity was deleted and the
        // next lookup minted this empty replacement. We can spot it but not
        // say where the characters went — the pointer was never recorded — so
        // the profile offers the one-step fix instead of guessing.
        $identityOrphanHint = false;
        if ($identity
            && !$identity->isMerged()
            && $identity->seat_user_id
            && empty($identity->currentCharacterIds())) {
            try {
                $identityOrphanHint = \HrManager\Models\PlayerIdentity::onlyTrashed()
                    ->where('seat_user_id', $identity->seat_user_id)
                    ->exists();
            } catch (\Throwable $e) {
                $identityOrphanHint = false;
            }
        }

        $identityMergedByName = null;
        if ($identity && $identity->merged_by) {
            $identityMergedByName = app(\HrManager\Services\NameResolutionService::class)
                ->getUserNames([(int) $identity->merged_by])[(int) $identity->merged_by] ?? null;
        }

        // Direct ISK transfers between this human and entities the corp rates
        // badly. Read straight from the flags table the nightly scan writes;
        // nothing here touches the wallet journal.
        $donationFlags = app(\HrManager\Services\DonationScanService::class)
            ->flagsForCharacters($blCharIds, $allowedCorps);

        // Intel on this human. Notes are filed per CHARACTER while a profile is
        // a whole account, so a note written against an alt was invisible here
        // even though it is about the same person. Read-only and loaded from
        // the intel tables directly: no copy lives on the profile, so there is
        // nothing that can drift out of step with the dossier.
        $intelNotes      = collect();
        $intelNoteNames  = [];
        try {
            $viewerTier = auth()->user()->can('hr-manager.admin') ? 'admin'
                : (auth()->user()->can('hr-manager.director') ? 'director' : 'recruiter');

            $intelNotes = app(\HrManager\Services\IntelService::class)->notesForCharacters(
                $blCharIds,
                (int) auth()->user()->id,
                $allowedCorps,
                $viewerTier
            );

            if ($intelNotes->isNotEmpty()) {
                $intelNoteNames = app(\HrManager\Services\NameResolutionService::class)
                    ->getCharacterNamesWithFallback(
                        $intelNotes->pluck('character_id')->map(fn ($c) => (int) $c)->unique()->all()
                    );
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] player intel lookup failed: ' . $e->getMessage());
        }

        return view('hr-manager::players.show', compact(
            'activeBlacklist',
            'donationFlags',
            'intelNotes',
            'intelNoteNames',
            'altFlags',
            'identityOrphanHint',
            'identityMergedByName',
            'altCoverage',
            'summary', 'notes', 'history', 'corporationId',
            'corporations', 'tierAuto', 'titleSnapshot',
            'identity', 'identityCharNames', 'roleProfiles', 'fcActivity',
            'blueprintActivity', 'accessDepth', 'discord', 'squads',
            'noteAuthorNames', 'noteAuthorAdmins', 'historyActorNames',
            'historySubjectNames', 'buyback', 'roleAlignment', 'playerActivity',
            'miningEngagement', 'topPvp', 'discordAccess', 'connectorInstalled'
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
    /**
     * Limit watchlist entries to what this viewer may see: global entries plus
     * anything scoped to a corp (or that corp's alliance) they have access to.
     * Mirrors WatchlistController's rule so the profile banner can never reveal
     * an entry the Watchlist page itself would hide. Admins (null) see all.
     */
    private function applyWatchlistScopeVisibility($query, ?array $allowedCorps): void
    {
        if ($allowedCorps === null) {
            return;
        }

        $allowedAlliances = !empty($allowedCorps) && \Illuminate\Support\Facades\Schema::hasTable('corporation_infos')
            ? DB::table('corporation_infos')
                ->whereIn('corporation_id', $allowedCorps)
                ->whereNotNull('alliance_id')
                ->pluck('alliance_id')->map(fn ($id) => (int) $id)->unique()->all()
            : [];

        $query->where(function ($q) use ($allowedCorps, $allowedAlliances) {
            $q->where(function ($g) {
                $g->whereNull('scope_corporation_id')->whereNull('scope_alliance_id');
            });
            if (!empty($allowedCorps)) {
                $q->orWhereIn('scope_corporation_id', $allowedCorps);
            }
            if (!empty($allowedAlliances)) {
                $q->orWhereIn('scope_alliance_id', $allowedAlliances);
            }
        });
    }

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

        $removed = app(\HrManager\Services\SeatSquadService::class)->removeUserFromRemovableSquads($userId);

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
        $request->validate([
            'corporation_id' => 'required|integer',
            'cancel_reason'  => 'nullable|string|max:1000',
        ]);

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);

        $status = PlayerStatus::where('user_id', $userId)
            ->where('corporation_id', $corporationId)
            ->first();

        if (!$status) {
            return redirect()->back()->with('success', trans('hr-manager::players.status_already_active'));
        }

        $wasPurge = ($status->status === PlayerStatus::STATUS_MARKED_FOR_PURGE);
        $isAutoPurge = $wasPurge && $status->purge_origin === PlayerStatus::ORIGIN_TOKEN_LOSS;

        // Cancelling a purge schedule requires director (recruiters can clear LOA).
        if ($wasPurge && !auth()->user()->can('hr-manager.director')) {
            return redirect()->back()
                ->with('error', trans('hr-manager::players.cancel_purge_director_required'));
        }

        // Overriding a security AUTO-purge (token loss) demands a written reason,
        // recorded as an accountable override — a director is countermanding the
        // Watchdog's security decision.
        $reason = trim((string) $request->input('cancel_reason', ''));
        if ($isAutoPurge && $reason === '') {
            return redirect()->back()
                ->with('error', trans('hr-manager::players.cancel_auto_purge_reason_required'))
                ->withInput();
        }

        $actorId = (int) auth()->user()->id;

        $status->update([
            'status'                  => PlayerStatus::STATUS_ACTIVE,
            'loa_until'               => null,
            'purge_scheduled_for'     => null,
            'purge_squads_removed_at' => null,
            'reason'                  => $isAutoPurge ? ('Override: ' . $reason) : null,
            'purge_origin'            => PlayerStatus::ORIGIN_MANUAL,
            'status_set_by'           => $actorId,
            'status_set_at'           => now(),
        ]);

        app(HistoryEventService::class)->record(
            $wasPurge ? 'hr.purge.cancelled' : 'hr.player.status_cleared',
            $isAutoPurge ? ['override' => true, 'reason' => $reason] : [],
            [
                'user_id'        => $userId,
                'corporation_id' => $corporationId,
                'actor_user_id'  => $actorId,
                'occurred_at'    => now(),
            ]
        );

        // An override of the Watchdog's security decision is noted on the player
        // timeline, attributed to the director who made the call.
        if ($isAutoPurge) {
            Note::create([
                'noteable_type' => 'player',
                'noteable_id'   => $userId,
                'author_id'     => $actorId,
                'content'       => 'Overrode the HR Watchdog security auto-purge (SeAT token loss) and cleared the status. Reason: ' . $reason,
                'is_private'    => false,
            ]);
        }

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
            'as_intel'       => 'nullable|boolean',
        ]);

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);
        $this->assertPlayerExistsInCorp($userId, $corporationId);

        // Destination chosen when the note is written, rather than a copy made
        // afterwards. A player note and an intel note answer different
        // questions -- one is about managing this member, the other is about
        // the character and outlives their membership -- and mirroring the text
        // into both tables would leave two rows that drift apart the first time
        // anyone edits one. So it goes to exactly one place.
        if ($request->boolean('as_intel')) {
            $result = $this->recordPlayerNoteAsIntel($userId, $corporationId, (string) $request->content, $request->boolean('is_private'));

            return redirect()->route('hr-manager.players.show', [
                'id' => $id, 'corporation_id' => $corporationId,
            ])->with($result['ok'] ? 'success' : 'error', $result['message']);
        }

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

    /**
     * Write a player note into the intel database instead of the notes table.
     *
     * Notes are keyed to a SeAT ACCOUNT and intel to a CHARACTER, so this has
     * to pick one. It uses the account's main character: the alternative --
     * filing against every character on the account -- is the duplication that
     * turned six intended notes into eleven the last time intel spread itself
     * across an account, and the dossier now shows the whole account anyway, so
     * one row is read from every one of their characters regardless.
     *
     * Scoped to the corp whose page the director was on. A note written while
     * looking at a specific corp is about that corp's business, and intel with
     * no scope is visible to every corp on the install.
     *
     * @return array{ok:bool, message:string}
     */
    private function recordPlayerNoteAsIntel(int $userId, int $corporationId, string $body, bool $isPrivate): array
    {
        $mainId = $this->mainCharacterFor($userId);
        if ($mainId === null) {
            // Nothing to file against. Say so rather than silently dropping the
            // note or quietly writing it somewhere the director did not choose.
            return ['ok' => false, 'message' => trans('hr-manager::intel.as_intel_no_character')];
        }

        try {
            $name = app(\HrManager\Services\NameResolutionService::class)->getCharacterName($mainId);

            \HrManager\Models\IntelNote::create([
                'character_id'         => $mainId,
                'character_name'       => $name ?: ('Character #' . $mainId),
                'scope_corporation_id' => $corporationId,
                'body'                 => $body,
                'tags'                 => [],
                // A private note is the director thinking aloud, so it must not
                // become recruiter-visible just by changing table.
                'recruiter_visible'    => false,
                'author_id'            => (int) auth()->user()->id,
                'expires_at'           => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] player note-to-intel failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => trans('hr-manager::intel.as_intel_failed')];
        }

        return [
            'ok'      => true,
            'message' => trans('hr-manager::intel.as_intel_saved', [
                'name' => $name ?: ('#' . $mainId),
            ]),
        ];
    }

    /**
     * The account's main character, falling back to any character it holds.
     *
     * users.main_character_id is what SeAT itself calls the main, so it is the
     * right answer when set. A fallback matters because an account can hold
     * characters without ever nominating one, and refusing to record intel over
     * a missing preference would be a poor trade.
     */
    private function mainCharacterFor(int $userId): ?int
    {
        try {
            $main = DB::table('users')->where('id', $userId)->value('main_character_id');
            if ($main) {
                return (int) $main;
            }

            $any = DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->orderBy('character_id')
                ->value('character_id');

            return $any ? (int) $any : null;
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] main character lookup failed: ' . $e->getMessage());
            return null;
        }
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

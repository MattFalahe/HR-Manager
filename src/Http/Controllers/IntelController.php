<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\IntelNote;
use HrManager\Services\AuditService;
use HrManager\Services\IntelService;
use HrManager\Services\NameResolutionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class IntelController extends Controller
{
    use ScopesCorporationAccess;

    /**
     * Index page. Visible to recruiters when the install-level setting
     * is on; visible to directors always.
     */
    public function index(Request $request, IntelService $intel)
    {
        $this->assertCanViewIntel($intel);

        $allowedCorps = $this->getAllowedCorpIds();
        $viewerUserId = (int) auth()->user()->id;
        $viewerTier   = $this->viewerTier();

        $notes = $intel->index(
            $viewerUserId,
            $allowedCorps,
            $viewerTier,
            $request->input('search'),
            $request->input('tag')
        );

        // Resolve corporation names once for the displayed scopes.
        $corpIds = $notes->pluck('scope_corporation_id')->filter()->unique()->all();
        $corpNames = !empty($corpIds)
            ? CorporationInfo::whereIn('corporation_id', $corpIds)->pluck('name', 'corporation_id')->toArray()
            : [];

        $corporations = $this->corporationPickerOptions($allowedCorps);

        // Tag suggestions for the add form + filter dropdown
        $suggestedTags = ['spy', 'scammer', 'drama', 'fc', 'industrialist', 'miner', 'ratter', 'mentor', 'reliable', 'alt-confirmed'];

        return view('hr-manager::intel.index', compact(
            'notes',
            'corpNames',
            'corporations',
            'suggestedTags',
            'viewerTier'
        ));
    }

    /**
     * Per-character intel page. Shows every visible note for one
     * character_id plus any watchlist match for context.
     */
    public function show(int $characterId, IntelService $intel)
    {
        $this->assertCanViewIntel($intel);

        $allowedCorps = $this->getAllowedCorpIds();
        $viewerUserId = (int) auth()->user()->id;
        $viewerTier   = $this->viewerTier();

        $resolver = app(NameResolutionService::class);

        // Every character proven to be the same human. Notes are filed per
        // character, so a dossier that showed only this one would hide the rest
        // of what is known about the person -- which is the thing a director
        // actually opened the page to find out.
        $accountIds = [$characterId];
        try {
            foreach (app(\HrManager\Services\AccountCharacterResolver::class)->siblingsFor($characterId) as $sib) {
                $accountIds[] = (int) $sib['character_id'];
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] intel dossier account lookup failed: ' . $e->getMessage());
        }
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), fn ($v) => $v > 0)));

        // Claims are NOT proof, so they never widen the note set. They are
        // shown as claims, and the director decides what to make of them.
        $altLinks = $this->altLinksFor($accountIds);

        // Visibility filtering happens inside the service, so widening the id
        // set cannot widen what this viewer is allowed to read.
        $allNotes = $intel->notesForCharacters($accountIds, $viewerUserId, $allowedCorps, $viewerTier);

        $notes     = $allNotes->where('character_id', $characterId)->values();
        $altNotes  = $allNotes->where('character_id', '!=', $characterId)
            ->groupBy('character_id');

        if ($allNotes->isEmpty() && !$intel->canContribute()) {
            abort(404, 'No intel for that character that you can see.');
        }

        // Names for every character on the page: the subject, the account's
        // other characters, and both ends of any claim.
        $nameIds = array_merge(
            $accountIds,
            $allNotes->pluck('character_id')->map(fn ($c) => (int) $c)->all(),
            $altLinks->pluck('other_character_id')->map(fn ($c) => (int) $c)->all()
        );
        $charNames = $resolver->getCharacterNamesWithFallback($nameIds);

        // Resolve even with zero notes, so a director who has just added one
        // sees the character rather than a bare id.
        $displayName = $notes->first()?->character_name
            ?? ($charNames[$characterId] ?? null)
            ?? $resolver->getCharacterName($characterId)
            ?? ('Character #' . $characterId);

        // Watchlist context (already gated by scope inside the service).
        $watchlistMatch = app(\HrManager\Services\WatchlistService::class)
            ->findMatch($characterId, $allowedCorps);

        $corporations = $this->corporationPickerOptions($allowedCorps);
        $suggestedTags = ['spy', 'scammer', 'drama', 'fc', 'industrialist', 'miner', 'ratter', 'mentor', 'reliable', 'alt-confirmed'];

        return view('hr-manager::intel.show', compact(
            'characterId',
            'displayName',
            'notes',
            'altNotes',
            'altLinks',
            'charNames',
            'accountIds',
            'watchlistMatch',
            'corporations',
            'suggestedTags'
        ));
    }

    /**
     * Suspected-alt claims touching any character on this account, from either
     * end of the claim.
     *
     * A claim says a director once asserted two characters are the same human.
     * It is deliberately kept apart from the account set above: that one is
     * proven (shared SeAT account or an HR identity a director merged), this
     * one is somebody's assertion, and the dossier should not quietly promote
     * the second into the first.
     *
     * @param array<int> $accountIds
     */
    private function altLinksFor(array $accountIds)
    {
        if (empty($accountIds) || !\Illuminate\Support\Facades\Schema::hasTable('hr_manager_suspected_alt_links')) {
            return collect();
        }

        try {
            $links = \HrManager\Models\SuspectedAltLink::where(function ($q) use ($accountIds) {
                $q->whereIn('suspected_character_id', $accountIds)
                  ->orWhereIn('main_character_id', $accountIds);
            })->orderByDesc('asserted_at')->get();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] intel alt-link lookup failed: ' . $e->getMessage());
            return collect();
        }

        return $links->map(function ($l) use ($accountIds) {
            $thisIsSuspected = in_array((int) $l->suspected_character_id, $accountIds, true);
            $otherId = $thisIsSuspected ? (int) $l->main_character_id : (int) $l->suspected_character_id;

            return [
                'state'                => $l->state,
                // Direction changes the meaning entirely: "this player may be
                // an alt of X" is a different claim from "X may be an alt of
                // this player".
                'this_is_suspected'    => $thisIsSuspected,
                'other_character_id'   => $otherId,
                'other_character_name' => $thisIsSuspected
                    ? ($l->main_character_name ?: ('#' . $l->main_character_id))
                    : ($l->suspected_character_name ?: ('#' . $l->suspected_character_id)),
                'resolution_note'      => $l->resolution_note,
            ];
        })->unique(fn ($r) => $r['other_character_id'] . ':' . $r['this_is_suspected'])->values();
    }

    /**
     * Add a new intel note. Director-only.
     */
    public function store(Request $request, IntelService $intel)
    {
        if (!$intel->canContribute()) {
            abort(403, 'Director permission required to add intel notes.');
        }

        $request->validate([
            'input'                => 'required|string|min:1|max:64',
            'suspected_alts'       => 'nullable|string|max:4000',
            'body'                 => 'required|string|max:8000',
            'scope_corporation_id' => 'nullable|integer',
            'tags'                 => 'nullable|string|max:255',
            'recruiter_visible'    => 'nullable|boolean',
            'expires_at'           => 'nullable|date|after:today',
            'include_alts'         => 'nullable|boolean',
        ]);

        $scope = $request->filled('scope_corporation_id')
            ? (int) $request->scope_corporation_id
            : null;
        if ($scope !== null) {
            $this->assertCanAccessCorp($scope);
        }

        // One character per line. Someone who was never in SeAT has no account
        // for HR to expand, so listing their known alts by hand is the only way
        // to file intel against the whole group — ESI exposes no account concept
        // that could prove the link automatically.
        $resolver = app(NameResolutionService::class);
        $resolveOne = function (string $token) use ($resolver) {
            $token = trim($token);
            if ($token === '') {
                return null;
            }
            if (ctype_digit($token)) {
                $id = (int) $token;
                return $id > 0 ? ['id' => $id, 'name' => $resolver->getCharacterName($id)] : null;
            }
            $r = $resolver->getIdFromCharacterName($token);
            return isset($r['character_id']) && $r['character_id']
                ? ['id' => (int) $r['character_id'], 'name' => $r['character_name'] ?? null]
                : null;
        };

        $tokens = preg_split('/\r\n|\r|\n/', (string) $request->input('suspected_alts', '')) ?: [];
        $tokens = array_slice(array_values(array_unique(array_filter(
            array_map('trim', $tokens),
            function ($t) { return $t !== ''; }
        ))), 0, 50);

        // The main drives the redirect and the immediate scope check. An alt
        // line that won't resolve is reported rather than discarding the notes
        // that did land.
        $primary = $resolveOne((string) $request->input('input'));
        if ($primary === null) {
            return redirect()->back()->with('error', 'Could not resolve that name to a character. Try the character ID directly.')->withInput();
        }
        $cid   = $primary['id'];
        $cname = $primary['name'];

        $extraTargets = [];
        $extraFailed  = [];
        foreach ($tokens as $token) {
            $res = $resolveOne($token);
            if ($res === null) {
                $extraFailed[] = $token;
                continue;
            }
            if ((int) $res['id'] === $cid) {
                continue; // same character listed twice
            }
            $extraTargets[$res['id']] = $res['name'] ?: ('Character #' . $res['id']);
        }

        // Parse comma-separated tags into a normalized array.
        $tagsInput = $request->input('tags', '');
        $tagsArr = array_values(array_filter(array_map(
            fn($t) => preg_replace('/[^a-z0-9\-]/i', '', trim(strtolower($t))),
            explode(',', (string) $tagsInput)
        ), fn($t) => $t !== ''));

        $note = IntelNote::create([
            'character_id'         => $cid,
            'character_name'       => $cname ?: ('Character #' . $cid),
            'scope_corporation_id' => $scope,
            'body'                 => $request->input('body'),
            'tags'                 => $tagsArr,
            'recruiter_visible'    => (bool) $request->input('recruiter_visible', false),
            'author_id'            => (int) auth()->user()->id,
            'expires_at'           => $request->filled('expires_at') ? \Carbon\Carbon::parse($request->expires_at) : null,
        ]);

        // Immediate scope check: if this character is ALREADY inside a corp the
        // note watches, alert that corp's webhook now instead of waiting for the
        // 15-minute scan. Best-effort + idempotent (the note's scope_alert_corp_id
        // dedups against the periodic pass) so it never blocks the save.
        $immediateHit = false;
        try {
            $immediateHit = app(\HrManager\Services\WatchlistMonitorService::class)->checkIntelNoteNow($note);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR] intel immediate scope check failed: ' . $e->getMessage());
        }

        // Everyone else who should carry this note, gathered into ONE set before
        // anything is written.
        //
        // Two independent things put a character here: the director typed them
        // into the possible-alts box, and the include-alts toggle expanded the
        // account. Those overlap constantly (a hand-listed alt that is ALSO
        // registered on the same SeAT account is the normal case, not the edge
        // case) and writing from two separate loops gave that character the
        // note twice. Keying by character id makes the overlap harmless and
        // keeps one place deciding who gets a note.
        //
        // Hand-listed entries are tracked separately because only they earn a
        // suspected-alt claim: the director ASSERTED that link, whereas an
        // account sibling is already proven and needs no claim.
        $claimed = [];   // character_id => name, listed by hand
        $targets = [];   // character_id => name, everyone bar the main

        foreach ($extraTargets as $exId => $exName) {
            $claimed[(int) $exId] = $exName;
            $targets[(int) $exId] = $exName;
        }

        $alsoAdded = [];
        if ($request->boolean('include_alts')) {
            try {
                foreach (app(\HrManager\Services\AccountCharacterResolver::class)->siblingsFor($cid) as $sib) {
                    if (!empty($sib['is_seed'])) {
                        continue;
                    }
                    $sibId = (int) $sib['character_id'];
                    if ($sibId === $cid || isset($targets[$sibId])) {
                        continue; // the main, or already listed by hand
                    }
                    $targets[$sibId] = $sib['name'];
                    $alsoAdded[]     = $sib['name'];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[HR Manager] intel alt bulk-add failed: ' . $e->getMessage());
            }
        }

        $extraAdded = [];
        $linksMade  = 0;
        $altService = app(\HrManager\Services\SuspectedAltService::class);

        foreach ($targets as $exId => $exName) {
            try {
                $altNote = IntelNote::create([
                    'character_id'         => (int) $exId,
                    'character_name'       => $exName,
                    'scope_corporation_id' => $scope,
                    'body'                 => $request->input('body'),
                    'tags'                 => $tagsArr,
                    'recruiter_visible'    => (bool) $request->input('recruiter_visible', false),
                    'author_id'            => (int) auth()->user()->id,
                    'expires_at'           => $request->filled('expires_at') ? \Carbon\Carbon::parse($request->expires_at) : null,
                ]);

                // Only a hand-listed alt is a CLAIM. An account sibling is
                // already proven to be the same human, so recording a claim
                // about it would assert something HR can already see.
                if (isset($claimed[$exId])) {
                    if ($altService->record(
                        (int) $exId,
                        $cid,
                        $exName,
                        $cname,
                        \HrManager\Models\SuspectedAltLink::SOURCE_INTEL,
                        (int) $altNote->id,
                        (int) auth()->user()->id
                    )) {
                        $linksMade++;
                    }
                    $extraAdded[] = $exName;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[HR Manager] intel multi-add failed for ' . $exId . ': ' . $e->getMessage());
                $extraFailed[] = $exName;
            }
        }

        // Audit trail (security): an intel note was recorded on a character.
        app(AuditService::class)->action('intel.add', AuditService::CAT_SECURITY, [
            'target_type'    => 'intel',
            'target_id'      => (int) $cid,
            'target_label'   => $cname ?: ('#' . $cid),
            'corporation_id' => $scope,
            'summary'        => 'Added an intel note on ' . ($cname ?: ('#' . $cid))
                . (!empty($alsoAdded) ? ' (+' . count($alsoAdded) . ' alts of the same account)' : ''),
            'context'        => [
                'recruiter_visible' => (bool) $request->input('recruiter_visible', false),
                'alts_added'        => $alsoAdded ?: null,
            ],
        ]);

        $flash = trans('hr-manager::intel.note_added' . ($immediateHit ? '_with_hit' : ''));
        if (!empty($extraAdded)) {
            $flash .= ' ' . trans('hr-manager::intel.extra_added', [
                'count' => count($extraAdded),
                'names' => implode(', ', $extraAdded),
            ]);
        }
        if ($linksMade > 0) {
            $flash .= ' ' . trans('hr-manager::intel.alt_links_added', [
                'count' => $linksMade,
                'main'  => $cname ?: ('#' . $cid),
            ]);
        }
        if (!empty($extraFailed)) {
            $flash .= ' ' . trans('hr-manager::intel.extra_failed', [
                'names' => implode(', ', $extraFailed),
            ]);
        }
        if (!empty($alsoAdded)) {
            $flash .= ' ' . trans('hr-manager::intel.alts_also_added', [
                'count' => count($alsoAdded),
                'names' => implode(', ', $alsoAdded),
            ]);
        }

        return redirect()->route('hr-manager.intel.show', $cid)->with('success', $flash);
    }

    public function destroy(int $id, IntelService $intel)
    {
        if (!$intel->canContribute()) {
            abort(403, 'Director permission required to remove intel notes.');
        }

        $note = IntelNote::findOrFail($id);
        if ($note->scope_corporation_id !== null) {
            $this->assertCanAccessCorp($note->scope_corporation_id);
        }

        $characterId = $note->character_id;
        $charName    = $note->character_name ?: ('#' . $characterId);
        $scopeCorp   = $note->scope_corporation_id;
        $note->delete();

        // Audit trail (security): an intel note was deleted. Uses the snapshot
        // captured before the delete.
        app(AuditService::class)->action('intel.delete', AuditService::CAT_SECURITY, [
            'target_type'    => 'intel',
            'target_id'      => (int) $characterId,
            'target_label'   => $charName,
            'corporation_id' => $scopeCorp,
            'summary'        => 'Deleted an intel note on ' . $charName,
        ]);

        return redirect()->route('hr-manager.intel.show', $characterId)
            ->with('success', trans('hr-manager::intel.note_removed'));
    }

    // -----------------------------------------------------------------

    private function assertCanViewIntel(IntelService $intel): void
    {
        $user = auth()->user();
        if (!$user) {
            abort(403);
        }
        if ($user->can('hr-manager.director') || $user->can('hr-manager.admin')) {
            return;
        }
        if ($user->can('hr-manager.recruiter') && $intel->recruiterViewEnabled()) {
            return;
        }
        abort(403, 'You do not have access to the intel database.');
    }

    private function viewerTier(): string
    {
        $user = auth()->user();
        if ($user->can('hr-manager.admin')) return 'admin';
        if ($user->can('hr-manager.director')) return 'director';
        return 'recruiter';
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
}

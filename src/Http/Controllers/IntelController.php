<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\IntelNote;
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

        $notes = $intel->notesForCharacter($characterId, $viewerUserId, $allowedCorps, $viewerTier);

        if ($notes->isEmpty() && !$intel->canContribute()) {
            abort(404, 'No intel for that character that you can see.');
        }

        // Try to resolve the name even if there are zero notes (so the
        // contributing director sees the character on a fresh add).
        $resolver = app(NameResolutionService::class);
        $displayName = $notes->first()?->character_name
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
            'watchlistMatch',
            'corporations',
            'suggestedTags'
        ));
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
            'body'                 => 'required|string|max:8000',
            'scope_corporation_id' => 'nullable|integer',
            'tags'                 => 'nullable|string|max:255',
            'recruiter_visible'    => 'nullable|boolean',
            'expires_at'           => 'nullable|date|after:today',
        ]);

        $scope = $request->filled('scope_corporation_id')
            ? (int) $request->scope_corporation_id
            : null;
        if ($scope !== null) {
            $this->assertCanAccessCorp($scope);
        }

        // Resolve name -> ID OR validate the ID via the shared service.
        $resolver = app(NameResolutionService::class);
        $rawInput = trim((string) $request->input('input'));
        if (ctype_digit($rawInput)) {
            $cid = (int) $rawInput;
            $cname = $resolver->getCharacterName($cid);
        } else {
            $r = $resolver->getIdFromCharacterName($rawInput);
            $cid = $r['character_id'] ?? null;
            $cname = $r['character_name'] ?? null;
            if ($cid === null) {
                return redirect()->back()->with('error', 'Could not resolve that name to a character. Try the character ID directly.')->withInput();
            }
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

        return redirect()->route('hr-manager.intel.show', $cid)
            ->with('success', trans('hr-manager::intel.note_added' . ($immediateHit ? '_with_hit' : '')));
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
        $note->delete();

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

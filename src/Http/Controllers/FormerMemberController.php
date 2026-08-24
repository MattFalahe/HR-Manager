<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\Note;
use HrManager\Services\IntelService;
use HrManager\Services\MemberArchiveService;
use HrManager\Services\NameResolutionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * People who used to be members.
 *
 * Deliberately its own surface rather than a filter on Players. The Members and
 * Players views are live rosters: every column on them (tier, token health,
 * days inactive) is a statement about somebody currently in the corp, and none
 * of it means anything for a person who left eighteen months ago. More
 * importantly the access rule is different -- those pages ask whether somebody
 * is in a corp you manage RIGHT NOW, which is exactly the question that stops
 * being answerable the moment they leave.
 *
 * Here, access comes from the archived stint's own corporation_id. A director
 * who could read somebody's file while they were a member can still read it
 * afterwards, and a director of a corp they were never in still cannot. That is
 * the same durable scoping intel notes use, and the reason intel survives a
 * departure when a player note does not.
 */
class FormerMemberController extends Controller
{
    use ScopesCorporationAccess;

    public function index(Request $request, MemberArchiveService $archives)
    {
        $allowedCorps = $this->getAllowedCorpIds();
        $search       = trim((string) $request->input('search', ''));

        $people = $archives->formerMembers($allowedCorps, $search ?: null);

        // Corp names for the badges. Only the corps actually on the page, not
        // every corporation SeAT has ever resolved -- that list runs to
        // thousands and rendering against it is what blew the settings page.
        $corpIds = [];
        foreach ($people as $person) {
            foreach ($person['corporation_ids'] as $cid) {
                $corpIds[$cid] = $cid;
            }
        }
        $corpNames = empty($corpIds) ? [] : CorporationInfo::whereIn('corporation_id', array_values($corpIds))
            ->pluck('name', 'corporation_id')->toArray();

        $reconstructed = 0;
        foreach ($people as $person) {
            if ($person['reconstructed']) {
                $reconstructed++;
            }
        }

        return view('hr-manager::former-members.index', compact(
            'people', 'corpNames', 'search', 'reconstructed'
        ));
    }

    /**
     * One former member's frozen record.
     *
     * The archive holds figures. Everything else -- notes, intel, history -- is
     * read live from tables that were never at risk, so this page links to the
     * real records rather than keeping copies that could fall out of step.
     */
    public function show(Request $request, string $key, MemberArchiveService $archives)
    {
        $allowedCorps = $this->getAllowedCorpIds();

        $stints = $archives->stintsForHuman($key, $allowedCorps);
        if ($stints->isEmpty()) {
            abort(404, 'No archived membership you can see for that person.');
        }

        $characterIds = $stints->pluck('character_id')->map(fn ($c) => (int) $c)->unique()->values()->all();
        $userId       = $stints->first()->user_id;

        $names = app(NameResolutionService::class)->getCharacterNamesWithFallback($characterIds);

        $corpIds = $stints->pluck('corporation_id')->merge(
            $stints->pluck('destination_corporation_id')
        )->filter()->map(fn ($c) => (int) $c)->unique()->values()->all();

        $corpNames = empty($corpIds) ? [] : CorporationInfo::whereIn('corporation_id', $corpIds)
            ->pluck('name', 'corporation_id')->toArray();

        $displayName = $stints->first()->character_name ?: ($names[$stints->first()->character_id] ?? ('#' . $stints->first()->character_id));

        // Notes are keyed to the ACCOUNT, so an unregistered member has none to
        // find. That is a fact about the record, not a failure to look.
        $notes = collect();
        if ($userId) {
            try {
                $notes = Note::where('noteable_type', 'player')
                    ->where('noteable_id', $userId)
                    ->orderByDesc('created_at')
                    ->get();
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] former-member note read failed: ' . $e->getMessage());
            }
        }

        $intelNotes = collect();
        try {
            $viewerTier = auth()->user()->can('hr-manager.admin') ? 'admin'
                : (auth()->user()->can('hr-manager.director') ? 'director' : 'recruiter');

            $intelNotes = app(IntelService::class)->notesForCharacters(
                $characterIds,
                (int) auth()->user()->id,
                $allowedCorps,
                $viewerTier
            );
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] former-member intel read failed: ' . $e->getMessage());
        }

        $noteAuthorNames = [];
        $authorIds = $notes->pluck('author_id')->merge($intelNotes->pluck('author_id'))
            ->filter()->map(fn ($a) => (int) $a)->unique()->values()->all();
        if (!empty($authorIds)) {
            $noteAuthorNames = app(NameResolutionService::class)->getUserNames($authorIds);
        }

        return view('hr-manager::former-members.show', compact(
            'key', 'stints', 'displayName', 'names', 'corpNames',
            'notes', 'intelNotes', 'noteAuthorNames', 'userId', 'characterIds'
        ));
    }
}

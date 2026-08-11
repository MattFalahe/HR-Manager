<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\MemberHistoryEvent;
use HrManager\Models\WatchlistEntry;
use HrManager\Services\AuditService;
use HrManager\Services\IntelService;
use HrManager\Services\NameResolutionService;
use HrManager\Services\WatchlistService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class WatchlistController extends Controller
{
    use ScopesCorporationAccess;

    public function index(Request $request)
    {
        $allowedCorps = $this->getAllowedCorpIds();

        // List filter: blacklist / whitelist / cleared.
        $listType = $request->input('list_type', WatchlistEntry::TYPE_BLACKLIST);
        $showCleared = $request->input('list_type') === 'cleared';
        if (!in_array($listType, [WatchlistEntry::TYPE_BLACKLIST, WatchlistEntry::TYPE_WHITELIST, 'cleared'], true)) {
            $listType = WatchlistEntry::TYPE_BLACKLIST;
        }

        $query = WatchlistEntry::with('addedByUser')
            ->orderByDesc('added_at');

        if ($showCleared) {
            $query->cleared();
        } else {
            $query->where('list_type', $listType)->active();
        }

        // Scope visibility: viewer must have access to the scope corp,
        // be in the scope alliance, or it's global.
        if ($allowedCorps !== null) {
            $allowedAlliances = !empty($allowedCorps) && \Illuminate\Support\Facades\Schema::hasTable('corporation_infos')
                ? \Illuminate\Support\Facades\DB::table('corporation_infos')
                    ->whereIn('corporation_id', $allowedCorps)
                    ->whereNotNull('alliance_id')
                    ->pluck('alliance_id')
                    ->map(fn($id) => (int) $id)
                    ->unique()
                    ->all()
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

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('character_name', 'like', "%{$search}%")
                  ->orWhere('character_id', 'like', "%{$search}%");
            });
        }

        // Display mode: flat by-character list (default, paginated) or grouped by
        // human account (all matching entries, capped, so alts of one person read
        // as one block instead of scattered rows).
        $groupMode = $request->input('group') === 'account' ? 'account' : 'character';

        $entries = null;
        $accountGroups = null;
        if ($groupMode === 'account') {
            $allEntries = $query->limit(500)->get();
            $accountGroups = $this->groupEntriesByAccount($allEntries);
            $corpSource = $allEntries;
        } else {
            $entries = $query->paginate(50);
            $corpSource = $entries;
        }

        // Bulk-resolve corp names for the displayed entries.
        $corpIds = $corpSource->pluck('scope_corporation_id')->filter()->unique()->all();
        $corpNames = !empty($corpIds)
            ? CorporationInfo::whereIn('corporation_id', $corpIds)->pluck('name', 'corporation_id')->toArray()
            : [];

        // Headline counts — ACTIVE entries only. The badges must not count
        // cleared entries (those live in the audit list), otherwise the
        // blacklist/whitelist chip still reads "1" after you clear the last
        // entry. active() adds both the status='active' and not-expired
        // filters the list itself uses.
        $countsQuery = WatchlistEntry::active()
            ->selectRaw('list_type, COUNT(*) as cnt')
            ->groupBy('list_type');
        if ($allowedCorps !== null) {
            $countsQuery->where(function ($q) use ($allowedCorps) {
                $q->whereNull('scope_corporation_id')
                  ->orWhereIn('scope_corporation_id', $allowedCorps);
            });
        }
        $counts = $countsQuery->pluck('cnt', 'list_type')->toArray();
        $blacklistCount = (int) ($counts[WatchlistEntry::TYPE_BLACKLIST] ?? 0);
        $whitelistCount = (int) ($counts[WatchlistEntry::TYPE_WHITELIST] ?? 0);

        // Corporation picker for the "scope" dropdown on the add form.
        $corporations = $this->corporationPickerOptions($allowedCorps);

        // Per-character count of ACTIVE entries across its whole account, so the
        // clear button can offer "clear all N on this account" when a spy's alts
        // are all listed (only prompts when the count is > 1).
        $accountActiveCounts = $this->accountActiveCounts($corpSource);

        // Suspected-alt links for the characters on THIS page, so a row can
        // badge itself "suspected / confirmed / refuted alt of X". Keyed by
        // character id; empty before the table exists.
        $altLinks = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('hr_manager_suspected_alt_links')) {
            $pageCharIds = collect($entries ?? [])->pluck('character_id')
                ->merge(collect($accountGroups ?? [])->pluck('entries')->flatten(1)->pluck('character_id'))
                ->filter()->map(function ($id) { return (int) $id; })->unique()->values()->all();

            if (!empty($pageCharIds)) {
                $altLinks = \HrManager\Models\SuspectedAltLink::whereIn('suspected_character_id', $pageCharIds)
                    ->get()
                    ->keyBy('suspected_character_id');
            }
        }

        return view('hr-manager::watchlist.index', compact(
            'entries',
            'altLinks',
            'accountGroups',
            'groupMode',
            'listType',
            'corpNames',
            'blacklistCount',
            'whitelistCount',
            'corporations',
            'accountActiveCounts'
        ));
    }

    /**
     * For each displayed character, how many ACTIVE watchlist entries exist
     * across its whole account (main + alts, via the shared SeAT token owner).
     * Characters with no linked account (or no siblings) count as 1. Drives the
     * "clear one vs. clear the whole account" prompt.
     *
     * @return array<int,int> character_id => active-entry count on its account
     */
    private function accountActiveCounts($entries): array
    {
        $charIds = $entries->pluck('character_id')->map(fn ($c) => (int) $c)->filter()->unique()->values()->all();
        if (empty($charIds)) {
            return [];
        }

        // Displayed char → account (SeAT user).
        $charToUser = DB::table('refresh_tokens')
            ->whereIn('character_id', $charIds)
            ->whereNull('deleted_at')
            ->pluck('user_id', 'character_id')->toArray();

        $userIds = array_values(array_unique(array_filter(array_map('intval', $charToUser))));
        if (empty($userIds)) {
            return array_fill_keys($charIds, 1);
        }

        // Every character on those accounts → its owning account.
        $charToUserAll = DB::table('refresh_tokens')
            ->whereIn('user_id', $userIds)
            ->whereNull('deleted_at')
            ->pluck('user_id', 'character_id')->toArray();

        // Count active entries per account (a char can hold more than one active
        // entry across scopes, so count entries, not characters).
        $activeCountByUser = [];
        if (!empty($charToUserAll)) {
            foreach (WatchlistEntry::active()->whereIn('character_id', array_keys($charToUserAll))->pluck('character_id') as $cid) {
                $u = $charToUserAll[(int) $cid] ?? null;
                if ($u) {
                    $activeCountByUser[(int) $u] = ($activeCountByUser[(int) $u] ?? 0) + 1;
                }
            }
        }

        $result = [];
        foreach ($charIds as $cid) {
            $u = isset($charToUser[$cid]) ? (int) $charToUser[$cid] : null;
            $result[$cid] = $u ? ($activeCountByUser[$u] ?? 1) : 1;
        }
        return $result;
    }

    /**
     * Group a collection of watchlist entries by human account (via the shared
     * refresh_tokens owner), resolving each account's main character for the
     * group header. Unauthed characters have no account, so each becomes its own
     * one-entry group. Groups are ordered by entry count (busiest account first).
     *
     * @return array<int,array{user_id:?int, main_character_id:int, main_name:string, entries:array}>
     */
    private function groupEntriesByAccount($entries): array
    {
        $charIds = $entries->pluck('character_id')->map(fn ($c) => (int) $c)->unique()->all();
        $userByChar = !empty($charIds)
            ? DB::table('refresh_tokens')->whereIn('character_id', $charIds)
                ->whereNull('deleted_at')->pluck('user_id', 'character_id')->toArray()
            : [];

        $byUser = [];
        $unauthed = [];
        foreach ($entries as $e) {
            $uid = $userByChar[$e->character_id] ?? null;
            if ($uid) {
                $byUser[(int) $uid][] = $e;
            } else {
                $unauthed[] = [
                    'user_id'           => null,
                    'main_character_id' => (int) $e->character_id,
                    'main_name'         => $e->display_name,
                    'entries'           => [$e],
                ];
            }
        }

        $mainIdByUser = !empty($byUser)
            ? DB::table('users')->whereIn('id', array_keys($byUser))->pluck('main_character_id', 'id')->toArray()
            : [];
        $mainIds = array_values(array_filter(array_map('intval', $mainIdByUser)));
        $mainNames = !empty($mainIds)
            ? app(NameResolutionService::class)->getCharacterNamesWithFallback($mainIds)
            : [];

        $authed = [];
        foreach ($byUser as $uid => $es) {
            $mainId = isset($mainIdByUser[$uid]) ? (int) $mainIdByUser[$uid] : (int) $es[0]->character_id;
            $authed[] = [
                'user_id'           => (int) $uid,
                'main_character_id' => $mainId,
                'main_name'         => $mainNames[$mainId] ?? $es[0]->display_name,
                'entries'           => $es,
            ];
        }

        $groups = array_merge($authed, $unauthed);
        usort($groups, fn ($a, $b) => count($b['entries']) <=> count($a['entries']));

        return $groups;
    }

    /**
     * Character dossier — everything HR holds on ONE character in one place:
     * the full (untruncated) watchlist reasons, every intel note the viewer is
     * allowed to see, and (director-only) the history timeline. Recruiter-gated
     * like the rest of the watchlist.
     *
     * Visibility is honoured section-by-section: watchlist entries are filtered
     * to the viewer's scope (same rule as the index), the intel section is fed
     * by IntelService which self-filters to what the viewer may see (so it is
     * simply empty — and hidden — for a recruiter without intel access), and the
     * history timeline is director-only. Nothing hidden for recruiters leaks.
     */
    public function dossier(int $characterId, IntelService $intel)
    {
        $allowedCorps = $this->getAllowedCorpIds();
        $user         = auth()->user();
        $isDirector   = $user->can('hr-manager.director') || $user->can('hr-manager.admin');
        $viewerTier   = $user->can('hr-manager.admin') ? 'admin'
            : ($user->can('hr-manager.director') ? 'director' : 'recruiter');

        // Resolve the WHOLE applying account (main + alts) so the dossier lists
        // every flagged character on the account, not just the clicked one — a
        // spy's account typically has several blacklisted characters.
        $accountCharIds = $this->accountCharacterIds($characterId);

        // Watchlist entries (active + cleared) across the account, scope-filtered
        // like the index.
        $wlQuery = WatchlistEntry::with('addedByUser')
            ->whereIn('character_id', $accountCharIds)
            ->orderByDesc('added_at');
        $this->applyScopeVisibility($wlQuery, $allowedCorps);
        $entries = $wlQuery->get();

        // Intel notes across the account — visibility-respected by the service.
        $notes = $intel->notesForCharacters($accountCharIds, (int) $user->id, $allowedCorps, $viewerTier);

        // History — director-only, across the account.
        $history = collect();
        if ($isDirector && Schema::hasTable('hr_manager_member_history_events')) {
            $history = MemberHistoryEvent::whereIn('character_id', $accountCharIds)
                ->orderByDesc('occurred_at')
                ->limit(50)
                ->get();
        }

        // Nothing visible and not a contributor → 404 (mirrors intel show).
        if ($entries->isEmpty() && $notes->isEmpty() && $history->isEmpty() && !$intel->canContribute()) {
            abort(404, 'No dossier for that character that you can see.');
        }

        $resolver    = app(NameResolutionService::class);
        $displayName = $entries->first()->character_name
            ?? $notes->first()?->character_name
            ?? $resolver->getCharacterName($characterId)
            ?? ('Character #' . $characterId);

        $corpIds = $entries->pluck('scope_corporation_id')
            ->merge($notes->pluck('scope_corporation_id'))
            ->filter()->unique()->all();
        $corpNames = !empty($corpIds)
            ? CorporationInfo::whereIn('corporation_id', $corpIds)->pluck('name', 'corporation_id')->toArray()
            : [];

        $actorIds = $history->pluck('actor_user_id')->filter()->map(fn ($i) => (int) $i)->unique()->all();
        $actorNames = !empty($actorIds) ? $resolver->getUserNames($actorIds) : [];

        // Names for every character across the account's entries + notes, so each
        // row is labelled with its character (the dossier is now account-wide).
        $entryCharIds = $entries->pluck('character_id')
            ->merge($notes->pluck('character_id'))
            ->filter()->map(fn ($c) => (int) $c)->unique()->all();
        $charNames = !empty($entryCharIds)
            ? $resolver->getCharacterNamesWithFallback($entryCharIds)
            : [];

        // Alt links touching this human, either end: what's been claimed as
        // their alt, and what they've been claimed as an alt OF. Plus the
        // coverage gap — characters on this account nobody has listed.
        $altLinks = collect();
        $altCoverage = ['listed' => [], 'uncovered' => []];
        if (Schema::hasTable('hr_manager_suspected_alt_links')) {
            $altLinks = \HrManager\Models\SuspectedAltLink::whereIn('suspected_character_id', $accountCharIds)
                ->orWhereIn('main_character_id', $accountCharIds)
                ->orderByDesc('asserted_at')
                ->get();
            try {
                $altCoverage = app(\HrManager\Services\SuspectedAltService::class)->coverageGap($characterId);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[HR Manager] dossier coverage gap failed: ' . $e->getMessage());
            }
        }

        return view('hr-manager::watchlist.dossier', compact(
            'altLinks',
            'altCoverage',
            'characterId',
            'displayName',
            'entries',
            'notes',
            'history',
            'corpNames',
            'charNames',
            'actorNames',
            'isDirector',
            'viewerTier'
        ));
    }

    /** Every character id on the account that owns $characterId (main + alts). */
    private function accountCharacterIds(int $characterId): array
    {
        // Identity-aware: follows HR's player identity as well as the SeAT
        // account, so a human who made two SeAT accounts (and had them merged
        // by a director) reads as ONE dossier instead of the account SeAT
        // happens to hold on whichever character was clicked.
        $siblings = app(\HrManager\Services\AccountCharacterResolver::class)->siblingsFor($characterId);
        if (!empty($siblings)) {
            return array_values(array_unique(array_map(
                fn ($s) => (int) $s['character_id'],
                $siblings
            )));
        }

        // Unknown to both -> just the character that was clicked.
        return [(int) $characterId];
    }

    /**
     * Apply the same scope-visibility filter the index uses: global entries
     * always, plus entries scoped to a corp/alliance the viewer can access.
     * $allowedCorps === null means admin (see everything).
     */
    private function applyScopeVisibility($query, ?array $allowedCorps): void
    {
        if ($allowedCorps === null) {
            return;
        }

        $allowedAlliances = !empty($allowedCorps) && Schema::hasTable('corporation_infos')
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

    public function store(Request $request, WatchlistService $service)
    {
        if (!auth()->user()->can('hr-manager.director')) {
            abort(403, 'Director permission required to add watchlist entries.');
        }

        $request->validate([
            'list_type'                  => 'required|in:blacklist,whitelist',
            'input'                      => 'required|string|min:1|max:64',
            'suspected_alts'             => 'nullable|string|max:4000',
            'scope_corporation_id'       => 'nullable|integer',
            'scope_alliance_id'          => 'nullable|integer',
            'reason'                     => 'nullable|string|max:2000',
            'severity'                   => 'nullable|in:low,medium,high',
            'expires_at'                 => 'nullable|date|after:today',
            'notify_on_corp_match'       => 'nullable|boolean',
            'notify_on_alliance_match'   => 'nullable|boolean',
            'notify_on_external_change'  => 'nullable|boolean',
            'include_alts'               => 'nullable|boolean',
        ]);

        $scopeCorp = $request->filled('scope_corporation_id')
            ? (int) $request->scope_corporation_id
            : null;
        $scopeAlliance = $request->filled('scope_alliance_id')
            ? (int) $request->scope_alliance_id
            : null;
        if ($scopeCorp !== null) {
            $this->assertCanAccessCorp($scopeCorp);
        }

        // The main is one character; the possible-alts box is a separate list.
        // Keeping them apart is what lets HR record each alt as a CLAIM against
        // a named main — a claim the reconciler can later confirm or refute —
        // rather than as an anonymous batch of unrelated entries.
        $altTokens = $this->parseCharacterList((string) $request->input('suspected_alts', ''));

        $addEntry = function (string $token) use ($service, $request, $scopeCorp) {
            return $service->addEntry(
                $request->list_type,
                (int) auth()->user()->id,
                $token,
                $scopeCorp,
                $request->reason,
                $request->input('severity', WatchlistEntry::SEVERITY_MEDIUM),
                $request->filled('expires_at') ? \Carbon\Carbon::parse($request->expires_at) : null
            );
        };

        $result = $addEntry((string) $request->input('input'));

        if (!$result['success']) {
            return redirect()->back()->with('error', trans('hr-manager::watchlist.add_failed_' . ($result['reason'] ?? 'unknown')))->withInput();
        }

        // Patch the created entry with the alliance scope + policy
        // flags that addEntry() doesn't know about yet.
        $entry = $result['entry'];

        // Each possible alt gets its own entry carrying the same settings, PLUS
        // a suspected-alt link naming the main. Collected rather than aborted on
        // failure — one fat-fingered name shouldn't discard the rest.
        $extraAdded  = [];
        $extraFailed = [];
        $linksMade   = 0;
        $altService  = app(\HrManager\Services\SuspectedAltService::class);

        foreach ($altTokens as $token) {
            $r = $addEntry($token);
            if (empty($r['success']) || empty($r['entry'])) {
                $extraFailed[] = $token;
                continue;
            }

            $altEntry = $r['entry'];
            $altEntry->update([
                'scope_alliance_id'         => $scopeAlliance,
                'notify_on_corp_match'      => (bool) $request->input('notify_on_corp_match', true),
                'notify_on_alliance_match'  => (bool) $request->input('notify_on_alliance_match', $scopeAlliance !== null),
                'notify_on_external_change' => (bool) $request->input('notify_on_external_change', false),
            ]);

            $link = $altService->record(
                (int) $altEntry->character_id,
                (int) $entry->character_id,
                $altEntry->character_name,
                $entry->character_name,
                \HrManager\Models\SuspectedAltLink::SOURCE_WATCHLIST,
                (int) $altEntry->id,
                (int) auth()->user()->id
            );
            if ($link) {
                $linksMade++;
            }

            $extraAdded[] = $altEntry->character_name ?: ('#' . $altEntry->character_id);
        }
        $entry->update([
            'scope_alliance_id'          => $scopeAlliance,
            'notify_on_corp_match'       => (bool) $request->input('notify_on_corp_match', true),
            'notify_on_alliance_match'   => (bool) $request->input('notify_on_alliance_match', $scopeAlliance !== null),
            'notify_on_external_change'  => (bool) $request->input('notify_on_external_change', false),
        ]);

        // Immediate scope check: if this character is ALREADY inside a corp or
        // alliance the entry watches, tell the operator now instead of waiting
        // for the next cron tick. Best-effort and idempotent (same dedup as the
        // periodic scan) so it never blocks the save or double-notifies.
        $immediateHit = false;
        try {
            $immediateHit = $entry->fresh()
                && app(\HrManager\Services\WatchlistMonitorService::class)->checkEntryNow($entry->fresh()) > 0;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR] watchlist immediate check failed: ' . $e->getMessage());
        }

        // Optional bulk-add: extend the same entry to every OTHER character
        // proven to be the same human (shared SeAT account or HR identity).
        // Same reason / severity / scope / policy flags, so one spy is listed
        // once as a person rather than once per alt the director remembered.
        // Only ever the same human — never a group.
        $alsoAdded = [];
        if ($request->boolean('include_alts')) {
            $alsoAdded = $this->addSiblingEntries($service, $entry, $request, $scopeCorp, $scopeAlliance);
        }

        // Audit trail (security): who added whom to the blacklist / whitelist,
        // with the severity + reason. Names the character so the log reads
        // "Added X to blacklist" rather than an id.
        $charName = $entry->character_name ?: ('#' . $entry->character_id);
        app(AuditService::class)->action('watchlist.add', AuditService::CAT_SECURITY, [
            'target_type'    => 'dossier',
            'target_id'      => (int) $entry->character_id ?: null,
            'target_label'   => $charName,
            'corporation_id' => $scopeCorp,
            'summary'        => 'Added ' . $charName . ' to ' . $request->list_type
                . (!empty($alsoAdded) ? ' (+' . count($alsoAdded) . ' alts of the same account)' : ''),
            'context'        => [
                'list_type' => $request->list_type,
                'severity'  => $request->input('severity', WatchlistEntry::SEVERITY_MEDIUM),
                'reason'    => $request->reason ? mb_substr((string) $request->reason, 0, 200) : null,
                'alts_added' => $alsoAdded ?: null,
            ],
        ]);

        $flash = trans('hr-manager::watchlist.entry_added' . ($immediateHit ? '_with_hit' : ''));
        if (!empty($extraAdded)) {
            $flash .= ' ' . trans('hr-manager::watchlist.extra_added', [
                'count' => count($extraAdded),
                'names' => implode(', ', $extraAdded),
            ]);
        }
        if ($linksMade > 0) {
            $flash .= ' ' . trans('hr-manager::watchlist.alt_links_added', [
                'count' => $linksMade,
                'main'  => $entry->character_name ?: ('#' . $entry->character_id),
            ]);
        }
        if (!empty($extraFailed)) {
            $flash .= ' ' . trans('hr-manager::watchlist.extra_failed', [
                'names' => implode(', ', $extraFailed),
            ]);
        }
        if (!empty($alsoAdded)) {
            // Name them so the director can see exactly what landed and remove
            // any they didn't want, rather than trusting a bare count.
            $flash .= ' ' . trans('hr-manager::watchlist.alts_also_added', [
                'count' => count($alsoAdded),
                'names' => implode(', ', $alsoAdded),
            ]);
        }

        return redirect()->route('hr-manager.watchlist.index', ['list_type' => $request->list_type])
            ->with('success', $flash);
    }

    /**
     * Split a multi-line character box into individual tokens (names or IDs).
     * Newline-separated, since EVE character names legitimately contain spaces
     * and may contain a comma. Blanks dropped, duplicates collapsed, and the
     * list capped so a pasted wall of text can't fire hundreds of ESI name
     * lookups in one request.
     *
     * @return array<int, string>
     */
    private function parseCharacterList(string $raw): array
    {
        $tokens = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $tokens = array_values(array_unique(array_filter(array_map('trim', $tokens), function ($t) {
            return $t !== '';
        })));

        return array_slice($tokens, 0, 50);
    }

    /**
     * Add the same watchlist entry for every other character on the seed
     * character's account. Returns the names actually added (skipping the seed
     * and anything that failed to resolve), so the caller can report them.
     *
     * @return array<int, string>
     */
    private function addSiblingEntries(
        WatchlistService $service,
        WatchlistEntry $seed,
        Request $request,
        ?int $scopeCorp,
        ?int $scopeAlliance
    ): array {
        $added = [];

        try {
            $siblings = app(\HrManager\Services\AccountCharacterResolver::class)
                ->siblingsFor((int) $seed->character_id);

            foreach ($siblings as $sib) {
                if (!empty($sib['is_seed'])) {
                    continue;
                }

                $res = $service->addEntry(
                    $request->list_type,
                    (int) auth()->user()->id,
                    (string) $sib['character_id'],
                    $scopeCorp,
                    $request->reason,
                    $request->input('severity', WatchlistEntry::SEVERITY_MEDIUM),
                    $request->filled('expires_at') ? \Carbon\Carbon::parse($request->expires_at) : null
                );

                if (empty($res['success']) || empty($res['entry'])) {
                    continue;
                }

                $res['entry']->update([
                    'scope_alliance_id'         => $scopeAlliance,
                    'notify_on_corp_match'      => (bool) $request->input('notify_on_corp_match', true),
                    'notify_on_alliance_match'  => (bool) $request->input('notify_on_alliance_match', $scopeAlliance !== null),
                    'notify_on_external_change' => (bool) $request->input('notify_on_external_change', false),
                ]);

                $added[] = $sib['name'];
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] watchlist alt bulk-add failed: ' . $e->getMessage());
        }

        return $added;
    }

    /**
     * Clear an entry (audit trail kept). Requires a reason so the
     * historical record explains why the blacklist/whitelist was
     * lifted.
     */
    public function destroy(Request $request, int $id, WatchlistService $service)
    {
        if (!auth()->user()->can('hr-manager.director')) {
            abort(403, 'Director permission required to clear watchlist entries.');
        }

        $request->validate([
            'cleared_reason' => 'required|string|min:3|max:2000',
            'clear_scope'    => 'nullable|in:single,account',
        ]);

        $entry = WatchlistEntry::findOrFail($id);
        if ($entry->scope_corporation_id !== null) {
            $this->assertCanAccessCorp($entry->scope_corporation_id);
        }

        $userId   = (int) auth()->user()->id;
        $reason   = $request->input('cleared_reason');
        $charName = $entry->character_name ?: ('#' . $entry->character_id);

        // "Clear all on this account": clear every ACTIVE entry across the
        // character's whole account (main + alts), skipping any entry the
        // director can't access. Each clear records its own history event, so the
        // per-character dossier timelines all keep the story.
        if ($request->input('clear_scope') === 'account') {
            $allowedCorps   = $this->getAllowedCorpIds();
            $accountCharIds = $this->accountCharacterIds($entry->character_id);
            $cleared = 0;
            WatchlistEntry::active()
                ->whereIn('character_id', $accountCharIds)
                ->get()
                ->each(function ($sib) use ($service, $userId, $reason, $allowedCorps, &$cleared) {
                    if ($sib->scope_corporation_id !== null
                        && $allowedCorps !== null
                        && !in_array((int) $sib->scope_corporation_id, $allowedCorps, true)) {
                        return; // out of the director's scope — leave it
                    }
                    if ($service->clearEntry((int) $sib->id, $userId, $reason)) {
                        $cleared++;
                    }
                });

            app(AuditService::class)->action('watchlist.clear', AuditService::CAT_SECURITY, [
                'target_type'    => 'dossier',
                'target_id'      => (int) $entry->character_id ?: null,
                'target_label'   => $charName,
                'corporation_id' => $entry->scope_corporation_id,
                'summary'        => 'Cleared ' . $cleared . ' watchlist entr' . ($cleared === 1 ? 'y' : 'ies') . " across {$charName}'s account",
                'context'        => ['scope' => 'account', 'count' => $cleared, 'reason' => mb_substr((string) $reason, 0, 200)],
            ]);

            return redirect()->route('hr-manager.watchlist.index', ['list_type' => $entry->list_type])
                ->with('success', trans('hr-manager::watchlist.entry_cleared_account', ['count' => $cleared]));
        }

        // Single entry (default).
        $service->clearEntry((int) $id, $userId, $reason);

        // Audit trail (security): who cleared whom from the watchlist, and why.
        // Uses the entry snapshot captured before the clear.
        app(AuditService::class)->action('watchlist.clear', AuditService::CAT_SECURITY, [
            'target_type'    => 'dossier',
            'target_id'      => (int) $entry->character_id ?: null,
            'target_label'   => $charName,
            'corporation_id' => $entry->scope_corporation_id,
            'summary'        => 'Cleared ' . $charName . ' from ' . $entry->list_type . ' watchlist',
            'context'        => [
                'list_type' => $entry->list_type,
                'reason'    => mb_substr((string) $reason, 0, 200),
            ],
        ]);

        return redirect()->route('hr-manager.watchlist.index', ['list_type' => $entry->list_type])
            ->with('success', trans('hr-manager::watchlist.entry_cleared'));
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

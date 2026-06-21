<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\WatchlistEntry;
use HrManager\Services\WatchlistService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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

        $entries = $query->paginate(50);

        // Bulk-resolve corp names for the displayed entries.
        $corpIds = $entries->pluck('scope_corporation_id')->filter()->unique()->all();
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

        return view('hr-manager::watchlist.index', compact(
            'entries',
            'listType',
            'corpNames',
            'blacklistCount',
            'whitelistCount',
            'corporations'
        ));
    }

    public function store(Request $request, WatchlistService $service)
    {
        if (!auth()->user()->can('hr-manager.director')) {
            abort(403, 'Director permission required to add watchlist entries.');
        }

        $request->validate([
            'list_type'                  => 'required|in:blacklist,whitelist',
            'input'                      => 'required|string|min:1|max:64',
            'scope_corporation_id'       => 'nullable|integer',
            'scope_alliance_id'          => 'nullable|integer',
            'reason'                     => 'nullable|string|max:2000',
            'severity'                   => 'nullable|in:low,medium,high',
            'expires_at'                 => 'nullable|date|after:today',
            'notify_on_corp_match'       => 'nullable|boolean',
            'notify_on_alliance_match'   => 'nullable|boolean',
            'notify_on_external_change'  => 'nullable|boolean',
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

        $result = $service->addEntry(
            $request->list_type,
            (int) auth()->user()->id,
            $request->input('input'),
            $scopeCorp,
            $request->reason,
            $request->input('severity', WatchlistEntry::SEVERITY_MEDIUM),
            $request->filled('expires_at') ? \Carbon\Carbon::parse($request->expires_at) : null
        );

        if (!$result['success']) {
            return redirect()->back()->with('error', trans('hr-manager::watchlist.add_failed_' . ($result['reason'] ?? 'unknown')))->withInput();
        }

        // Patch the created entry with the alliance scope + policy
        // flags that addEntry() doesn't know about yet.
        $entry = $result['entry'];
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

        return redirect()->route('hr-manager.watchlist.index', ['list_type' => $request->list_type])
            ->with('success', trans('hr-manager::watchlist.entry_added' . ($immediateHit ? '_with_hit' : '')));
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
        ]);

        $entry = WatchlistEntry::findOrFail($id);
        if ($entry->scope_corporation_id !== null) {
            $this->assertCanAccessCorp($entry->scope_corporation_id);
        }

        $service->clearEntry(
            (int) $id,
            (int) auth()->user()->id,
            $request->input('cleared_reason')
        );

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

<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\PlayerClassification;
use HrManager\Services\ClassifierService;
use HrManager\Services\CorpStatusService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class CorpHealthController extends Controller
{
    use ScopesCorporationAccess;

    public function index(Request $request)
    {
        $allowedCorps = $this->getAllowedCorpIds();
        $corporationId = $this->resolveCorporationContext($request, $allowedCorps);
        $this->assertCanAccessCorp($corporationId);

        $classifications = PlayerClassification::forCorporation($corporationId)->get();

        $byCategory = [
            'active'      => $classifications->where('category', PlayerClassification::CATEGORY_ACTIVE)->count(),
            'at_risk'     => $classifications->where('category', PlayerClassification::CATEGORY_AT_RISK)->count(),
            'inactive'    => $classifications->where('category', PlayerClassification::CATEGORY_INACTIVE)->count(),
            'dead_weight' => $classifications->where('category', PlayerClassification::CATEGORY_DEAD_WEIGHT)->count(),
        ];

        $atRiskOrWorse = $classifications->whereIn('category', [
            PlayerClassification::CATEGORY_AT_RISK,
            PlayerClassification::CATEGORY_INACTIVE,
            PlayerClassification::CATEGORY_DEAD_WEIGHT,
        ])->sortByDesc('days_inactive')->take(50)->values();

        // Wallet signals: count the CWM signal events HR recorded in its
        // history over the last 30 days, across ALL corp members (registered
        // or not). The classifier wallet_flags only attach to authed players,
        // so an unregistered member who triggered a CWM event (CWM reads
        // corp-wide wallet data via the director token) would otherwise be
        // invisible here. Counts DISTINCT members affected per signal type.
        $walletSignals = [
            'stalled'            => 0,
            'contribution_drop'  => 0,
            'compliance_dropped' => 0,
            'unusual_recipient'  => 0,
            'milestone'          => 0,
        ];
        if (\Illuminate\Support\Facades\Schema::hasTable('hr_manager_member_history_events')) {
            $signalCounts = DB::table('hr_manager_member_history_events')
                ->where('corporation_id', $corporationId)
                ->where('occurred_at', '>=', now()->subDays(30))
                ->whereIn('event_type', [
                    'wallet_stalled', 'wallet_contribution_drop',
                    'wallet_compliance_dropped', 'wallet_unusual_recipient',
                    'wallet_milestone',
                ])
                ->selectRaw('event_type, COUNT(DISTINCT character_id) as c')
                ->groupBy('event_type')
                ->pluck('c', 'event_type')
                ->toArray();
            $walletSignals = [
                'stalled'            => (int) ($signalCounts['wallet_stalled'] ?? 0),
                'contribution_drop'  => (int) ($signalCounts['wallet_contribution_drop'] ?? 0),
                'compliance_dropped' => (int) ($signalCounts['wallet_compliance_dropped'] ?? 0),
                'unusual_recipient'  => (int) ($signalCounts['wallet_unusual_recipient'] ?? 0),
                'milestone'          => (int) ($signalCounts['wallet_milestone'] ?? 0),
            ];
        }

        $coherence = $this->personnelManagerCoherence($corporationId);

        $corporations = $this->corporationPickerOptions($allowedCorps);
        $latestClassifiedAt = $classifications->max('classified_at');

        // Tabbed Corp Health. Only the active tab's sections build (lazy)
        // — opening Economy doesn't pay to build Overview and vice-versa.
        // Default lands on overview. Economy is director-gated; a
        // recruiter hitting ?ch_tab=economy is bounced to overview.
        $activeTab = $request->input('ch_tab', 'overview');
        if (!in_array($activeTab, CorpStatusService::TABS, true)) {
            $activeTab = 'overview';
        }
        if (in_array($activeTab, ['economy', 'purge', 'structure-compliance', 'membership'], true) && !auth()->user()->can('hr-manager.director')) {
            $activeTab = 'overview';
        }

        $corpStatus = app(CorpStatusService::class)
            ->getCorporationStatus($corporationId, $activeTab);

        // Roster-based director health. Covers UNAUTHED directors too: the
        // classifier's is_inactive_director flag only exists for registered
        // users, so a director who never authed into SeAT is invisible to it.
        $directorHealth = app(CorpStatusService::class)->getDirectorHealth($corporationId);

        // Corp-wide activity (ALL members by last login), so the dashboard
        // reflects the whole corp, not just the registered pilots the
        // classifier covers.
        $rosterActivity = app(CorpStatusService::class)->getRosterActivity($corporationId);

        // Blueprint engagement rollup (optional — Blueprint Manager via MC).
        // Lazy: only built on the Economy tab where the card lives.
        $blueprintCorpSummary = ($activeTab === 'economy')
            ? app(\HrManager\Services\BlueprintActivityService::class)->getCorpSummary($corporationId)
            : ['available' => false];

        // Buyback contribution rollup (optional — Buyback Manager via MC).
        // Contributions CREDITED to this corp (honouring alt/holding-corp
        // attribution) + the top contributors. Lazy: Economy tab only.
        $buybackCorp = ($activeTab === 'economy')
            ? app(\HrManager\Services\BuybackContributionService::class)->forCorporation($corporationId)
            : ['available' => false];

        // Membership change log + no-application review queue (director-only
        // tab). The full log is lazy (Membership tab only); the unreviewed
        // count is always computed for directors so the nav badge shows from
        // any tab.
        $membershipSvc        = app(\HrManager\Services\MembershipChangeService::class);
        $isDirector           = auth()->user()->can('hr-manager.director');
        $membershipReviewCount = $isDirector ? $membershipSvc->reviewCount($corporationId) : 0;
        $membership = ($activeTab === 'membership')
            ? $membershipSvc->corporationLog($corporationId)
            : ['available' => false];

        // Structure doctrine compliance (lazy — only on its own tab). Sourced
        // from Structure Manager via Manager Core's PluginBridge: SM owns the
        // feature, HR consumes it. Returns a 'sm_absent' marker (rendered as a
        // "Structure Manager required" notice) when SM / MC isn't installed.
        $structureCompliance = ($activeTab === 'structure-compliance')
            ? app(\HrManager\Services\CrossPluginDataService::class)->getStructureCompliance($corporationId)
            : ['available' => false];

        return view('hr-manager::corp-health.index', compact(
            'byCategory',
            'directorHealth',
            'rosterActivity',
            'atRiskOrWorse',
            'walletSignals',
            'coherence',
            'corpStatus',
            'corporationId',
            'corporations',
            'latestClassifiedAt',
            'activeTab',
            'blueprintCorpSummary',
            'buybackCorp',
            'membership',
            'membershipReviewCount',
            'structureCompliance'
        ));
    }

    public function runNow(Request $request, ClassifierService $classifier)
    {
        $allowedCorps = $this->getAllowedCorpIds();
        $corporationId = (int) $request->input('corporation_id');
        $this->assertCanAccessCorp($corporationId);

        $counts = $classifier->classifyCorporation($corporationId);

        return redirect()->route('hr-manager.corp-health.index', ['corporation_id' => $corporationId])
            ->with('success', sprintf(
                'Classification rerun. active=%d at_risk=%d inactive=%d dead_weight=%d inactive_directors=%d',
                $counts['active'], $counts['at_risk'], $counts['inactive'],
                $counts['dead_weight'], $counts['inactive_directors']
            ));
    }

    /**
     * Acknowledge a "joined without a valid application" flag from the
     * Membership tab review queue. Director-gated + corp-scoped.
     */
    public function reviewMembershipEvent(Request $request, int $id)
    {
        if (!auth()->user()->can('hr-manager.director')) {
            abort(403);
        }

        $request->validate(['review_note' => 'nullable|string|max:500']);

        $event = \HrManager\Models\MembershipEvent::findOrFail($id);
        $this->assertCanAccessCorp($event->corporation_id);

        $event->update([
            'reviewed_at' => now(),
            'reviewed_by' => auth()->user()->id,
            'review_note' => $request->input('review_note'),
        ]);

        return redirect()
            ->route('hr-manager.corp-health.index', ['corporation_id' => $event->corporation_id, 'ch_tab' => 'membership'])
            ->with('success', trans('hr-manager::corp-health.mem_reviewed'));
    }

    /**
     * Tick/untick a manual purge step (in-game roles removed) on the
     * Purge board. Director-gated + corp-scoped; busts the Corp Health cache
     * so the board reflects the change immediately.
     */
    public function purgeStep(Request $request, $id, \HrManager\Services\PurgeBoardService $board)
    {
        $status = \HrManager\Models\PlayerStatus::find((int) $id);
        if (!$status) {
            abort(404);
        }
        $this->assertCanAccessCorp((int) $status->corporation_id);
        if (!auth()->user()->can('hr-manager.director')) {
            abort(403);
        }

        $board->markStep((int) $id, (string) $request->input('step'), $request->boolean('done'));
        app(CorpStatusService::class)->bustCache((int) $status->corporation_id);

        return redirect()
            ->route('hr-manager.corp-health.index', ['corporation_id' => $status->corporation_id, 'ch_tab' => 'purge'])
            ->with('success', trans('hr-manager::corp-health.purge_step_saved'));
    }

    /**
     * Save the free-text progress note on a Purge board entry. Director-gated.
     */
    public function purgeNote(Request $request, $id, \HrManager\Services\PurgeBoardService $board)
    {
        $status = \HrManager\Models\PlayerStatus::find((int) $id);
        if (!$status) {
            abort(404);
        }
        $this->assertCanAccessCorp((int) $status->corporation_id);
        if (!auth()->user()->can('hr-manager.director')) {
            abort(403);
        }

        $board->updateNote((int) $id, $request->input('note'));
        app(CorpStatusService::class)->bustCache((int) $status->corporation_id);

        return redirect()
            ->route('hr-manager.corp-health.index', ['corporation_id' => $status->corporation_id, 'ch_tab' => 'purge'])
            ->with('success', trans('hr-manager::corp-health.purge_note_saved'));
    }

    /**
     * Manually clear a purge-scheduled player's removable SeAT squads from the
     * board (manual/hidden, minus the operator's excluded list; auto squads are
     * never touched). Director-gated. Stamps the same purge_squads_removed_at
     * marker the auto cleanup uses, so the auto pass will not re-fire.
     */
    public function purgeRemoveSquads(Request $request, $id, \HrManager\Services\PurgeService $purge)
    {
        $status = \HrManager\Models\PlayerStatus::find((int) $id);
        if (!$status) {
            abort(404);
        }
        $this->assertCanAccessCorp((int) $status->corporation_id);
        if (!auth()->user()->can('hr-manager.director')) {
            abort(403);
        }

        $removed = $purge->removeSquadsForPurge($status, 'purge_manual');
        app(CorpStatusService::class)->bustCache((int) $status->corporation_id);

        $message = empty($removed)
            ? trans('hr-manager::corp-health.purge_squads_none')
            : trans('hr-manager::corp-health.purge_squads_removed', ['count' => count($removed)]);

        return redirect()
            ->route('hr-manager.corp-health.index', ['corporation_id' => $status->corporation_id, 'ch_tab' => 'purge'])
            ->with(empty($removed) ? 'info' : 'success', $message);
    }

    /**
     * Personnel-Manager coherence check: list users with hr-manager.recruiter
     * (or above) and surface whether any of their characters hold the
     * in-game "Personnel_Manager" corp role. Soft signal so operators can
     * keep SeAT permissions aligned with in-game authority.
     */
    private function personnelManagerCoherence(int $corporationId): array
    {
        // Best-effort lookup of corp characters with Personnel_Manager role
        $charactersWithRole = [];
        try {
            // corporation_roles is the director-token table (covers every
            // member, carries corporation_id). type='roles' = base corp-wide
            // grant, so only members who actually hold Personnel_Manager count.
            if (\Illuminate\Support\Facades\Schema::hasTable('corporation_roles')) {
                $charactersWithRole = DB::table('corporation_roles')
                    ->where('corporation_id', $corporationId)
                    ->where('type', 'roles')
                    ->where('role', 'Personnel_Manager')
                    ->pluck('character_id')
                    ->map(fn($id) => (int) $id)
                    ->all();
            }
        } catch (\Throwable $e) {
            // Schema drift - log and degrade
            \Illuminate\Support\Facades\Log::warning('[HR Manager] coherence query failed: ' . $e->getMessage());
        }

        // Users with at least one character in this corp
        $usersInCorp = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('character_affiliations.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->select(['refresh_tokens.user_id', 'character_affiliations.character_id'])
            ->get();

        // Map: user_id => [character_ids]
        $userChars = [];
        foreach ($usersInCorp as $row) {
            $userChars[(int) $row->user_id][] = (int) $row->character_id;
        }

        $hasRole = [];
        $missingRole = [];
        foreach ($userChars as $userId => $charIds) {
            $overlap = array_intersect($charIds, $charactersWithRole);
            if (!empty($overlap)) {
                $hasRole[] = $userId;
            } else {
                $missingRole[] = $userId;
            }
        }

        return [
            'total_in_corp_users'         => count($userChars),
            'has_personnel_manager'       => count($hasRole),
            'missing_personnel_manager'   => count($missingRole),
            'coverage_pct'                => count($userChars) > 0
                ? round(count($hasRole) / count($userChars) * 100, 1)
                : 0,
            'sample_missing_user_ids'     => array_slice($missingRole, 0, 20),
        ];
    }

    private function resolveCorporationContext(Request $request, ?array $allowedCorps): int
    {
        if ($request->filled('corporation_id')) {
            $requested = (int) $request->corporation_id;
            if ($allowedCorps === null || in_array($requested, $allowedCorps)) {
                return $requested;
            }
            abort(403, 'You do not have access to that corporation.');
        }

        // Land on the viewer's own corp first when no explicit corp_id —
        // friendlier than alphabetical-first.
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
}

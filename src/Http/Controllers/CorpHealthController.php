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

        // Resolve each classifier row's user_id to the account's MAIN character
        // (name + portrait) so the table reads as people, not "User #id".
        $classifierUserIds = $atRiskOrWorse->pluck('user_id')->map(fn ($i) => (int) $i)->filter()->unique()->values()->all();
        $classifierNames = !empty($classifierUserIds)
            ? app(\HrManager\Services\NameResolutionService::class)->getUserNames($classifierUserIds)
            : [];
        $classifierMains = !empty($classifierUserIds) && \Illuminate\Support\Facades\Schema::hasTable('users')
            ? DB::table('users')->whereIn('id', $classifierUserIds)->pluck('main_character_id', 'id')->toArray()
            : [];

        // Each classifier account's characters (main + alts), for the expandable
        // per-row breakdown. The classifier is already per-player; this just lets
        // a director see WHICH characters make up the human behind the row.
        $classifierAlts = [];
        if (!empty($classifierUserIds) && \Illuminate\Support\Facades\Schema::hasTable('refresh_tokens')) {
            $altRows = DB::table('refresh_tokens')
                ->whereIn('user_id', $classifierUserIds)
                ->whereNull('deleted_at')
                ->select('user_id', 'character_id')
                ->get();
            $altCharIds = $altRows->pluck('character_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
            $altNames = !empty($altCharIds)
                ? app(\HrManager\Services\NameResolutionService::class)->getCharacterNamesWithFallback($altCharIds)
                : [];
            foreach ($altRows as $r) {
                $uid = (int) $r->user_id;
                $cid = (int) $r->character_id;
                $classifierAlts[$uid][] = [
                    'character_id' => $cid,
                    'name'         => $altNames[$cid] ?? ('#' . $cid),
                    'is_main'      => isset($classifierMains[$uid]) && (int) $classifierMains[$uid] === $cid,
                ];
            }
            // Main first within each account, then alphabetical.
            foreach ($classifierAlts as &$list) {
                usort($list, fn ($a, $b) => ($b['is_main'] <=> $a['is_main']) ?: strcmp($a['name'], $b['name']));
            }
            unset($list);
        }

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
        $accessMap = $this->buildAccessMap($corporationId);

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
        if (in_array($activeTab, ['economy', 'purge', 'structure-compliance', 'membership', 'alignment', 'access'], true) && !auth()->user()->can('hr-manager.director')) {
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

        // Optional title/role alignment (lazy — heavy per-character reads).
        $roleAlignSvc = app(\HrManager\Services\RoleAlignmentService::class);
        $roleAlignEnabled = $roleAlignSvc->isEnabled();
        $roleAlignment = ($roleAlignEnabled && $activeTab === 'alignment')
            ? $roleAlignSvc->forCorporation($corporationId)
            : ['available' => false];

        // Access tab (director-only, lazy): corp-wide SeAT + Discord access
        // depth with dormant-with-power cross-referencing. Batched reads.
        $corpAccess = ($activeTab === 'access')
            ? app(\HrManager\Services\CorpAccessService::class)->forCorporation($corporationId)
            : null;

        return view('hr-manager::corp-health.index', compact(
            'byCategory',
            'directorHealth',
            'rosterActivity',
            'atRiskOrWorse',
            'classifierNames',
            'classifierMains',
            'classifierAlts',
            'walletSignals',
            'coherence',
            'accessMap',
            'corpStatus',
            'corporationId',
            'corporations',
            'latestClassifiedAt',
            'activeTab',
            'blueprintCorpSummary',
            'buybackCorp',
            'membership',
            'membershipReviewCount',
            'structureCompliance',
            'roleAlignEnabled',
            'roleAlignment',
            'corpAccess'
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

    /**
     * "Who can do what" access map for the corp: everyone with HR recruitment
     * authority (recruiter / Personnel Manager / director / admin) or the in-game
     * Personnel Manager / Director role, cross-referenced so mismatches surface —
     * HR accept authority without the in-game role (can decide in HR but can't
     * complete the invite), or the in-game role without an HR permission (accepts
     * bypass the HR workflow). Explicit grants only; SeAT superusers implicitly
     * override everything and are not the recruitment org being mapped.
     */
    private function buildAccessMap(int $corporationId): array
    {
        // In-game Personnel Manager / Director role holders in this corp -> users.
        $ingameByUser = [];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('corporation_roles')) {
                $roleRows = DB::table('corporation_roles')
                    ->join('refresh_tokens', function ($j) {
                        $j->on('refresh_tokens.character_id', '=', 'corporation_roles.character_id')
                          ->whereNull('refresh_tokens.deleted_at');
                    })
                    ->where('corporation_roles.corporation_id', $corporationId)
                    ->where('corporation_roles.type', 'roles')
                    ->whereIn('corporation_roles.role', ['Personnel_Manager', 'Director'])
                    ->select(['refresh_tokens.user_id', 'corporation_roles.role', 'corporation_roles.character_id'])
                    ->get();
                foreach ($roleRows as $r) {
                    $uid   = (int) $r->user_id;
                    $cid   = (int) $r->character_id;
                    $isPm  = $r->role === 'Personnel_Manager';
                    $isDir = $r->role === 'Director';
                    $ingameByUser[$uid]['pm']       = ($ingameByUser[$uid]['pm'] ?? false) || $isPm;
                    $ingameByUser[$uid]['director'] = ($ingameByUser[$uid]['director'] ?? false) || $isDir;
                    // Track which character(s) actually hold the role so the map
                    // can show "PM via <alt>" — the human's recruitment authority
                    // frequently sits on an alt, not the main.
                    $ingameByUser[$uid]['chars'][$cid]['pm']       = ($ingameByUser[$uid]['chars'][$cid]['pm'] ?? false) || $isPm;
                    $ingameByUser[$uid]['chars'][$cid]['director'] = ($ingameByUser[$uid]['chars'][$cid]['director'] ?? false) || $isDir;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] access-map in-game role query failed: ' . $e->getMessage());
        }

        // HR permission grants (explicit, via SeAT's ACL) -> users.
        $hrRecruiter = $this->hrPermissionUserIds('hr-manager.recruiter');
        $hrPersonnel = $this->hrPermissionUserIds('hr-manager.personnel');
        $hrDirector  = $this->hrPermissionUserIds('hr-manager.director');
        $hrAdmin     = $this->hrPermissionUserIds('hr-manager.admin');
        $hrDecide    = array_values(array_unique(array_merge($hrPersonnel, $hrDirector, $hrAdmin)));

        // Corp scope: only users with a character in THIS corp.
        $corpUserIds = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('character_affiliations.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('refresh_tokens.user_id')->map(fn ($i) => (int) $i)->unique()->all();

        // Relevant = anyone in this corp with HR recruitment authority OR an
        // in-game recruitment role (the in-game holders are already corp-scoped).
        $relevant = array_values(array_unique(array_merge(
            array_intersect($hrRecruiter, $corpUserIds),
            array_intersect($hrDecide, $corpUserIds),
            array_keys($ingameByUser)
        )));

        if (empty($relevant)) {
            return ['rows' => [], 'mismatch_count' => 0];
        }

        $mainByUser = DB::table('users')->whereIn('id', $relevant)->pluck('main_character_id', 'id')->toArray();
        $mainIds = array_values(array_filter(array_map('intval', $mainByUser)));

        // Gather every in-game role-holding character so their names resolve in
        // the same bulk pass as the mains (the "via <alt>" pills need them).
        $ingameCharIds = [];
        foreach ($ingameByUser as $entry) {
            foreach (array_keys($entry['chars'] ?? []) as $cid) {
                $ingameCharIds[] = (int) $cid;
            }
        }
        $nameIds = array_values(array_unique(array_merge($mainIds, $ingameCharIds)));
        $names = !empty($nameIds)
            ? app(\HrManager\Services\NameResolutionService::class)->getCharacterNamesWithFallback($nameIds)
            : [];

        $rows = [];
        foreach ($relevant as $uid) {
            $canDecideHr     = in_array($uid, $hrDecide, true);
            $isRecruiterHr   = $canDecideHr || in_array($uid, $hrRecruiter, true);
            $hasPm           = $ingameByUser[$uid]['pm'] ?? false;
            $hasDir          = $ingameByUser[$uid]['director'] ?? false;
            $hasIngameDecide = $hasPm || $hasDir;

            if ($canDecideHr && $hasIngameDecide)        $verdict = 'aligned';
            elseif ($canDecideHr && !$hasIngameDecide)   $verdict = 'no_ingame';
            elseif (!$canDecideHr && $hasIngameDecide)   $verdict = 'no_hr';
            else                                         $verdict = 'recruiter_only';

            $mainId = (int) ($mainByUser[$uid] ?? 0);

            // Decorate the holding characters with names + a main flag, main
            // first, so the view can render "Director via <alt>" beneath the row.
            $ingameChars = [];
            foreach (($ingameByUser[$uid]['chars'] ?? []) as $cid => $flags) {
                $ingameChars[] = [
                    'character_id' => (int) $cid,
                    'name'         => $names[(int) $cid] ?? ('#' . $cid),
                    'pm'           => !empty($flags['pm']),
                    'director'     => !empty($flags['director']),
                    'is_main'      => ((int) $cid === $mainId),
                ];
            }
            usort($ingameChars, fn ($a, $b) => ($b['is_main'] <=> $a['is_main']) ?: strcmp($a['name'], $b['name']));

            $rows[] = [
                'user_id'           => $uid,
                'main_character_id' => $mainId,
                'main_name'         => $names[$mainId] ?? ('User #' . $uid),
                'hr'                => [
                    'recruiter'  => $isRecruiterHr,
                    'personnel'  => in_array($uid, $hrPersonnel, true),
                    'director'   => in_array($uid, $hrDirector, true),
                    'admin'      => in_array($uid, $hrAdmin, true),
                    'can_decide' => $canDecideHr,
                ],
                'ingame'            => ['pm' => $hasPm, 'director' => $hasDir, 'chars' => $ingameChars],
                'verdict'           => $verdict,
            ];
        }

        $order = ['no_hr' => 0, 'no_ingame' => 1, 'aligned' => 2, 'recruiter_only' => 3];
        usort($rows, fn ($a, $b) => ($order[$a['verdict']] <=> $order[$b['verdict']]) ?: strcmp($a['main_name'], $b['main_name']));

        $mismatchCount = count(array_filter($rows, fn ($r) => in_array($r['verdict'], ['no_hr', 'no_ingame'], true)));

        return ['rows' => $rows, 'mismatch_count' => $mismatchCount];
    }

    /**
     * User ids EXPLICITLY granted an HR permission via SeAT's ACL
     * (permission -> permission_role -> role_user). Superusers are excluded on
     * purpose — they override everything and aren't the recruitment org we map.
     */
    private function hrPermissionUserIds(string $title): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('permissions')
                || !\Illuminate\Support\Facades\Schema::hasTable('permission_role')
                || !\Illuminate\Support\Facades\Schema::hasTable('role_user')) {
                return [];
            }

            return DB::table('permissions')
                ->join('permission_role', 'permission_role.permission_id', '=', 'permissions.id')
                ->join('role_user', 'role_user.role_id', '=', 'permission_role.role_id')
                ->where('permissions.title', $title)
                ->pluck('role_user.user_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] access-map permission query failed: ' . $e->getMessage());
            return [];
        }
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

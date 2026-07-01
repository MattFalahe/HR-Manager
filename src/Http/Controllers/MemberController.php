<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\MemberAssessment;
use HrManager\Models\Note;
use HrManager\Services\CharacterRoleClassifier;
use HrManager\Services\CharacterTitleService;
use HrManager\Services\CrossPluginDataService;
use HrManager\Services\NameResolutionService;
use HrManager\Services\PlayerIdentityResolver;
use HrManager\Services\SeatConnectorService;
use HrManager\Services\WalletAuditService;
use HrManager\Services\ZkillService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class MemberController extends Controller
{
    use ScopesCorporationAccess;

    public function index(Request $request)
    {
        $allowedCorps = $this->getAllowedCorpIds();
        $corporationId = $this->resolveCorporationContext($request, $allowedCorps);
        $this->assertCanAccessCorp($corporationId);

        // Source-of-truth roster table. Priority order:
        //   1. corporation_members        - authoritative full roster from
        //                                   esi-corporations.read_corporation_membership.v1
        //   2. corporation_member_trackings - same roster plus login info,
        //                                   requires the director track scope
        //   3. character_affiliations     - last resort; only contains chars
        //                                   SeAT has touched (sparse)
        // We probe each in order and pick the first that actually has rows
        // for this corp. This keeps the page useful even on partial syncs
        // and degrades gracefully when no director token is registered.
        $rosterSource = $this->resolveRosterSource($corporationId);

        $search = trim((string) $request->input('search', ''));

        $query = DB::table($rosterSource . ' as cm')
            ->leftJoin('character_infos as ci', 'ci.character_id', '=', 'cm.character_id')
            ->leftJoin('character_affiliations as ca', 'ca.character_id', '=', 'cm.character_id')
            ->where('cm.corporation_id', $corporationId)
            ->select([
                'cm.character_id',
                'ci.name as info_name',
                'ca.alliance_id',
            ]);

        if ($search !== '') {
            // Match in character_infos.name OR universe_names.name so
            // unregistered alts that SeAT has resolved through the
            // names cache are still findable.
            $query->where(function ($q) use ($search) {
                $q->where('ci.name', 'like', "%{$search}%");
                if (Schema::hasTable('universe_names')) {
                    $q->orWhereExists(function ($sub) use ($search) {
                        $sub->select(DB::raw(1))
                            ->from('universe_names')
                            ->whereColumn('entity_id', 'cm.character_id')
                            ->where('category', 'character')
                            ->where('name', 'like', "%{$search}%");
                    });
                }
            });
        }

        // Order by resolvable name; rows with NULL info_name sink to the
        // bottom by default which puts unknown / unresolved chars last.
        $members = $query
            ->orderByRaw('ci.name IS NULL ASC')
            ->orderBy('ci.name')
            ->orderBy('cm.character_id')
            ->paginate(50);

        $charIds = $members->pluck('character_id')->map(fn($id) => (int) $id)->all();

        // Batch-resolve names using the MM-style NameResolutionService
        // (mirrors MM's ExternalCharacterService pattern). Walks
        // character_infos -> universe_names -> ESI POST /universe/names/
        // batch endpoint (up to 1000 IDs per call). Persists resolved
        // names into universe_names so subsequent loads + other plugins
        // benefit.
        $universeNames = [];
        if (!empty($charIds)) {
            $resolved = app(NameResolutionService::class)->getCharacterNames($charIds);
            foreach ($resolved as $id => $name) {
                $universeNames[$id] = $name;
            }
        }

        // Registration map (character_id => true when a refresh token exists)
        $registered = !empty($charIds)
            ? DB::table('refresh_tokens')
                ->whereIn('character_id', $charIds)
                ->whereNull('deleted_at')
                ->pluck('character_id')
                ->map(fn($id) => (int) $id)
                ->all()
            : [];
        $registeredSet = array_flip($registered);

        // Token-health map: full refresh_tokens row (including trashed) per
        // character so the roster can badge valid / missing-scopes / lost /
        // never-linked against the corp requirement profile.
        $tokenHealth   = app(\HrManager\Services\TokenHealthService::class);
        $tokenRequired = $tokenHealth->requiredScopes();
        $tokenMap = !empty($charIds)
            ? DB::table('refresh_tokens')
                ->whereIn('character_id', $charIds)
                ->get(['character_id', 'scopes', 'deleted_at'])
                ->keyBy(fn ($r) => (int) $r->character_id)
            : collect();

        // Resolve corp name once (every row shares the same corp).
        // Also resolve the corp's alliance_id so unregistered chars
        // without their own alliance_id in character_affiliations
        // inherit it (every char in a corp shares the corp's alliance).
        $corp = CorporationInfo::find($corporationId);
        $corpName = $corp->name ?? null;
        $corpAllianceId = $corp->alliance_id ?? null;

        // Bulk-load alliance names. Three-source fallback:
        //   alliance_infos (canonical) -> universe_names cache -> ESI
        // ESI fallback only fires for IDs missing from both DB sources.
        $allianceIds = array_values(array_unique(array_filter(array_merge(
            $members->pluck('alliance_id')->all(),
            [$corpAllianceId]
        ))));
        $allianceNames = [];
        if (!empty($allianceIds)) {
            if (Schema::hasTable('alliance_infos')) {
                $allianceNames = DB::table('alliance_infos')
                    ->whereIn('alliance_id', $allianceIds)
                    ->pluck('name', 'alliance_id')
                    ->toArray();
            }
            if (Schema::hasTable('universe_names')) {
                $missingIds = array_diff($allianceIds, array_keys($allianceNames));
                if (!empty($missingIds)) {
                    $extra = DB::table('universe_names')
                        ->whereIn('entity_id', $missingIds)
                        ->where('category', 'alliance')
                        ->pluck('name', 'entity_id')
                        ->toArray();
                    $allianceNames = $allianceNames + $extra;
                }
            }
            // Final fallback: live ESI via NameResolutionService for
            // any alliance still missing. Only fires when we have a
            // non-empty list of unresolved alliance IDs (usually
            // 0 or 1 in practice).
            $stillMissing = array_diff($allianceIds, array_keys($allianceNames));
            if (!empty($stillMissing)) {
                $resolver = app(NameResolutionService::class);
                foreach ($stillMissing as $aid) {
                    $n = $resolver->getAllianceName((int) $aid);
                    if ($n !== null) {
                        $allianceNames[(int) $aid] = $n;
                    }
                }
            }
        }

        // Decorate paginated rows so the view can stay shape-stable
        // (corporation/alliance pseudo-relations matching the previous
        // CharacterAffiliation hydration).
        $resolver = app(NameResolutionService::class);
        $members->getCollection()->transform(function ($row) use ($universeNames, $registeredSet, $corpName, $allianceNames, $corpAllianceId, $resolver, $tokenHealth, $tokenRequired, $tokenMap) {
            $cid = (int) $row->character_id;
            $infoName = $row->info_name;
            $row->display_name = ($resolver->isUsableName($infoName) ? (string) $infoName : null)
                ?? ($universeNames[$cid] ?? null)
                ?? ('Character #' . $cid);
            $row->is_registered = isset($registeredSet[$cid]);
            $tokenClass = $tokenHealth->classify($tokenMap->get($cid), $tokenRequired);
            $row->token_status = $tokenClass['status'];
            $row->token_missing = $tokenClass['missing'];
            $row->corporation = $corpName !== null ? (object) ['name' => $corpName] : null;
            // Alliance fallback: when row has no alliance_id but the
            // corp is in an alliance, inherit. Real-world: every char
            // in a corp is automatically in the corp's alliance.
            $effectiveAllianceId = $row->alliance_id ?: $corpAllianceId;
            $row->alliance_id = $effectiveAllianceId; // overwrite for view consistency
            if ($effectiveAllianceId) {
                $row->alliance = (object) [
                    'name' => $allianceNames[(int) $effectiveAllianceId] ?? null,
                ];
            } else {
                $row->alliance = null;
            }
            return $row;
        });

        $corporations = $this->corporationPickerOptions($allowedCorps);

        // Headline counts based on the same authoritative roster source.
        $totalMembers = (int) DB::table($rosterSource)
            ->where('corporation_id', $corporationId)
            ->count();
        $registeredCount = (int) DB::table($rosterSource . ' as r')
            ->join('refresh_tokens', 'refresh_tokens.character_id', '=', 'r.character_id')
            ->where('r.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->distinct('r.character_id')
            ->count('r.character_id');
        $unregisteredCount = max(0, $totalMembers - $registeredCount);

        // Surfaced so the view can show a hint when corporation_members
        // isn't populated (no director token = sparse roster).
        $rosterStatus = [
            'source'                  => $rosterSource,
            'is_authoritative'        => in_array($rosterSource, ['corporation_members', 'corporation_member_trackings'], true),
            'needs_director_token'    => !in_array($rosterSource, ['corporation_members', 'corporation_member_trackings'], true),
        ];

        return view('hr-manager::members.index', compact(
            'members',
            'corporationId',
            'corporations',
            'totalMembers',
            'registeredCount',
            'unregisteredCount',
            'rosterStatus'
        ));
    }

    /**
     * Probe roster tables in priority order and pick the first that has
     * rows for this corp. Falls back to character_affiliations if neither
     * SeAT corporation_members nor corporation_member_trackings is
     * populated (no director token + scope, or first-time sync still
     * pending).
     */
    private function resolveRosterSource(int $corporationId): string
    {
        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (Schema::hasTable($table)
                && DB::table($table)->where('corporation_id', $corporationId)->limit(1)->exists()) {
                return $table;
            }
        }
        return 'character_affiliations';
    }

    public function show(int $characterId)
    {
        // Build a synthetic affiliation from whatever sources are
        // available so the page renders for unregistered alts too.
        // Priority for corp resolution:
        //   character_affiliations -> corporation_members ->
        //   corporation_member_trackings. If none has the character,
        //   404 because we can't even do the corp access check.
        $resolved = $this->resolveCharacterContext($characterId);
        if (!$resolved) {
            abort(404, 'Character not known to any tracked corporation.');
        }

        $corporationId = $resolved['corporation_id'];
        $this->assertCanAccessCorp($corporationId);

        $isRegistered = $resolved['is_registered'];
        $displayName  = $resolved['display_name'];
        $allianceId   = $resolved['alliance_id'];
        $corpName     = $resolved['corp_name'];
        $allianceName = $resolved['alliance_name'];

        // affiliation is a real Eloquent row when present (for backwards
        // compat with view code that uses the relation), otherwise a
        // PHP stdClass with the same accessors the view needs.
        $affiliation = $resolved['affiliation'];

        $assessment = MemberAssessment::forCharacter($characterId)
            ->where('corporation_id', $corporationId)
            ->first();

        $viewerUserId = auth()->user()->id;
        $notes = Note::where('noteable_type', 'member')
            ->where('noteable_id', $characterId)
            ->where(function ($q) use ($viewerUserId) {
                $q->where('is_private', false)->orWhere('author_id', $viewerUserId);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // Resolve note authors (SeAT user_id) to their main-character name so
        // the notes panel shows people not "User #2", and flag superusers.
        $noteAuthorIds = $notes->pluck('author_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $userNames = app(\HrManager\Services\NameResolutionService::class)->getUserNames($noteAuthorIds);
        $noteAuthorAdmins = empty($noteAuthorIds) ? [] : DB::table('users')
            ->whereIn('id', $noteAuthorIds)
            ->where('admin', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Cross-plugin enrichment. Each lookup is gated by
        // class_exists / Schema::hasTable inside its service so this
        // page renders even when every sibling plugin is missing.
        $ownerUserId = DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->whereNull('deleted_at')
            ->value('user_id');
        $ownerUserId = $ownerUserId !== null ? (int) $ownerUserId : null;

        $cross = app(CrossPluginDataService::class);
        $walletActivity = $this->buildWalletActivity(
            $cross,
            $characterId,
            $corporationId
        );

        // Mining detail — favourite ores + systems + corp ore-op
        // attendance (recruiter+ visible; mining activity is a profile
        // fact, not fraud-sensitive). All degrade to available=false
        // when MM isn't installed.
        $miningDetail = [
            'ores'       => $cross->getTopMiningOres($characterId, 6),
            'systems'    => $cross->getTopMiningSystems($characterId, 6),
            'attendance' => $corporationId
                ? $cross->getMiningEventAttendance($characterId, $corporationId, 6)
                : ['available' => false],
        ];

        // Some integrations only make sense for registered chars
        // (Discord identity). zKill works for any character ID
        // since it's a public ESI lookup.
        $discord = $isRegistered
            ? app(SeatConnectorService::class)->getIdentityForCharacter($characterId)
            : ['available' => false, 'discord_username' => null, 'connector_id' => null, 'roles' => [], 'reason' => 'character_unregistered'];
        $pvp     = app(ZkillService::class)->getCharacterStats($characterId);

        // In-game titles + direct roles snapshot. Strips EVE color
        // markup at the service layer.
        $titleSnapshot = app(CharacterTitleService::class)
            ->snapshotForCharacter($characterId, $corporationId);

        // Player identity — lazy-resolved. Auto-creates a ghost
        // identity for unregistered alts so the member profile can
        // always link to a player page.
        $playerIdentity = app(PlayerIdentityResolver::class)->forCharacter($characterId);

        // Director-only wallet audit (income / expense / fraud
        // signals). Gated here so we don't waste CWM bridge calls
        // for recruiter views that won't render the panel.
        $walletAudit = ['available' => false];
        if (auth()->user()->can('hr-manager.director')) {
            $walletAudit = app(WalletAuditService::class)
                ->snapshot($characterId, $corporationId);
        }

        // Character role badges — what this character is USED FOR based
        // on observed activity (ratting / mining / PI / industry).
        // Recruiter+ visible (it's not fraud-sensitive, just a profile).
        $roleProfile = app(CharacterRoleClassifier::class)
            ->classify($characterId, $corporationId);

        return view('hr-manager::members.show', compact(
            'affiliation',
            'assessment',
            'notes',
            'walletActivity',
            'discord',
            'pvp',
            'displayName',
            'ownerUserId',
            'titleSnapshot',
            'isRegistered',
            'allianceName',
            'corpName',
            'corporationId',
            'allianceId',
            'playerIdentity',
            'walletAudit',
            'roleProfile',
            'miningDetail',
            'userNames',
            'noteAuthorAdmins'
        ));
    }

    /**
     * Resolve everything we need to render the member-profile page
     * for a character_id. Walks the authoritative source chain so
     * unregistered alts get a page too (with limited data + warning
     * banner) instead of a 404.
     *
     * Returns null when the character isn't in any tracked corp at
     * all — in that case 404 is correct because we can't determine a
     * corp_id for the access check.
     *
     * @return array{
     *   corporation_id:int,
     *   corp_name:?string,
     *   alliance_id:?int,
     *   alliance_name:?string,
     *   display_name:string,
     *   is_registered:bool,
     *   affiliation:object
     * }|null
     */
    private function resolveCharacterContext(int $characterId): ?array
    {
        // 1. Try character_affiliations (most data when present).
        $affRow = CharacterAffiliation::where('character_id', $characterId)
            ->with(['character', 'corporation', 'alliance'])
            ->first();

        $corpId = $affRow?->corporation_id;
        $allianceId = $affRow?->alliance_id;

        // 2. Fall back to corporation_members for the corp_id.
        if ($corpId === null && Schema::hasTable('corporation_members')) {
            $corpId = DB::table('corporation_members')
                ->where('character_id', $characterId)
                ->value('corporation_id');
        }
        if ($corpId === null && Schema::hasTable('corporation_member_trackings')) {
            $corpId = DB::table('corporation_member_trackings')
                ->where('character_id', $characterId)
                ->value('corporation_id');
        }
        if ($corpId === null) {
            return null;
        }
        $corpId = (int) $corpId;

        // Registration check: refresh_token presence.
        $isRegistered = DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->whereNull('deleted_at')
            ->exists();

        // Display name via the shared NameResolutionService (mirrors
        // MM's ExternalCharacterService pattern: SeAT cache first, then
        // ESI /characters/{id}/ with 24h Cache::remember, then zKill
        // fallback). Persists to universe_names so subsequent loads
        // skip the network entirely.
        //
        // SeAT writes the LITERAL string "Unknown" into
        // character_infos.name when its own ESI sync failed — that's
        // a placeholder, not a real name. Filter it through
        // NameResolutionService::isUsableName so we fall through to a
        // live ESI lookup instead of rendering the placeholder.
        $resolver = app(NameResolutionService::class);
        $relationName = $affRow?->character?->name;
        $displayName = ($resolver->isUsableName($relationName) ? (string) $relationName : null)
            ?? $resolver->getCharacterName($characterId)
            ?? ('Character #' . $characterId);

        // Corp name.
        $corpName = $affRow?->corporation?->name
            ?? DB::table('corporation_infos')->where('corporation_id', $corpId)->value('name');

        // Alliance: prefer the row's value, fall back to corp's
        // alliance_id (chars in a corp share its current alliance).
        if ($allianceId === null) {
            $allianceId = DB::table('corporation_infos')
                ->where('corporation_id', $corpId)
                ->value('alliance_id');
        }
        $allianceId = $allianceId ? (int) $allianceId : null;

        // Alliance name: alliance_infos -> universe_names -> ESI
        // (cascaded through NameResolutionService).
        $allianceName = null;
        if ($allianceId) {
            $allianceName = $affRow?->alliance?->name
                ?? $resolver->getAllianceName($allianceId);
        }

        // Build a "real or synthetic" affiliation object for the view.
        $affiliation = $affRow ?: (object) [
            'character_id'   => $characterId,
            'corporation_id' => $corpId,
            'alliance_id'    => $allianceId,
            'character'      => (object) ['name' => $displayName],
            'corporation'    => (object) ['name' => $corpName],
            'alliance'       => $allianceId ? (object) ['name' => $allianceName] : null,
        ];

        return [
            'corporation_id' => $corpId,
            'corp_name'      => $corpName,
            'alliance_id'    => $allianceId,
            'alliance_name'  => $allianceName,
            'display_name'   => (string) $displayName,
            'is_registered'  => (bool) $isRegistered,
            'affiliation'    => $affiliation,
        ];
    }

    // v2: shape gained breakdown / entries / percentile sub-blocks.
    private const WALLET_ACTIVITY_CACHE_PREFIX = 'hr-wallet-activity-v2-';
    private const WALLET_ACTIVITY_CACHE_TTL    = 600; // 10 minutes

    /**
     * Aggregate CWM wallet signals for the member detail view in a single
     * pass. Returns a flat array the partial can render without making any
     * service calls itself.
     *
     * Cached per (character, corp) for 10 minutes. The five PluginBridge
     * calls inside cost roughly 5x ~30ms each in production traffic; the
     * cache turns repeat profile views into a single Redis read.
     * refreshAssessment busts the key.
     */
    protected function buildWalletActivity(CrossPluginDataService $cross, int $characterId, ?int $corporationId): array
    {
        if (!$corporationId) {
            return ['available' => false, 'reason' => 'no_corporation'];
        }

        return Cache::remember(
            self::WALLET_ACTIVITY_CACHE_PREFIX . $characterId . '-' . $corporationId,
            self::WALLET_ACTIVITY_CACHE_TTL,
            function () use ($cross, $characterId, $corporationId) {
                $lifetime    = $cross->getCharacterLifetimeSummary($characterId, $corporationId);
                $trend       = $cross->getCharacterContributionTrend($characterId, $corporationId, 6);
                $gaps        = $cross->getCharacterActivityGaps($characterId, $corporationId, 12);
                $netPosition = $cross->getCharacterNetPosition($characterId, $corporationId, 6);
                $tax         = $cross->getCharacterTaxCompliance($characterId, $corporationId, 6);
                // Round-3 enrichments: surface the new CWM "My Contribution"
                // signals (category breakdown + recent entries) and the
                // existing percentile capability that was already exposed
                // but never rendered in HR.
                $breakdown   = $cross->getCharacterCategoryBreakdown($characterId, $corporationId, 6);
                $entries     = $cross->getCharacterRecentEntries($characterId, $corporationId, 5);
                $percentile  = $cross->getCharacterContributionPercentile($characterId, $corporationId, 'last_3_months');

                $anyAvailable = ($lifetime['available'] ?? false)
                    || ($trend['available'] ?? false)
                    || ($gaps['available'] ?? false)
                    || ($netPosition['available'] ?? false)
                    || ($tax['available'] ?? false);

                return [
                    'available'    => $anyAvailable,
                    'reason'       => $anyAvailable ? null : ($lifetime['reason'] ?? 'unavailable'),
                    'lifetime'     => $lifetime,
                    'trend'        => $trend,
                    'gaps'         => $gaps,
                    'net_position' => $netPosition,
                    'tax'          => $tax,
                    'breakdown'    => $breakdown,
                    'entries'      => $entries,
                    'percentile'   => $percentile,
                ];
            }
        );
    }

    public function refreshAssessment(int $characterId)
    {
        $this->assertCanAccessCharacter($characterId);

        try {
            $service = app(\HrManager\Services\AssessmentService::class);
            $service->refreshAssessment($characterId);

            // Also refresh zKill cache so the Recent PvP panel shows live
            // data after operator clicks Refresh Data.
            app(ZkillService::class)->refreshCharacterStats($characterId);

            // Bust the wallet-activity cache so the next view fires fresh
            // PluginBridge calls instead of serving the stale 10-min copy.
            $corpId = (int) DB::table('character_affiliations')
                ->where('character_id', $characterId)
                ->value('corporation_id');
            if ($corpId > 0) {
                Cache::forget(self::WALLET_ACTIVITY_CACHE_PREFIX . $characterId . '-' . $corpId);
                // The corp-level health page also stales after a fresh
                // assessment — bust so directors see up-to-date aggregates.
                app(\HrManager\Services\CorpStatusService::class)->bustCache($corpId);
            }

            // Bust the Discord identity cache so updates surface
            // immediately. 10-min TTL cache keyed by user_id.
            $userId = (int) DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->whereNull('deleted_at')
                ->value('user_id');
            if ($userId > 0) {
                app(SeatConnectorService::class)->bustCache($userId);
            }

            return redirect()->route('hr-manager.members.show', $characterId)
                ->with('success', 'Assessment data refreshed.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[HR Manager] Assessment refresh failed', [
                'character_id' => $characterId,
                'error' => $e->getMessage(),
            ]);
            return redirect()->route('hr-manager.members.show', $characterId)
                ->with('error', 'Failed to refresh assessment data. Check logs for details.');
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

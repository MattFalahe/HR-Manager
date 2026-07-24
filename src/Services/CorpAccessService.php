<?php

namespace HrManager\Services;

use HrManager\Models\PlayerClassification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Corp-wide access-depth aggregation for the Corp Health "Access" tab.
 *
 * Answers, for one corporation, "who holds elevated access — in SeAT and in
 * Discord — and where are the risky mismatches?" Two sections:
 *
 *   SeAT access    -> superusers (users.admin), HR admin/director grants, and
 *                     in-game Directors, unioned into an elevated-accounts list.
 *   Discord access -> which corp members hold a role the operator flagged
 *                     "sensitive" (leadership / FC / wallet), via seat-connector.
 *
 * Then cross-references both against the classifier so a director sees deep
 * access sitting on a disengaged account (the classic dormant-with-power gap).
 *
 * Everything is batched (a handful of whereIn queries, no per-member fan-out)
 * and every optional source is Schema::hasTable-guarded, so it degrades to
 * empty sections rather than crashing when seat-connector isn't installed.
 */
class CorpAccessService
{
    /** Classifier categories that make elevated access a risk to surface. */
    private const RISK_CATEGORIES = [
        PlayerClassification::CATEGORY_AT_RISK,
        PlayerClassification::CATEGORY_INACTIVE,
        PlayerClassification::CATEGORY_DEAD_WEIGHT,
    ];

    public function __construct(private SeatConnectorService $connector)
    {
    }

    /**
     * @return array{
     *   seat: array,
     *   discord: array,
     *   mismatches: array<int, array{type: string, main_name: string, detail: string, severity: string}>,
     * }
     */
    public function forCorporation(int $corporationId): array
    {
        // Corp accounts: users with a (non-deleted) character in this corp.
        $corpUserIds = Schema::hasTable('refresh_tokens') && Schema::hasTable('character_affiliations')
            ? DB::table('refresh_tokens')
                ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                ->where('character_affiliations.corporation_id', $corporationId)
                ->whereNull('refresh_tokens.deleted_at')
                ->pluck('refresh_tokens.user_id')->map(fn ($i) => (int) $i)->unique()->values()->all()
            : [];

        // Corp headcount is CHARACTER-based (member tracking): total tracked
        // characters, and how many of those actually carry a SeAT token. The
        // registered/unregistered split must be counted in characters — mixing
        // it with the account count (a person owns many characters) is wrong.
        $memberTotal = Schema::hasTable('corporation_member_trackings')
            ? (int) DB::table('corporation_member_trackings')->where('corporation_id', $corporationId)->distinct()->count('character_id')
            : count($corpUserIds);
        $registeredCharCount = (Schema::hasTable('corporation_member_trackings') && Schema::hasTable('refresh_tokens'))
            ? (int) DB::table('corporation_member_trackings as m')
                ->join('refresh_tokens as rt', function ($j) {
                    $j->on('rt.character_id', '=', 'm.character_id')->whereNull('rt.deleted_at');
                })
                ->where('m.corporation_id', $corporationId)
                ->distinct()->count('m.character_id')
            : count($corpUserIds);
        $unregisteredCount = max(0, $memberTotal - $registeredCharCount);

        if (empty($corpUserIds)) {
            return [
                'seat'       => $this->emptySeat($memberTotal),
                'discord'    => $this->emptyDiscord(),
                'mismatches' => [],
            ];
        }

        // Superusers (users.admin) + HR power grants among corp accounts.
        $superusers = DB::table('users')->whereIn('id', $corpUserIds)->where('admin', 1)
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
        $hrAdmins    = array_intersect($this->permissionUserIds('hr-manager.admin'), $corpUserIds);
        $hrDirectors = array_intersect($this->permissionUserIds('hr-manager.director'), $corpUserIds);

        // In-game Directors in this corp -> the accounts behind them.
        $ingameDirectorUsers = $this->ingameDirectorUsers($corporationId);

        // Classifier category per account (for the dormant-with-power cross-ref).
        $categoryByUser = $this->categoryByUser($corporationId);

        // SeAT roles each account holds — direct (Access Management) AND via
        // squad membership (squad_role). This is how many installs actually
        // grant power, so it belongs in an access-depth view.
        $seatRolesByUser = $this->seatRolesForUsers($corpUserIds);

        // How many accounts get a SeAT role by each path — so the operator can
        // see (and filter) squad-granted vs directly-assigned access.
        $squadRoleHolders = 0;
        $directRoleHolders = 0;
        foreach ($seatRolesByUser as $roles) {
            $hasSquad = false;
            $hasDirect = false;
            foreach ($roles as $role) {
                if (!empty($role['via_squad'])) { $hasSquad = true; } else { $hasDirect = true; }
            }
            if ($hasSquad) { $squadRoleHolders++; }
            if ($hasDirect) { $directRoleHolders++; }
        }

        // The union of everyone with elevated SeAT access: capability signals
        // (superuser / director / HR grants) PLUS anyone holding a SeAT role.
        $elevatedUserIds = array_values(array_unique(array_merge(
            $superusers, $hrAdmins, $hrDirectors, array_keys($ingameDirectorUsers), array_keys($seatRolesByUser)
        )));

        // Discord: which corp accounts are linked, and every role each holds.
        $sensitiveRoleIds  = $this->connector->sensitiveRoleIds();
        $discordLinked     = $this->discordLinkedUsers($corpUserIds);
        $discordHolders    = $this->discordRoleHolders($discordLinked, $sensitiveRoleIds);
        // Registered accounts with NO Discord link — a visibility gap worth
        // naming, not just counting.
        $discordUnlinkedUserIds = $this->connector->isAvailable()
            ? array_values(array_diff($corpUserIds, $discordLinked))
            : [];

        // Resolve display names for every registered account (mains).
        $mainByUser  = Schema::hasTable('users')
            ? DB::table('users')->whereIn('id', $corpUserIds ?: [0])->pluck('main_character_id', 'id')->toArray()
            : [];
        $mainIds = array_values(array_filter(array_map('intval', $mainByUser)));
        $names   = !empty($mainIds)
            ? app(NameResolutionService::class)->getCharacterNamesWithFallback($mainIds)
            : [];
        $nameFor = function (int $uid) use ($mainByUser, $names) {
            $mid = (int) ($mainByUser[$uid] ?? 0);
            return $names[$mid] ?? ('User #' . $uid);
        };

        // Build ONE unified row per registered account: SeAT access and Discord
        // access side by side, so a person's two access surfaces read together.
        // Tier comes from the activity-mapping resolver and drives the sort.
        $tierService = app(TierService::class);
        $sensitiveHolderCount = 0;
        $people = [];
        foreach ($corpUserIds as $uid) {
            $isSuper   = in_array($uid, $superusers, true);
            $isDir     = isset($ingameDirectorUsers[$uid]);
            $isHrDir   = in_array($uid, $hrDirectors, true);
            $isHrAdmin = in_array($uid, $hrAdmins, true);
            $seatRoles = $seatRolesByUser[$uid] ?? [];
            $hasCapability = $isSuper || $isDir || $isHrDir || $isHrAdmin;

            $discordInfo   = $discordHolders[$uid] ?? null;
            $discordRoles  = $discordInfo['roles'] ?? [];
            $discSensitive = (int) ($discordInfo['sensitive_count'] ?? 0);
            $isLinked      = in_array($uid, $discordLinked, true);
            if ($discSensitive > 0) {
                $sensitiveHolderCount++;
            }

            // Activity-tier mapping (if any) — primary sort key + a badge.
            $tier = $tierService->resolveTier($uid, $corporationId);

            $category = $categoryByUser[$uid] ?? null;
            $flagged  = in_array($category, self::RISK_CATEGORIES, true);
            // A row is a "risk" when a genuine access signal (deep SeAT
            // capability OR a sensitive Discord role) sits on a flagged account.
            $isRisk = $flagged && ($hasCapability || $discSensitive > 0);

            // Sort fallback when there's no activity mapping: superuser first,
            // then in-game Director, then HR, then everyone else.
            $capRank = $isSuper ? 0 : ($isDir ? 1 : (($isHrDir || $isHrAdmin) ? 2 : 3));

            $people[] = [
                'user_id'                 => $uid,
                'main_character_id'       => (int) ($mainByUser[$uid] ?? 0),
                'main_name'               => $nameFor($uid),
                'tier_level'              => $tier['level'] ?? null,
                'tier_threshold'          => $tier['threshold_days'] ?? null,
                'superuser'               => $isSuper,
                'ingame_director'         => $isDir,
                'director_chars'          => $ingameDirectorUsers[$uid] ?? [],
                'hr_director'             => $isHrDir,
                'hr_admin'                => $isHrAdmin,
                'seat_roles'              => $seatRoles,
                'has_seat_access'         => $hasCapability || !empty($seatRoles),
                'discord_linked'          => $isLinked,
                'discord_roles'           => $discordRoles,
                'discord_sensitive_count' => $discSensitive,
                'category'                => $category,
                'is_risk'                 => $isRisk,
                'unregistered'            => false,
                'cap_rank'                => $capRank,
            ];
        }

        // Unregistered characters (tracked in the corp, but with no SeAT token)
        // are the biggest visibility gap — list them too, one row each, so a
        // director sees who has zero SeAT oversight AND no Discord link. They
        // have no account, so no tier / roles; sort to the very bottom.
        foreach ($this->unregisteredCharacters($corporationId) as $uc) {
            $people[] = [
                'user_id'                 => null,
                'main_character_id'       => $uc['character_id'],
                'main_name'               => $uc['name'],
                'tier_level'              => null,
                'tier_threshold'          => null,
                'superuser'               => false,
                'ingame_director'         => false,
                'director_chars'          => [],
                'hr_director'             => false,
                'hr_admin'                => false,
                'seat_roles'              => [],
                'has_seat_access'         => false,
                'discord_linked'          => false,
                'discord_roles'           => [],
                'discord_sensitive_count' => 0,
                'category'                => null,
                'is_risk'                 => false,
                'unregistered'            => true,
                'cap_rank'                => 5,
            ];
        }

        // Sort: activity tier first (higher tier earlier; no mapping sorts to
        // the end), then the capability fallback, then name.
        usort($people, function ($a, $b) {
            $ta = $a['tier_level'] ?? -1;
            $tb = $b['tier_level'] ?? -1;
            return ($tb <=> $ta)
                ?: ($a['cap_rank'] <=> $b['cap_rank'])
                ?: strcmp($a['main_name'], $b['main_name']);
        });

        return [
            'seat' => [
                'account_count'      => count($corpUserIds),
                'member_total'       => $memberTotal,
                'registered_char_count' => $registeredCharCount,
                'unregistered_count' => $unregisteredCount,
                'superuser_count'    => count($superusers),
                'hr_admin_count'     => count($hrAdmins),
                'hr_director_count'  => count($hrDirectors),
                'ingame_director_count' => count($ingameDirectorUsers),
                'seat_role_count'    => count($seatRolesByUser),
                'squad_role_count'   => $squadRoleHolders,
                'direct_role_count'  => $directRoleHolders,
            ],
            'discord' => [
                'available'       => $this->connector->isAvailable(),
                'configured'      => !empty($sensitiveRoleIds),
                'linked_count'    => count($discordLinked),
                'unlinked_count'  => count($discordUnlinkedUserIds),
                // No Discord link at all: registered-but-unlinked accounts plus
                // unregistered characters (they can't be linked either).
                'no_discord_count' => $this->connector->isAvailable()
                    ? count($discordUnlinkedUserIds) + $unregisteredCount
                    : 0,
                'sensitive_count' => $sensitiveHolderCount,
            ],
            'people'     => $people,
            'mismatches' => $this->buildMismatches($people),
        ];
    }

    /**
     * Risk call-outs, one per person: a genuine access signal (deep SeAT
     * capability, else a sensitive Discord role) sitting on a classifier-flagged
     * (at-risk / inactive / dead-weight) account. Plain squad/role grants aren't
     * called out — we can't know which roles are powerful without operator input.
     */
    private function buildMismatches(array $people): array
    {
        $out = [];
        foreach ($people as $r) {
            if (!$r['is_risk']) {
                continue;
            }
            $hasCapability = $r['superuser'] || $r['ingame_director'] || $r['hr_director'] || $r['hr_admin'];
            if ($hasCapability) {
                $what = $r['superuser'] ? 'SeAT superuser'
                    : ($r['ingame_director'] ? 'in-game Director'
                    : ($r['hr_director'] ? 'HR director' : 'HR admin'));
                $out[] = [
                    'type'      => 'seat_power_dormant',
                    'main_name' => $r['main_name'],
                    'detail'    => $what,
                    'category'  => $r['category'],
                    'severity'  => ($r['superuser'] || $r['ingame_director']) ? 'high' : 'medium',
                ];
            } elseif ($r['discord_sensitive_count'] > 0) {
                $sensitiveNames = array_values(array_map(
                    fn ($x) => $x['name'],
                    array_filter($r['discord_roles'], fn ($x) => !empty($x['sensitive']))
                ));
                $out[] = [
                    'type'      => 'discord_power_dormant',
                    'main_name' => $r['main_name'],
                    'detail'    => implode(', ', array_slice($sensitiveNames, 0, 4)),
                    'category'  => $r['category'],
                    'severity'  => 'medium',
                ];
            }
        }
        // High severity first, then by name.
        usort($out, fn ($a, $b) => (($b['severity'] === 'high') <=> ($a['severity'] === 'high')) ?: strcmp($a['main_name'], $b['main_name']));
        return $out;
    }

    /**
     * SeAT roles each corp account holds, split by HOW they were granted:
     *   - via squad membership (squad_member -> squad_role)
     *   - direct assignment via the Access Management model (role_user)
     * Returns [user_id => [ ['name' => role title, 'via_squad' => ?squad name] ]].
     *
     * IMPORTANT: SeAT's SquadMember/SquadRole observers MATERIALISE a squad's
     * roles into role_user (syncWithoutDetaching) — so a squad-granted role also
     * appears in role_user and can't be told apart there. Therefore we resolve
     * SQUAD grants FIRST (they win); a role only counts as "direct" when it's in
     * role_user AND not attributable to any of the user's squads.
     */
    private function seatRolesForUsers(array $corpUserIds): array
    {
        if (empty($corpUserIds) || !Schema::hasTable('roles')) {
            return [];
        }
        $byUser = [];

        // Squad-granted roles first — these take precedence over the role_user
        // row SeAT mirrors them into.
        try {
            if (Schema::hasTable('squad_member') && Schema::hasTable('squad_role') && Schema::hasTable('squads')) {
                $rows = DB::table('squad_member')
                    ->join('squad_role', 'squad_role.squad_id', '=', 'squad_member.squad_id')
                    ->join('roles', 'roles.id', '=', 'squad_role.role_id')
                    ->join('squads', 'squads.id', '=', 'squad_member.squad_id')
                    ->whereIn('squad_member.user_id', $corpUserIds)
                    ->select('squad_member.user_id', 'roles.title', 'squads.name as squad_name')
                    ->get();
                foreach ($rows as $r) {
                    $uid = (int) $r->user_id;
                    $title = (string) $r->title;
                    // First squad to grant a title owns the "via squad" label.
                    if (!isset($byUser[$uid][$title])) {
                        $byUser[$uid][$title] = ['name' => $title, 'via_squad' => (string) $r->squad_name];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] CorpAccessService squad-role query failed: ' . $e->getMessage());
        }

        // Direct grants — only titles not already attributed to a squad.
        try {
            if (Schema::hasTable('role_user')) {
                $rows = DB::table('role_user')
                    ->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->whereIn('role_user.user_id', $corpUserIds)
                    ->select('role_user.user_id', 'roles.title')
                    ->get();
                foreach ($rows as $r) {
                    $uid = (int) $r->user_id;
                    $title = (string) $r->title;
                    if (!isset($byUser[$uid][$title])) {
                        $byUser[$uid][$title] = ['name' => $title, 'via_squad' => null];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] CorpAccessService direct-role query failed: ' . $e->getMessage());
        }

        // Reindex each account's roles to a plain list, roles alphabetical.
        return array_map(function ($roles) {
            ksort($roles);
            return array_values($roles);
        }, $byUser);
    }

    /** Accounts (corp-scoped) that hold Director in-game, with the holding char names. */
    private function ingameDirectorUsers(int $corporationId): array
    {
        if (!Schema::hasTable('corporation_roles') || !Schema::hasTable('refresh_tokens')) {
            return [];
        }
        try {
            $rows = DB::table('corporation_roles')
                ->join('refresh_tokens', function ($j) {
                    $j->on('refresh_tokens.character_id', '=', 'corporation_roles.character_id')
                      ->whereNull('refresh_tokens.deleted_at');
                })
                ->where('corporation_roles.corporation_id', $corporationId)
                ->where('corporation_roles.type', 'roles')
                ->where('corporation_roles.role', 'Director')
                ->select(['refresh_tokens.user_id', 'corporation_roles.character_id'])
                ->get();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] CorpAccessService director query failed: ' . $e->getMessage());
            return [];
        }

        $charIds = $rows->pluck('character_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        $charNames = !empty($charIds)
            ? app(NameResolutionService::class)->getCharacterNamesWithFallback($charIds)
            : [];

        $byUser = [];
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            $cid = (int) $r->character_id;
            $byUser[$uid][] = $charNames[$cid] ?? ('#' . $cid);
        }
        return $byUser;
    }

    /**
     * Characters tracked in the corp (member tracking) that carry no SeAT
     * token — i.e. no SeAT account at all. Returns [['character_id'=>, 'name'=>]]
     * name-sorted. Empty without member tracking or refresh_tokens (can't tell
     * registered from not).
     */
    private function unregisteredCharacters(int $corporationId): array
    {
        if (!Schema::hasTable('corporation_member_trackings') || !Schema::hasTable('refresh_tokens')) {
            return [];
        }
        try {
            $charIds = DB::table('corporation_member_trackings as m')
                ->leftJoin('refresh_tokens as rt', function ($j) {
                    $j->on('rt.character_id', '=', 'm.character_id')->whereNull('rt.deleted_at');
                })
                ->where('m.corporation_id', $corporationId)
                ->whereNull('rt.character_id')
                ->distinct()
                ->pluck('m.character_id')->map(fn ($i) => (int) $i)->values()->all();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] CorpAccessService unregistered query failed: ' . $e->getMessage());
            return [];
        }
        if (empty($charIds)) {
            return [];
        }
        $names = app(NameResolutionService::class)->getCharacterNamesWithFallback($charIds);
        $out = [];
        foreach ($charIds as $cid) {
            $out[] = ['character_id' => $cid, 'name' => $names[$cid] ?? ('#' . $cid)];
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Corp accounts with a linked Discord identity via seat-connector. The
     * seat_connector_users row is the account<->Discord link (keyed on user_id,
     * connector_type = discord); a set_id on it does NOT exist (roles live in
     * the polymorphic set_entity pivot), so linkage is just presence of a row.
     */
    private function discordLinkedUsers(array $corpUserIds): array
    {
        if (empty($corpUserIds) || !Schema::hasTable('seat_connector_users')) {
            return [];
        }
        try {
            $query = DB::table('seat_connector_users')->whereIn('user_id', $corpUserIds);
            if (Schema::hasColumn('seat_connector_users', 'connector_type')) {
                $query->where('connector_type', 'discord');
            }
            return $query->pluck('user_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] CorpAccessService discord-linked query failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Every Discord role each LINKED corp account holds, each flagged sensitive
     * or not. A user's effective roles come from set_entity across several
     * entity types (user / corp / alliance / role / squad / public), so we reuse
     * SeatConnectorService's resolver (cached per user, 10m). Returns
     * [user_id => ['roles' => [['name'=>, 'sensitive'=>bool]], 'sensitive_count' => int]].
     * Accounts with zero resolved roles are omitted.
     */
    private function discordRoleHolders(array $linkedUserIds, array $sensitiveRoleIds): array
    {
        if (empty($linkedUserIds)) {
            return [];
        }
        $sensitiveLookup = array_flip($sensitiveRoleIds);
        $byUser = [];
        foreach ($linkedUserIds as $uid) {
            $identity = $this->connector->getIdentityForUser((int) $uid);
            $roles = [];
            $sensitiveCount = 0;
            foreach (($identity['roles'] ?? []) as $role) {
                $rid = isset($role['role_id']) && $role['role_id'] !== null ? (string) $role['role_id'] : null;
                $isSensitive = $rid !== null && isset($sensitiveLookup[$rid]);
                if ($isSensitive) {
                    $sensitiveCount++;
                }
                $roles[] = [
                    'name'      => (string) ($role['name'] ?? $rid ?? ''),
                    'sensitive' => $isSensitive,
                    'source'    => $role['source'] ?? null,
                ];
            }
            if (empty($roles)) {
                continue;
            }
            // Sensitive roles first so the red badges lead each row.
            usort($roles, fn ($a, $b) => ($b['sensitive'] <=> $a['sensitive']) ?: strcmp($a['name'], $b['name']));
            $byUser[(int) $uid] = ['roles' => $roles, 'sensitive_count' => $sensitiveCount];
        }
        return $byUser;
    }

    /** classifier category per account for this corp. */
    private function categoryByUser(int $corporationId): array
    {
        try {
            return PlayerClassification::forCorporation($corporationId)
                ->get(['user_id', 'category'])
                ->pluck('category', 'user_id')
                ->map(fn ($c) => (string) $c)
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * User ids granted an ACL permission title, via EITHER path SeAT honours:
     * a role assigned directly (role_user) OR a role carried by a squad the
     * user belongs to (squad_role -> squad_member). Superuser is not folded in.
     * The squad path matters on installs that grant permissions through squads —
     * without it, a squad-permissioned HR director would be invisible here.
     */
    private function permissionUserIds(string $title): array
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('permission_role')) {
            return [];
        }
        $ids = [];
        try {
            if (Schema::hasTable('role_user')) {
                $ids = DB::table('permissions')
                    ->join('permission_role', 'permission_role.permission_id', '=', 'permissions.id')
                    ->join('role_user', 'role_user.role_id', '=', 'permission_role.role_id')
                    ->where('permissions.title', $title)
                    ->pluck('role_user.user_id')->map(fn ($i) => (int) $i)->all();
            }
            if (Schema::hasTable('squad_role') && Schema::hasTable('squad_member')) {
                $viaSquad = DB::table('permissions')
                    ->join('permission_role', 'permission_role.permission_id', '=', 'permissions.id')
                    ->join('squad_role', 'squad_role.role_id', '=', 'permission_role.role_id')
                    ->join('squad_member', 'squad_member.squad_id', '=', 'squad_role.squad_id')
                    ->where('permissions.title', $title)
                    ->pluck('squad_member.user_id')->map(fn ($i) => (int) $i)->all();
                $ids = array_merge($ids, $viaSquad);
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] CorpAccessService permission query failed: ' . $e->getMessage());
        }
        return array_values(array_unique($ids));
    }

    private function emptySeat(int $memberTotal): array
    {
        return [
            'account_count' => 0, 'member_total' => $memberTotal, 'registered_char_count' => 0, 'unregistered_count' => $memberTotal,
            'superuser_count' => 0, 'hr_admin_count' => 0, 'hr_director_count' => 0,
            'ingame_director_count' => 0, 'seat_role_count' => 0, 'squad_role_count' => 0, 'direct_role_count' => 0,
            'elevated_rows' => [], 'elevated_count' => 0,
        ];
    }

    private function emptyDiscord(): array
    {
        return [
            'available' => $this->connector->isAvailable(), 'configured' => !empty($this->connector->sensitiveRoleIds()),
            'linked_count' => 0, 'unlinked_count' => 0, 'unlinked_rows' => [], 'role_rows' => [], 'sensitive_count' => 0,
        ];
    }
}

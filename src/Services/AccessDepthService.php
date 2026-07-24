<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "How deep does this person's access run?" — a consolidated read of a
 * player's IN-GAME corp roles/titles and their SeAT account access
 * (roles + permissions), with the most sensitive access flagged and a
 * set of off-balance indicators where in-game and SeAT access don't line
 * up. Pure read-only over SeAT-synced tables; no ESI.
 *
 *   In-game side   -> corporation_roles + corporation_*_titles
 *                     (via CharacterTitleService), corp-wide director token.
 *   SeAT side      -> users.admin (superuser) + role_user/permission_role/
 *                     permissions (the SeAT ACL).
 *
 * Everything degrades to empty/zero when a table is missing, so the
 * panel never errors on a partial install.
 */
class AccessDepthService
{
    /**
     * In-game corp roles that confer real power (asset, wallet, member, or
     * structure control). Director is the apex. Mirrors the high-impact set
     * the purge notification strips, extended with the other sensitive roles.
     */
    private const CRITICAL_INGAME_ROLES = [
        'Director', 'Personnel_Manager', 'Accountant', 'Junior_Accountant',
        'Security_Officer', 'Diplomat', 'Auditor', 'Station_Manager',
        'Factory_Manager', 'Config_Equipment', 'Config_Starbase_Equipment',
        'Trader', 'Rent_Office', 'Rent_Factory_Facility', 'Rent_Research_Facility',
    ];

    /**
     * Build the full access-depth picture for a player.
     *
     * @param array<int> $characterIds the player's characters in this corp
     * @param int        $corporationId
     * @param int|null   $userId        the SeAT user (null = unregistered player)
     * @param string|null $category     classifier category (active/at_risk/inactive/dead_weight) if known
     */
    public function forPlayer(array $characterIds, int $corporationId, ?int $userId, ?string $category = null): array
    {
        // Resolve the classifier category ourselves when the caller didn't
        // pass it, so the off-balance "dormant + deep access" flag works
        // without extra controller wiring.
        if ($category === null && $userId !== null && $userId > 0 && Schema::hasTable('hr_manager_player_classifications')) {
            $category = DB::table('hr_manager_player_classifications')
                ->where('user_id', $userId)
                ->where('corporation_id', $corporationId)
                ->value('category');
            $category = $category !== null ? (string) $category : null;
        }

        $ingame = $this->ingameAccess($characterIds, $corporationId);
        $seat   = $this->seatAccess($userId);
        $wallet = $this->directorWalletExercise(
            $characterIds,
            $corporationId,
            $ingame['is_director'] || $ingame['critical_count'] > 0
        );
        $flags  = $this->offBalanceFlags($ingame, $seat, $wallet, $characterIds, $userId, $category);

        return [
            'ingame'        => $ingame,
            'seat'          => $seat,
            'wallet'        => $wallet,
            'flags'         => $flags,
            'has_anything'  => $ingame['has_anything'] || $seat['has_account'],
        ];
    }

    /**
     * In-game roles + titles across the player's characters, with the
     * critical roles separated out.
     */
    private function ingameAccess(array $characterIds, int $corporationId): array
    {
        $snapshot = app(CharacterTitleService::class)->snapshotForUser($characterIds, $corporationId);
        $roles = $snapshot['roles'] ?? [];

        $critical = array_values(array_filter($roles, fn ($r) => in_array($r, self::CRITICAL_INGAME_ROLES, true)));
        $normal   = array_values(array_filter($roles, fn ($r) => !in_array($r, self::CRITICAL_INGAME_ROLES, true)));

        return [
            'roles'          => $roles,
            'critical_roles' => $critical,
            'normal_roles'   => $normal,
            'titles'         => $snapshot['titles'] ?? [],
            'is_director'    => in_array('Director', $roles, true),
            'role_count'     => count($roles),
            'critical_count' => count($critical),
            'title_count'    => count($snapshot['titles'] ?? []),
            'has_anything'   => $snapshot['has_anything'] ?? false,
            // Per-character breakdown: in-game roles + titles are held PER
            // CHARACTER (EVE kicks + strips roles one character at a time), so
            // the aggregated lists above aren't enough to act on. Each entry is
            // one of the player's characters that holds something in-game.
            'by_character'   => $this->buildPerChar($snapshot),
        ];
    }

    /**
     * Public per-character in-game access for a set of characters, for callers
     * outside the full forPlayer() picture (e.g. the Purge board's kick list).
     * Each entry: {character_id, name, roles, critical_roles, titles, is_director}.
     * Only characters that actually hold a role or title are returned.
     *
     * @param array<int> $characterIds
     */
    public function perCharacterIngameAccess(array $characterIds, int $corporationId): array
    {
        $snapshot = app(CharacterTitleService::class)->snapshotForUser($characterIds, $corporationId);
        return $this->buildPerChar($snapshot);
    }

    /**
     * Turn CharacterTitleService's by_character map into a richer per-character
     * list (named, critical-flagged). Characters with nothing in-game are
     * dropped so the UI only shows alts that need action.
     */
    private function buildPerChar(array $snapshot): array
    {
        $out = [];
        foreach ($snapshot['by_character'] ?? [] as $charId => $snap) {
            $charRoles = array_values($snap['roles'] ?? []);
            $titles    = $snap['titles'] ?? [];
            if (empty($charRoles) && empty($titles)) {
                continue;
            }
            $out[] = [
                'character_id'   => (int) $charId,
                'name'           => $this->characterName((int) $charId),
                'roles'          => $charRoles,
                'critical_roles' => array_values(array_filter($charRoles, fn ($r) => in_array($r, self::CRITICAL_INGAME_ROLES, true))),
                'titles'         => $titles,
                'is_director'    => in_array('Director', $charRoles, true),
            ];
        }
        // Most-privileged characters first (most criticals, then most roles).
        usort($out, fn ($a, $b) => (count($b['critical_roles']) <=> count($a['critical_roles']))
            ?: (count($b['roles']) <=> count($a['roles'])));
        return $out;
    }

    private function characterName(int $characterId): string
    {
        if (Schema::hasTable('character_infos')) {
            $name = DB::table('character_infos')->where('character_id', $characterId)->value('name');
            if ($name) {
                return (string) $name;
            }
        }
        return '#' . $characterId;
    }

    /**
     * SeAT account access: superuser flag, the SeAT roles the user holds,
     * and how many distinct permissions those roles grant relative to the
     * permissions configured on this install.
     */
    private function seatAccess(?int $userId): array
    {
        $empty = [
            'has_account'     => false,
            'is_superuser'    => false,
            'roles'           => [],
            'role_count'      => 0,
            'permission_count'=> 0,
            'install_total'   => 0,
            'depth_pct'       => 0,
            'by_scope'        => [],
            'critical_perms'  => [],
        ];

        if ($userId === null || $userId <= 0 || !Schema::hasTable('role_user') || !Schema::hasTable('roles')) {
            return $empty;
        }

        $isSuperuser = (bool) (DB::table('users')->where('id', $userId)->value('admin') ?? false);

        $roleIds = DB::table('role_user')->where('user_id', $userId)->pluck('role_id')->map(fn ($id) => (int) $id)->all();
        $roleTitles = empty($roleIds)
            ? []
            : DB::table('roles')->whereIn('id', $roleIds)->orderBy('title')->pluck('title')->map(fn ($t) => (string) $t)->all();

        // Distinct permission titles granted across those roles.
        $grantedTitles = [];
        if (!empty($roleIds) && Schema::hasTable('permission_role') && Schema::hasTable('permissions')) {
            $grantedTitles = DB::table('permission_role as pr')
                ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                ->whereIn('pr.role_id', $roleIds)
                ->distinct()
                ->pluck('p.title')
                ->map(fn ($t) => (string) $t)
                ->all();
        }

        // Denominator: distinct permissions configured anywhere on this install.
        $installTotal = Schema::hasTable('permissions')
            ? (int) DB::table('permissions')->distinct()->count('title')
            : 0;

        // Group granted permissions by scope (the part before the first dot)
        // so "corporation: 6, hr-manager: 4, global: 1" reads as a depth map.
        $byScope = [];
        foreach ($grantedTitles as $title) {
            $scope = str_contains($title, '.') ? explode('.', $title, 2)[0] : 'global';
            $byScope[$scope] = ($byScope[$scope] ?? 0) + 1;
        }
        arsort($byScope);

        // Critical SeAT permissions: superuser-grade access.
        $criticalPerms = array_values(array_filter(
            $grantedTitles,
            fn ($t) => $t === 'superuser' || str_contains($t, 'superuser')
        ));

        $grantedCount = count($grantedTitles);
        $depthPct = $isSuperuser
            ? 100
            : ($installTotal > 0 ? (int) round(min($grantedCount, $installTotal) / $installTotal * 100) : 0);

        return [
            'has_account'      => true,
            'is_superuser'     => $isSuperuser,
            'roles'            => $roleTitles,
            'role_count'       => count($roleTitles),
            'permission_count' => $grantedCount,
            'install_total'    => $installTotal,
            'depth_pct'        => $depthPct,
            'by_scope'         => $byScope,
            'critical_perms'   => $criticalPerms,
        ];
    }

    /**
     * Off-balance indicators: where in-game and SeAT access don't line up,
     * or where deep access sits on a risky account. These are heuristics
     * (operator judgement still rules) but each points at a real blind spot.
     *
     * @return array<int, array{key:string, severity:string}>
     */
    private function offBalanceFlags(array $ingame, array $seat, array $wallet, array $characterIds, ?int $userId, ?string $category): array
    {
        $flags = [];
        $deepSeat = $seat['is_superuser'] || $seat['depth_pct'] >= 50;
        $hasCriticalIngame = $ingame['critical_count'] > 0;
        $isDormant = in_array($category, ['at_risk', 'inactive', 'dead_weight'], true);

        // 1. Deep access on a dormant account = attack surface.
        if (($hasCriticalIngame || $deepSeat) && $isDormant) {
            $flags[] = ['key' => 'dormant_critical_access', 'severity' => 'high'];
        }

        // 2. Critical in-game role held by a character with no SeAT token
        //    (unregistered) = power with no oversight.
        if ($hasCriticalIngame && $this->hasUnregisteredCharacter($characterIds)) {
            $flags[] = ['key' => 'unregistered_critical_ingame', 'severity' => 'high'];
        }

        // 3. Director in-game but the whole player is unregistered in SeAT.
        if ($ingame['is_director'] && ($userId === null || $userId <= 0)) {
            $flags[] = ['key' => 'unregistered_director', 'severity' => 'high'];
        }

        // 4. Deep SeAT access but no critical in-game role = SeAT admin who's
        //    an in-game nobody. Usually legitimate (a tooling admin); surfaced
        //    so it's a conscious decision, not a surprise.
        if ($deepSeat && !$hasCriticalIngame) {
            $flags[] = ['key' => 'seat_power_low_ingame', 'severity' => 'info'];
        }

        // 5. Dormant member whose director characters are STILL moving corp ISK
        //    (best-effort attribution) = disengaged elsewhere yet actively
        //    exercising wallet access. The sharpest version of flag #1.
        if ($isDormant && ($wallet['attributed_isk'] ?? 0) > 0) {
            $flags[] = ['key' => 'dormant_director_moving_isk', 'severity' => 'high'];
        }

        return $flags;
    }

    /**
     * How much corp ISK this player's director-grade characters have moved
     * over the trailing 3 months, via CWM's best-effort director attribution
     * (routed through MC's PluginBridge, read-only + optional). Lets the access
     * panel show whether deep wallet access is actually being EXERCISED, not
     * just held. Returns available=false (and zeroes) for line members (we
     * don't probe the wallet attribution for non-privileged players) and
     * whenever Manager Core or Corp Wallet Manager isn't installed.
     *
     * @param array<int> $characterIds
     * @return array{available:bool, attributed_isk:float, action_count:int}
     */
    private function directorWalletExercise(array $characterIds, int $corporationId, bool $privileged): array
    {
        $none = ['available' => false, 'attributed_isk' => 0.0, 'action_count' => 0];

        // Only meaningful for characters that hold wallet-capable roles; never
        // probe the corp wallet attribution for ordinary line members.
        $characterIds = array_values(array_filter(array_map('intval', $characterIds), fn ($id) => $id > 0));
        if (!$privileged || empty($characterIds)) {
            return $none;
        }

        $result = app(CrossPluginDataService::class)->getDirectorAttribution($corporationId, 3, 50_000_000);
        if (!($result['available'] ?? false)) {
            return $none;
        }

        $data = $result['data'] ?? null;
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (!is_array($data)) {
            return $none;
        }

        // CWM returns ['directors' => [['character_id','count','total_amount'], ...]].
        // Tolerate the flat / ['rows'] / ['attributions'] shapes too, mirroring
        // ClassifierService's normalization.
        $rows = $data['directors'] ?? $data['rows'] ?? $data['attributions'] ?? $data;
        if (!is_array($rows)) {
            return $none;
        }

        $charSet = array_flip($characterIds);
        $isk = 0.0;
        $actions = 0;
        foreach ($rows as $row) {
            $row = is_object($row) ? (array) $row : $row;
            if (!is_array($row)) {
                continue;
            }
            $cid = (int) ($row['character_id'] ?? $row['attributed_character_id'] ?? 0);
            if ($cid > 0 && isset($charSet[$cid])) {
                $isk     += (float) ($row['total_amount'] ?? $row['amount'] ?? 0);
                $actions += (int) ($row['count'] ?? 1);
            }
        }

        return [
            'available'      => true,
            'attributed_isk' => $isk,
            'action_count'   => $actions,
        ];
    }

    /**
     * True when any of the supplied characters lacks a live SeAT refresh
     * token (i.e. SeAT can't see them directly).
     */
    private function hasUnregisteredCharacter(array $characterIds): bool
    {
        $characterIds = array_values(array_filter(array_map('intval', $characterIds), fn ($id) => $id > 0));
        if (empty($characterIds) || !Schema::hasTable('refresh_tokens')) {
            return false;
        }
        $registered = DB::table('refresh_tokens')
            ->whereIn('character_id', $characterIds)
            ->whereNull('deleted_at')
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        return count($registered) < count($characterIds);
    }
}

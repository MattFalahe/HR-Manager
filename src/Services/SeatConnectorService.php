<?php

namespace HrManager\Services;

use HrManager\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve Discord identity + assigned roles for a SeAT user via the
 * warlof/seat-connector framework when present. Returns null/empty when
 * the framework isn't installed so callers can render a muted fallback
 * rather than crash.
 *
 * Schema reality (SeAT v5 warlof/seat-connector):
 *   - `seat_connector_users`  — the account<->Discord LINK: user_id,
 *     connector_type ('discord'), connector_id (the user's Discord snowflake),
 *     connector_name (their Discord username). One row per linked account.
 *     There is NO set_id here.
 *   - `seat_connector_sets`   — the roles: id, connector_type, connector_id
 *     (the Discord ROLE snowflake), name, is_public.
 *   - `seat_connector_set_entity` — polymorphic pivot granting a set to an
 *     entity: set_id + entity_type (model FQCN) + entity_id. A user's effective
 *     roles are the union of sets granted to entities they match (themselves,
 *     their corp / alliance, their SeAT roles, their squads) plus public sets.
 */
class SeatConnectorService
{
    private const IDENTITY_CACHE_PREFIX = 'hr-discord-identity-v1-';
    private const IDENTITY_CACHE_TTL    = 600; // 10 minutes

    public function isAvailable(): bool
    {
        return Schema::hasTable('seat_connector_users')
            && Schema::hasTable('seat_connector_sets');
    }

    /**
     * Force a cache miss for the next getIdentityForUser call. Member
     * profile refresh wires through here so admins can verify a Discord
     * role rebind without waiting 10 minutes.
     */
    public function bustCache(int $userId): void
    {
        Cache::forget(self::IDENTITY_CACHE_PREFIX . $userId);
    }

    /**
     * The SeAT Connector identity-page URL a member links their Discord from.
     * Precedence: the operator's seat_connector_base_url override (unusual
     * reverse-proxy setups), then the framework's named route when installed,
     * then a hand-built path. Shared by the apply flow and the public tracking
     * page's "link your Discord" nudge.
     */
    public function identitiesUrl(): string
    {
        $configured = trim((string) Setting::getValue(
            'seat_connector_base_url',
            config('hr-manager.recruitment.seat_connector_base_url', '')
        ));
        if ($configured !== '') {
            return rtrim($configured, '/') . '/seat-connector/identities';
        }

        if (\Illuminate\Support\Facades\Route::has('seat-connector.identities')) {
            return route('seat-connector.identities');
        }

        return url('/seat-connector/identities');
    }

    /**
     * @return array{
     *   available: bool,
     *   discord_username: ?string,
     *   connector_id: ?string,
     *   roles: array<int, array{role_id: ?string, name: string}>,
     *   reason?: string,
     * }
     */
    public function getIdentityForUser(int $userId): array
    {
        if (!$this->isAvailable()) {
            return $this->emptyIdentity('connector_absent');
        }

        // Cache the resolved identity for 10 min. Two DB queries + a
        // schema probe collapse to a single Redis read on the hot path.
        return Cache::remember(
            self::IDENTITY_CACHE_PREFIX . $userId,
            self::IDENTITY_CACHE_TTL,
            function () use ($userId) {
                try {
                    $userRow = $this->resolveUserRow($userId);
                    // No connector row = this account isn't linked to Discord.
                    // Report unavailable so callers show a "not linked" state —
                    // and don't resolve roles, since set-entity grants (corp /
                    // alliance / public) would otherwise list roles the pilot
                    // doesn't actually hold until they link.
                    if (!$userRow) {
                        return $this->emptyIdentity('user_unlinked');
                    }
                    $username = $this->resolveDisplayName($userRow);
                    $connectorId = $userRow->connector_id ?? null;
                    $roles = $this->rolesForUser($userId);
                } catch (\Throwable $e) {
                    Log::warning('[HR Manager] SeatConnectorService failed: ' . $e->getMessage(), [
                        'user_id' => $userId,
                    ]);
                    return $this->emptyIdentity('query_failed');
                }

                return [
                    'available'        => true,
                    'discord_username' => $username,
                    'connector_id'     => $connectorId !== null ? (string) $connectorId : null,
                    'roles'            => $roles,
                ];
            }
        );
    }

    /**
     * Convenience for the member profile: take a character_id, resolve its
     * SeAT user via refresh_tokens, return identity. Empty when char isn't
     * registered in SeAT.
     */
    public function getIdentityForCharacter(int $characterId): array
    {
        $userId = DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->whereNull('deleted_at')
            ->value('user_id');

        if (!$userId) {
            return $this->emptyIdentity('character_unregistered');
        }

        return $this->getIdentityForUser((int) $userId);
    }

    // -----------------------------------------------------------------

    private function resolveUserRow(int $userId)
    {
        // seat_connector_users may have multiple rows per user (one per
        // connector type / role assignment). Filter to Discord and pick
        // the most recent.
        $cols = Schema::getColumnListing('seat_connector_users');

        $query = DB::table('seat_connector_users')
            ->where('user_id', $userId);

        // seat_connector_users carries connector_type in SeAT v5; filter to
        // Discord so a user linked to multiple connectors resolves correctly.
        if (in_array('connector_type', $cols, true)) {
            $query->where('connector_type', 'discord');
        }

        return $query->orderByDesc('id')->first();
    }

    private function resolveDisplayName($userRow): ?string
    {
        if (!$userRow) {
            return null;
        }

        // Probe known column names from various seat-connector versions
        foreach (['connector_name', 'display_name', 'user_name', 'username', 'name'] as $col) {
            if (isset($userRow->{$col}) && $userRow->{$col} !== '' && $userRow->{$col} !== null) {
                return (string) $userRow->{$col};
            }
        }
        return null;
    }

    // Morph entity_type values stored in seat_connector_set_entity. SeAT uses
    // no morph map, so these are the model FQCNs as strings. A Discord "set"
    // (role) is granted to any of these entities; a user's effective roles are
    // the union of sets granted to the entities they match, plus public sets.
    private const ENTITY_USER        = 'Seat\\Web\\Models\\User';
    private const ENTITY_CORPORATION = 'Seat\\Eveapi\\Models\\Corporation\\CorporationInfo';
    private const ENTITY_ALLIANCE    = 'Seat\\Eveapi\\Models\\Alliances\\Alliance';
    private const ENTITY_ROLE        = 'Seat\\Web\\Models\\Acl\\Role';
    private const ENTITY_SQUAD       = 'Seat\\Web\\Models\\Squads\\Squad';

    /**
     * A user's effective Discord roles = every set granted to an entity the
     * user matches (themselves, their characters' corp / alliance, their SeAT
     * roles, their squads) plus public sets. Resolved from the polymorphic
     * seat_connector_set_entity pivot — the same access model the connector's
     * own driver applies. Defensive: any failure returns [] so a roles hiccup
     * never nukes the resolved username.
     */
    private function rolesForUser(int $userId): array
    {
        try {
            if (!Schema::hasTable('seat_connector_set_entity') || !Schema::hasTable('seat_connector_sets')) {
                return [];
            }

            // The user's characters -> their corp + alliance ids.
            $charIds = Schema::hasTable('refresh_tokens')
                ? DB::table('refresh_tokens')->where('user_id', $userId)->whereNull('deleted_at')
                    ->pluck('character_id')->map(fn ($i) => (int) $i)->all()
                : [];
            $corpIds = [];
            $allianceIds = [];
            if (!empty($charIds) && Schema::hasTable('character_affiliations')) {
                $aff = DB::table('character_affiliations')->whereIn('character_id', $charIds)
                    ->get(['corporation_id', 'alliance_id']);
                $corpIds     = $aff->pluck('corporation_id')->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
                $allianceIds = $aff->pluck('alliance_id')->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
            }
            $roleIds = Schema::hasTable('role_user')
                ? DB::table('role_user')->where('user_id', $userId)->pluck('role_id')->map(fn ($i) => (int) $i)->all()
                : [];
            $squadIds = Schema::hasTable('squad_member')
                ? DB::table('squad_member')->where('user_id', $userId)->pluck('squad_id')->map(fn ($i) => (int) $i)->all()
                : [];

            $entityMatches = [
                [self::ENTITY_USER,        [$userId]],
                [self::ENTITY_CORPORATION, $corpIds],
                [self::ENTITY_ALLIANCE,    $allianceIds],
                [self::ENTITY_ROLE,        $roleIds],
                [self::ENTITY_SQUAD,       $squadIds],
            ];
            $entityMatches = array_values(array_filter($entityMatches, fn ($m) => !empty($m[1])));

            // Names for the user's squads + SeAT roles, so a set granted through
            // one can name its source (the squad -> role -> Discord chain).
            $squadNames = (!empty($squadIds) && Schema::hasTable('squads'))
                ? DB::table('squads')->whereIn('id', $squadIds)->pluck('name', 'id')->toArray()
                : [];
            $roleTitles = (!empty($roleIds) && Schema::hasTable('roles'))
                ? DB::table('roles')->whereIn('id', $roleIds)->pluck('title', 'id')->toArray()
                : [];

            // For each set the user matches, record WHICH entity grants it. When
            // several do, the most specific wins (squad > role > corp > alliance
            // > user), so "tied to a squad" surfaces over a broad corp grant.
            $prio = [
                self::ENTITY_SQUAD => 0, self::ENTITY_ROLE => 1, self::ENTITY_CORPORATION => 2,
                self::ENTITY_ALLIANCE => 3, self::ENTITY_USER => 4,
            ];
            $setSources = [];  // set_id => ['type'=>, 'name'=>?, 'prio'=>]
            if (!empty($entityMatches)) {
                $entityRows = DB::table('seat_connector_set_entity')
                    ->where(function ($outer) use ($entityMatches) {
                        foreach ($entityMatches as [$type, $ids]) {
                            $outer->orWhere(function ($w) use ($type, $ids) {
                                $w->where('entity_type', $type)->whereIn('entity_id', $ids);
                            });
                        }
                    })
                    ->get(['set_id', 'entity_type', 'entity_id']);
                foreach ($entityRows as $er) {
                    $sid = (int) $er->set_id;
                    $etype = (string) $er->entity_type;
                    $eid = (int) $er->entity_id;
                    $p = $prio[$etype] ?? 9;
                    if (isset($setSources[$sid]) && $setSources[$sid]['prio'] <= $p) {
                        continue;
                    }
                    $src = match ($etype) {
                        self::ENTITY_SQUAD       => ['type' => 'squad',        'name' => $squadNames[$eid] ?? null],
                        self::ENTITY_ROLE        => ['type' => 'seat_role',    'name' => $roleTitles[$eid] ?? null],
                        self::ENTITY_CORPORATION => ['type' => 'corporation',  'name' => null],
                        self::ENTITY_ALLIANCE    => ['type' => 'alliance',     'name' => null],
                        self::ENTITY_USER        => ['type' => 'direct',       'name' => null],
                        default                  => ['type' => 'other',        'name' => null],
                    };
                    $src['prio'] = $p;
                    $setSources[$sid] = $src;
                }
            }

            // Public sets apply to every linked user (lowest-priority source).
            if (Schema::hasColumn('seat_connector_sets', 'is_public')) {
                $publicIds = DB::table('seat_connector_sets')
                    ->where('connector_type', 'discord')->where('is_public', true)
                    ->pluck('id')->map(fn ($i) => (int) $i)->all();
                foreach ($publicIds as $pid) {
                    if (!isset($setSources[(int) $pid])) {
                        $setSources[(int) $pid] = ['type' => 'public', 'name' => null, 'prio' => 5];
                    }
                }
            }

            $setIds = array_keys($setSources);
            if (empty($setIds)) {
                return [];
            }

            $rows = DB::table('seat_connector_sets')
                ->whereIn('id', $setIds)
                ->where('connector_type', 'discord')
                ->orderBy('name')
                ->get(['id', 'connector_id', 'name']);

            return $rows->map(function ($r) use ($setSources) {
                $src = $setSources[(int) $r->id] ?? null;
                return [
                    'role_id' => $r->connector_id !== null ? (string) $r->connector_id : null,
                    'name'    => (string) $r->name,
                    'source'  => $src ? ['type' => $src['type'], 'name' => $src['name']] : null,
                ];
            })->unique('role_id')->values()->all();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] SeatConnectorService::rolesForUser failed: ' . $e->getMessage(), ['user_id' => $userId]);
            return [];
        }
    }

    /**
     * Setting key for the operator-curated list of "sensitive" Discord role
     * ids (leadership / FC / wallet-and-structure access). Stored as a JSON
     * array of role-id strings under HR Manager settings.
     */
    public const SETTING_SENSITIVE_ROLES = 'sensitive_discord_roles';

    /**
     * Role ids the operator has flagged as sensitive. Empty when unconfigured.
     *
     * @return array<int, string>
     */
    public function sensitiveRoleIds(): array
    {
        $ids = json_decode((string) Setting::getValue(self::SETTING_SENSITIVE_ROLES, '[]'), true);

        return is_array($ids)
            ? array_values(array_filter(array_map('strval', $ids)))
            : [];
    }

    /**
     * Break a resolved Discord identity into an access-depth summary: how many
     * roles the account holds and which of them the operator flagged sensitive.
     * Pure read over already-resolved data — issues no queries beyond the one
     * cached settings fetch, so it's cheap to call per player.
     *
     * @param  array  $identity  Shape from getIdentityForUser()/forCharacter().
     * @return array{
     *   available: bool,
     *   configured: bool,
     *   total: int,
     *   sensitive_count: int,
     *   sensitive: array<int, array{role_id: ?string, name: string, sensitive: bool}>,
     *   roles: array<int, array{role_id: ?string, name: string, sensitive: bool}>,
     * }
     */
    public function accessDepthForIdentity(array $identity): array
    {
        $sensitiveIds = $this->sensitiveRoleIds();
        $roles = [];
        $sensitive = [];

        foreach (($identity['roles'] ?? []) as $role) {
            $rid = isset($role['role_id']) && $role['role_id'] !== null ? (string) $role['role_id'] : null;
            $isSensitive = $rid !== null && in_array($rid, $sensitiveIds, true);
            $entry = [
                'role_id'   => $rid,
                'name'      => (string) ($role['name'] ?? $rid ?? ''),
                'sensitive' => $isSensitive,
            ];
            $roles[] = $entry;
            if ($isSensitive) {
                $sensitive[] = $entry;
            }
        }

        return [
            'available'       => (bool) ($identity['available'] ?? false),
            'configured'      => !empty($sensitiveIds),
            'total'           => count($roles),
            'sensitive_count' => count($sensitive),
            'sensitive'       => $sensitive,
            'roles'           => $roles,
        ];
    }

    private function emptyIdentity(string $reason): array
    {
        return [
            'available'        => false,
            'discord_username' => null,
            'connector_id'     => null,
            'roles'            => [],
            'reason'           => $reason,
        ];
    }
}

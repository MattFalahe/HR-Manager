<?php

namespace HrManager\Services;

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
 * Schema reality: `seat_connector_users` has user_id + set_id + the user's
 * own Discord identifier; `seat_connector_sets` carries the role metadata
 * (name + connector_id snowflake). Some installs store the Discord display
 * name on the user row, some don't — we Schema::hasColumn before reading
 * to stay resilient across versions.
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
                    $username = $this->resolveDisplayName($userRow);
                    $connectorId = $userRow ? ($userRow->connector_id ?? null) : null;
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

        // Some schemas join the set table to filter by connector_type;
        // others store connector_type on the user row. Use whichever is
        // available so we don't lose Discord-typed rows.
        if (in_array('connector_type', $cols, true)) {
            $query->where('connector_type', 'discord');
        } else {
            $query->leftJoin('seat_connector_sets', 'seat_connector_sets.id', '=', 'seat_connector_users.set_id')
                ->where(function ($q) {
                    $q->where('seat_connector_sets.connector_type', 'discord')
                      ->orWhereNull('seat_connector_sets.connector_type');
                })
                ->select('seat_connector_users.*');
        }

        return $query->orderByDesc('seat_connector_users.id')->first();
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

    private function rolesForUser(int $userId): array
    {
        $rows = DB::table('seat_connector_users')
            ->join('seat_connector_sets', 'seat_connector_sets.id', '=', 'seat_connector_users.set_id')
            ->where('seat_connector_users.user_id', $userId)
            ->where('seat_connector_sets.connector_type', 'discord')
            ->orderBy('seat_connector_sets.name')
            ->get([
                'seat_connector_sets.connector_id',
                'seat_connector_sets.name',
            ]);

        return $rows->map(fn($r) => [
            'role_id' => $r->connector_id !== null ? (string) $r->connector_id : null,
            'name'    => (string) $r->name,
        ])->all();
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

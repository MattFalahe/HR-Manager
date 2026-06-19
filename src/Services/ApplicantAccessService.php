<?php

namespace HrManager\Services;

use HrManager\Models\Application;
use HrManager\Models\PlayerIdentity;
use HrManager\Models\RecruiterAccessGrant;
use HrManager\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary-SeAT-role grant manager for recruiters handling applications.
 *
 * Architecture:
 *
 *   Recruiter joins handler list on application N
 *     │
 *     ▼
 *   grant() runs:
 *     1. Resolve applicant's character IDs (main + alts via PlayerIdentity)
 *     2. Find or create SeAT role "hr-mgr:apply:N"
 *     3. For each permission in the configured set, insert a
 *        permission_role pivot row scoped to those character IDs
 *        via the filters JSON column ({"character":[{"id":X}, ...]})
 *     4. Insert role_user pivot row for the recruiter
 *     5. Insert hr_manager_recruiter_access_grants row for audit
 *
 *   Recruiter leaves handler list / app closes / grant expires
 *     │
 *     ▼
 *   revoke() runs:
 *     1. Delete role_user pivot row for that (user, role)
 *     2. If zero recruiters remain attached → delete role entirely
 *        (cascades permission_role)
 *     3. Mark grant.revoked_at + revoke_reason
 *
 * Safety guarantees (also documented in feedback memory):
 *   - Strict namespace prefix `hr-mgr:apply:` — we never delete or
 *     modify a SeAT role that doesn't match this prefix
 *   - Detach-by-pivot, never delete-by-role-id-alone, so other roles
 *     a recruiter has are NEVER touched
 *   - Only delete the role itself when zero users remain attached
 *   - Every grant + revoke logs to hr_manager_recruiter_access_grants
 *     with reason for the audit trail
 *   - Daily cron sweeper backstop catches any expired grant where a
 *     lifecycle hook missed the revoke
 */
class ApplicantAccessService
{
    public const ROLE_TITLE_PREFIX = 'hr-mgr:apply:';

    // Setting keys
    public const SETTING_ENABLED      = 'recruiter_access_enabled';
    public const SETTING_PERMISSIONS  = 'recruiter_access_permissions';
    public const SETTING_MAX_DURATION = 'recruiter_access_max_duration_days';
    public const SETTING_INCLUDE_ALTS = 'recruiter_access_include_alts';

    // The full menu of SeAT character permissions (taken from
    // web/src/Config/package.character.menu.php).
    public const AVAILABLE_PERMISSIONS = [
        'character.asset', 'character.calendar', 'character.contact',
        'character.contract', 'character.fitting', 'character.blueprint',
        'character.industry', 'character.intel', 'character.killmail',
        'character.mail', 'character.market', 'character.mining',
        'character.notification', 'character.planetary', 'character.research',
        'character.sheet', 'character.skill', 'character.standing',
        'character.journal', 'character.transactions', 'character.loyalty_points',
    ];

    // Sensible default — the "due diligence" set most recruiters need.
    // SeAT splits wallet into 'journal' + 'transactions' — both included
    // to give a complete wallet picture. No 'character.wallet' literal
    // because that's not actually a SeAT permission key.
    public const DEFAULT_PERMISSIONS = [
        'character.sheet',
        'character.journal',
        'character.transactions',
        'character.asset',
        'character.mail',
        'character.skill',
    ];

    public const DEFAULT_MAX_DURATION_DAYS = 7;

    public function __construct(
        private PlayerIdentityResolver $playerIdentity
    ) {}

    // -----------------------------------------------------------------
    // Public lifecycle hooks
    // -----------------------------------------------------------------

    /**
     * Grant a recruiter temporary access to the applicant's character
     * data. Idempotent — re-running for the same (application, user)
     * returns the existing grant (refreshed if expired).
     *
     * Returns the grant row, or null when:
     *   - The feature is disabled in settings
     *   - SeAT's ACL tables are missing (defensive — shouldn't happen)
     *   - The applicant has no resolvable character IDs
     */
    public function grant(Application $application, int $recruiterUserId): ?RecruiterAccessGrant
    {
        if (!$this->isFeatureEnabled()) {
            return null;
        }
        if (!$this->seatAclAvailable()) {
            return null;
        }

        try {
            $characterIds = $this->resolveCharacterIds($application);
            if (empty($characterIds)) {
                Log::info('[HR Manager] grant: no characters resolved for application', [
                    'application_id' => $application->id,
                ]);
                return null;
            }

            $permissions = $this->resolvePermissions();
            $duration    = $this->resolveMaxDurationDays();
            $expiresAt   = now()->addDays($duration);

            return DB::transaction(function () use ($application, $recruiterUserId, $characterIds, $permissions, $expiresAt) {
                $roleId = $this->findOrCreateRole($application, $characterIds, $permissions);
                $this->attachUserToRole($roleId, $recruiterUserId);

                return RecruiterAccessGrant::updateOrCreate(
                    [
                        'application_id' => $application->id,
                        'user_id'        => $recruiterUserId,
                    ],
                    [
                        'role_id'        => $roleId,
                        'character_ids'  => array_values($characterIds),
                        'permission_set' => $permissions,
                        'granted_at'     => now(),
                        'expires_at'     => $expiresAt,
                        'revoked_at'     => null,
                        'revoke_reason'  => null,
                    ]
                );
            });
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] grant failed: ' . $e->getMessage(), [
                'application_id' => $application->id,
                'user_id'        => $recruiterUserId,
            ]);
            return null;
        }
    }

    /**
     * Grant access to EVERY current handler of every open application.
     * Called when the feature is toggled on so existing handlers get
     * access retroactively (the per-join grant only fires going forward).
     * Returns the number of grants created/refreshed.
     */
    public function grantAllCurrentHandlers(): int
    {
        if (!$this->isFeatureEnabled() || !$this->seatAclAvailable()) {
            return 0;
        }

        $count = 0;
        Application::whereIn('status', ['applied', 'under_review', 'interview'])
            ->whereNull('deleted_at')
            ->with('handlers')
            ->chunk(50, function ($apps) use (&$count) {
                foreach ($apps as $app) {
                    foreach ($app->handlers as $handler) {
                        if ($this->grant($app, (int) $handler->user_id)) {
                            $count++;
                        }
                    }
                }
            });

        return $count;
    }

    /**
     * Revoke a specific (application, user) grant. Detaches the user
     * from the role, deletes the role if no users remain. Idempotent —
     * already-revoked grants no-op.
     */
    public function revoke(int $applicationId, int $recruiterUserId, string $reason = 'manual'): bool
    {
        $grant = RecruiterAccessGrant::where('application_id', $applicationId)
            ->where('user_id', $recruiterUserId)
            ->whereNull('revoked_at')
            ->first();
        if (!$grant) {
            return false;
        }
        return $this->revokeGrant($grant, $reason);
    }

    /**
     * Revoke ALL grants on an application. Called when the application
     * closes (accepted / rejected / withdrawn) so every handler loses
     * access at once.
     */
    public function revokeAllForApplication(int $applicationId, string $reason = 'application_closed'): int
    {
        $count = 0;
        $grants = RecruiterAccessGrant::where('application_id', $applicationId)
            ->whereNull('revoked_at')
            ->get();
        foreach ($grants as $grant) {
            if ($this->revokeGrant($grant, $reason)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Sweep expired grants. Called from the daily cron — backstop for
     * any case where a lifecycle hook missed the revoke. Returns count
     * of grants forcibly revoked.
     */
    public function sweepExpired(): int
    {
        $count = 0;
        RecruiterAccessGrant::expired()->chunk(50, function ($grants) use (&$count) {
            foreach ($grants as $grant) {
                if ($this->revokeGrant($grant, 'expired_sweep')) {
                    $count++;
                }
            }
        });
        return $count;
    }

    /**
     * Active grants for a user (for the "your current access" panel).
     */
    public function activeGrantsForUser(int $userId)
    {
        return RecruiterAccessGrant::active()
            ->where('user_id', $userId)
            ->where('expires_at', '>=', now())
            ->orderBy('expires_at')
            ->get();
    }

    // -----------------------------------------------------------------
    // Settings resolvers
    // -----------------------------------------------------------------

    public function isFeatureEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_ENABLED, false);
    }

    public function shouldIncludeAlts(): bool
    {
        return (bool) Setting::getValue(self::SETTING_INCLUDE_ALTS, true);
    }

    public function resolvePermissions(): array
    {
        $raw = Setting::getValue(self::SETTING_PERMISSIONS, null);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return array_values(array_filter($decoded, 'is_string'));
        }
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_string'));
        }
        return self::DEFAULT_PERMISSIONS;
    }

    public function resolveMaxDurationDays(): int
    {
        $days = (int) Setting::getValue(self::SETTING_MAX_DURATION, self::DEFAULT_MAX_DURATION_DAYS);
        return max(1, min(30, $days));
    }

    // -----------------------------------------------------------------
    // SeAT ACL plumbing
    // -----------------------------------------------------------------

    private function seatAclAvailable(): bool
    {
        return Schema::hasTable('roles')
            && Schema::hasTable('role_user')
            && Schema::hasTable('permissions')
            && Schema::hasTable('permission_role');
    }

    /**
     * Resolve applicant's character IDs. Main character always
     * included; alts pulled via PlayerIdentity when settings allow.
     */
    private function resolveCharacterIds(Application $application): array
    {
        $ids = [(int) $application->character_id];

        if (!$this->shouldIncludeAlts()) {
            return $ids;
        }

        try {
            $identity = $this->playerIdentity->forCharacter((int) $application->character_id);
            if ($identity instanceof PlayerIdentity) {
                $altIds = DB::table('hr_manager_character_identity_mappings')
                    ->where('player_identity_id', $identity->id)
                    ->whereNull('effective_to')
                    ->pluck('character_id')
                    ->map(fn($id) => (int) $id)
                    ->all();
                $ids = array_merge($ids, $altIds);
            }
        } catch (\Throwable $e) {
            Log::info('[HR Manager] PlayerIdentity alt resolution failed: ' . $e->getMessage());
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Find existing role for this application, else create it +
     * attach the configured permission set with filters scoped to
     * the applicant's character IDs. Returns the role.id.
     */
    private function findOrCreateRole(Application $application, array $characterIds, array $permissions): int
    {
        $title = self::ROLE_TITLE_PREFIX . $application->id;
        $row = DB::table('roles')->where('title', $title)->first();
        if ($row) {
            // Refresh affiliation in case the alt list grew since the
            // first grant.
            $this->refreshRolePermissions((int) $row->id, $characterIds, $permissions);
            return (int) $row->id;
        }

        $roleId = DB::table('roles')->insertGetId([
            'title'       => $title,
            'description' => sprintf(
                'Temporary HR Manager grant for application #%d. Auto-managed; do not modify manually.',
                $application->id
            ),
        ]);
        $this->refreshRolePermissions($roleId, $characterIds, $permissions);
        return $roleId;
    }

    /**
     * Replace the role's permission_role attachments with the
     * configured set, all scoped to the same character_ids filter.
     * Idempotent — drop and re-insert keeps the role aligned with
     * current settings + applicant identity state.
     */
    private function refreshRolePermissions(int $roleId, array $characterIds, array $permissions): void
    {
        $filters = $this->buildFilters($characterIds);
        $filtersJson = json_encode($filters, JSON_UNESCAPED_SLASHES);

        DB::table('permission_role')->where('role_id', $roleId)->delete();

        foreach ($permissions as $permissionTitle) {
            $permissionId = $this->findOrCreatePermissionId($permissionTitle);
            if (!$permissionId) continue;

            DB::table('permission_role')->insert([
                'permission_id' => $permissionId,
                'role_id'       => $roleId,
                'not'           => 0,
                'filters'       => $filtersJson,
            ]);
        }
    }

    private function findOrCreatePermissionId(string $title): ?int
    {
        $row = DB::table('permissions')->where('title', $title)->first();
        if ($row) return (int) $row->id;

        try {
            return (int) DB::table('permissions')->insertGetId(['title' => $title]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] failed to create permission row for ' . $title . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build the filters JSON SeAT's policy layer reads. Shape per
     * AbstractEntityPolicy::isGrantedByFilter():
     *   {"character": [{"id": 12345}, {"id": 67890}]}
     */
    private function buildFilters(array $characterIds): array
    {
        return [
            'character' => array_map(fn($id) => ['id' => (int) $id], array_values($characterIds)),
        ];
    }

    private function attachUserToRole(int $roleId, int $userId): void
    {
        // Composite-PK table — exists() guard avoids primary key
        // collision on re-grant.
        $exists = DB::table('role_user')
            ->where('role_id', $roleId)
            ->where('user_id', $userId)
            ->exists();
        if ($exists) return;

        DB::table('role_user')->insert([
            'role_id' => $roleId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Internal revoke step — detach user from role, mark grant, drop
     * role if abandoned. Strict namespace guard prevents accidental
     * deletion of operator-managed roles even if grant data has been
     * corrupted.
     */
    private function revokeGrant(RecruiterAccessGrant $grant, string $reason): bool
    {
        return DB::transaction(function () use ($grant, $reason) {
            $roleId = (int) $grant->role_id;
            if ($roleId > 0) {
                $role = DB::table('roles')->where('id', $roleId)->first();
                if ($role && str_starts_with((string) $role->title, self::ROLE_TITLE_PREFIX)) {
                    DB::table('role_user')
                        ->where('role_id', $roleId)
                        ->where('user_id', $grant->user_id)
                        ->delete();

                    $remainingUsers = DB::table('role_user')
                        ->where('role_id', $roleId)
                        ->count();
                    if ($remainingUsers === 0) {
                        DB::table('permission_role')->where('role_id', $roleId)->delete();
                        DB::table('roles')->where('id', $roleId)->delete();
                    }
                }
            }

            $grant->update([
                'revoked_at'    => now(),
                'revoke_reason' => $reason,
            ]);

            return true;
        });
    }
}

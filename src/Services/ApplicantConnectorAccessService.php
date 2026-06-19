<?php

namespace HrManager\Services;

use HrManager\Models\ApplicantConnectorGrant;
use HrManager\Models\Application;
use HrManager\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Mints a temporary `seat-connector.view` SeAT permission for an
 * applicant so they can open /seat-connector/identities and link their
 * Discord while their application is open. The mirror image of
 * ApplicantAccessService (recruiter -> applicant data), pointed the
 * other way: applicant -> Connector linking permission.
 *
 * Architecture:
 *
 *   Application submitted (feature on + Connector installed)
 *     |
 *     v
 *   grant() runs:
 *     1. Resolve the applicant's SeAT user (character_id -> refresh_tokens)
 *     2. Find or create SeAT role "hr-mgr:connector:N"
 *     3. Attach the configured permission (seat-connector.view) to the
 *        role with NO filter. It's a GLOBAL ability, so SeAT's
 *        GlobalPolicy grants it on role possession alone (filters are
 *        never consulted for global abilities; verified against
 *        web/src/Acl/Policies/GlobalPolicy.php + AbstractPolicy.php).
 *     4. Attach the applicant to the role via a RAW role_user insert.
 *        Deliberately NOT SeAT's giveUserRole(), because that fires
 *        UserRoleAdded which the Connector listens to (UserRoleAddedListener
 *        -> notifyDrivers) and would trigger a needless driver re-sync --
 *        the exact churn that made the old SeAT-Squads integration
 *        untenable.
 *     5. Upsert hr_manager_applicant_connector_grants for audit + sweep.
 *
 *   Applicant joins corp / application rejected / withdrawn / expiry
 *     |
 *     v
 *   revoke() runs:
 *     1. Delete the applicant's role_user row
 *     2. Drop the role when no users remain (cascades permission_role)
 *     3. Mark revoked_at + revoke_reason
 *
 * Safety mirrors ApplicantAccessService: strict "hr-mgr:connector:"
 * prefix guard on every delete, raw-pivot detach (never touches other
 * roles the user holds), an audit row per grant, daily sweep backstop.
 *
 * Standalone-safe: every entry point bails when the feature is off, when
 * the Connector framework isn't installed (nothing to link), or when
 * SeAT's ACL tables are missing.
 */
class ApplicantConnectorAccessService
{
    public const ROLE_TITLE_PREFIX = 'hr-mgr:connector:';

    // Setting keys
    public const SETTING_ENABLED      = 'applicant_connector_access_enabled';
    public const SETTING_PERMISSION   = 'applicant_connector_permission';
    public const SETTING_MAX_DURATION = 'applicant_connector_access_max_duration_days';

    // The permission the Connector identity page checks. Stable since the
    // framework's 2019 permissions config (view / security / logs_review);
    // overridable in Settings only as a version-safety escape hatch.
    public const DEFAULT_PERMISSION        = 'seat-connector.view';
    public const DEFAULT_MAX_DURATION_DAYS = 30;

    // Statuses that still want linking access (open + accepted-not-joined).
    private const OPEN_STATUSES = ['applied', 'under_review', 'interview', 'accepted'];

    public function __construct(
        private SeatConnectorService $connector
    ) {}

    // -----------------------------------------------------------------
    // Public lifecycle hooks
    // -----------------------------------------------------------------

    /**
     * Grant the applicant temporary `seat-connector.view`. Idempotent --
     * re-running for the same (application, user) refreshes the existing
     * grant. Returns the grant row, or null when the feature is off, the
     * Connector isn't installed, ACL tables are missing, or the applicant
     * has no resolvable SeAT user.
     */
    public function grant(Application $application, ?int $applicantUserId = null): ?ApplicantConnectorGrant
    {
        if (!$this->isFeatureEnabled()) {
            return null;
        }
        // No Connector framework = no identity page to reach = nothing to grant.
        if (!$this->connector->isAvailable()) {
            return null;
        }
        if (!$this->seatAclAvailable()) {
            return null;
        }

        try {
            $userId = $this->resolveApplicantUserId($application, $applicantUserId);
            if (!$userId) {
                // Unregistered applicant (no refresh token yet) -- nothing to attach to.
                return null;
            }

            $permission = $this->resolvePermission();
            $expiresAt  = now()->addDays($this->resolveMaxDurationDays());

            return DB::transaction(function () use ($application, $userId, $permission, $expiresAt) {
                $roleId = $this->findOrCreateRole($application, $permission);
                $this->attachUserToRole($roleId, $userId);

                return ApplicantConnectorGrant::updateOrCreate(
                    [
                        'application_id' => $application->id,
                        'user_id'        => $userId,
                    ],
                    [
                        'role_id'       => $roleId,
                        'permission'    => $permission,
                        'granted_at'    => now(),
                        'expires_at'    => $expiresAt,
                        'revoked_at'    => null,
                        'revoke_reason' => null,
                    ]
                );
            });
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] connector access grant failed: ' . $e->getMessage(), [
                'application_id' => $application->id,
            ]);
            return null;
        }
    }

    /**
     * Grant to every open applicant (applied / under_review / interview /
     * accepted-not-yet-joined). Called when the feature flips on so
     * in-flight applicants get linking access without re-submitting.
     * Returns the number of grants created/refreshed.
     */
    public function grantAllOpenApplicants(): int
    {
        if (!$this->isFeatureEnabled() || !$this->connector->isAvailable() || !$this->seatAclAvailable()) {
            return 0;
        }

        $count = 0;
        Application::whereIn('status', self::OPEN_STATUSES)
            ->whereNull('joined_corp_at')
            ->whereNull('deleted_at')
            ->chunkById(100, function ($apps) use (&$count) {
                foreach ($apps as $app) {
                    if ($this->grant($app)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Revoke the applicant's grant(s) for an application. One applicant
     * per application, so this is typically a single row. Idempotent --
     * already-revoked grants no-op. Returns the count revoked.
     */
    public function revokeForApplication(int $applicationId, string $reason = 'manual'): int
    {
        $count = 0;
        ApplicantConnectorGrant::where('application_id', $applicationId)
            ->whereNull('revoked_at')
            ->get()
            ->each(function ($grant) use ($reason, &$count) {
                if ($this->revokeGrant($grant, $reason)) {
                    $count++;
                }
            });
        return $count;
    }

    /**
     * Sweep grants past expires_at that a lifecycle hook missed. Daily
     * cron backstop. Returns count forcibly revoked.
     */
    public function sweepExpired(): int
    {
        if (!$this->seatAclAvailable()) {
            return 0;
        }

        $count = 0;
        ApplicantConnectorGrant::expired()->chunkById(100, function ($grants) use (&$count) {
            foreach ($grants as $grant) {
                if ($this->revokeGrant($grant, 'expired_sweep')) {
                    $count++;
                }
            }
        });
        return $count;
    }

    /**
     * The active grant for an application, if any (for status display).
     */
    public function activeGrantForApplication(int $applicationId): ?ApplicantConnectorGrant
    {
        return ApplicantConnectorGrant::active()
            ->where('application_id', $applicationId)
            ->where('expires_at', '>=', now())
            ->orderByDesc('granted_at')
            ->first();
    }

    // -----------------------------------------------------------------
    // Settings resolvers
    // -----------------------------------------------------------------

    public function isFeatureEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_ENABLED, false);
    }

    public function connectorAvailable(): bool
    {
        return $this->connector->isAvailable();
    }

    public function resolvePermission(): string
    {
        $raw = Setting::getValue(self::SETTING_PERMISSION, null);
        $raw = is_string($raw) ? trim($raw) : '';
        // Defend the gate string -- only a bare {scope}.{ability} token.
        if ($raw !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $raw)) {
            return $raw;
        }
        return self::DEFAULT_PERMISSION;
    }

    public function resolveMaxDurationDays(): int
    {
        $days = (int) Setting::getValue(self::SETTING_MAX_DURATION, self::DEFAULT_MAX_DURATION_DAYS);
        return max(1, min(180, $days));
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
     * Resolve the applicant's SeAT user. The submit path passes it
     * explicitly; cron / close paths resolve character_id -> refresh_tokens.
     */
    private function resolveApplicantUserId(Application $application, ?int $explicit): ?int
    {
        if ($explicit) {
            return $explicit;
        }
        $uid = DB::table('refresh_tokens')
            ->where('character_id', $application->character_id)
            ->whereNull('deleted_at')
            ->value('user_id');
        return $uid ? (int) $uid : null;
    }

    private function findOrCreateRole(Application $application, string $permission): int
    {
        $title = self::ROLE_TITLE_PREFIX . $application->id;
        $row = DB::table('roles')->where('title', $title)->first();
        if ($row) {
            $this->syncRolePermission((int) $row->id, $permission);
            return (int) $row->id;
        }

        $roleId = DB::table('roles')->insertGetId([
            'title'       => $title,
            'description' => sprintf(
                'Temporary HR Manager Discord-link grant for application #%d (Connector identity access). Auto-managed; do not modify manually.',
                $application->id
            ),
        ]);
        $this->syncRolePermission($roleId, $permission);
        return $roleId;
    }

    /**
     * Attach the single global permission to the role with NULL filters.
     * Drop-and-reinsert keeps the row aligned if the configured permission
     * title changed between grants.
     */
    private function syncRolePermission(int $roleId, string $permission): void
    {
        DB::table('permission_role')->where('role_id', $roleId)->delete();

        $permissionId = $this->findOrCreatePermissionId($permission);
        if (!$permissionId) {
            return;
        }

        DB::table('permission_role')->insert([
            'permission_id' => $permissionId,
            'role_id'       => $roleId,
            'not'           => 0,
            'filters'       => null,   // global ability -- never entity-filtered
        ]);
    }

    private function findOrCreatePermissionId(string $title): ?int
    {
        $row = DB::table('permissions')->where('title', $title)->first();
        if ($row) {
            return (int) $row->id;
        }
        try {
            return (int) DB::table('permissions')->insertGetId(['title' => $title]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] failed to create permission row for ' . $title . ': ' . $e->getMessage());
            return null;
        }
    }

    private function attachUserToRole(int $roleId, int $userId): void
    {
        $exists = DB::table('role_user')
            ->where('role_id', $roleId)
            ->where('user_id', $userId)
            ->exists();
        if ($exists) {
            return;
        }

        // Raw insert (no UserRoleAdded event) so the Connector driver is
        // not needlessly re-synced on a permission-only grant.
        DB::table('role_user')->insert([
            'role_id' => $roleId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Internal revoke step -- detach the applicant, drop the role when
     * abandoned. Strict namespace guard prevents touching an
     * operator-managed role even if grant data were corrupted.
     */
    private function revokeGrant(ApplicantConnectorGrant $grant, string $reason): bool
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

                    $remaining = DB::table('role_user')->where('role_id', $roleId)->count();
                    if ($remaining === 0) {
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

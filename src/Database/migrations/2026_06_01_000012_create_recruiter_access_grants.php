<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary SeAT role grants issued to recruiters when they join an
 * application's handler list. Each row tracks a single (application,
 * user) attachment — the SeAT role itself is per-application and may
 * have multiple recruiters attached.
 *
 * Lifecycle:
 *   - Recruiter clicks "Join as handler" → grant inserted, SeAT
 *     role_user row created, expires_at set to now + max_duration.
 *   - Recruiter clicks "Leave as handler" OR application closes
 *     (accepted/rejected/withdrawn) → revoked_at + revoke_reason set,
 *     SeAT role_user row deleted.
 *   - Daily cron sweeps any grant with expires_at < now() still active
 *     (defensive backstop in case a lifecycle hook missed it).
 *
 * Why a table at all? The pivot row in SeAT's role_user has no metadata
 * — we'd lose audit ("Carla had access to Joe's wallet from Mar 15 to
 * Mar 18 because she was handling app #142"). This table is the audit
 * trail + the source of truth for sweeper detection.
 *
 * character_ids is stored as JSON so a single grant can cover the
 * applicant's main + any alts resolved via PlayerIdentity. The
 * permission_set is a snapshot of the permissions list at grant time
 * so changing the default in Settings doesn't retroactively narrow
 * existing grants.
 */
class CreateRecruiterAccessGrants extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_recruiter_access_grants')) {
            return;
        }

        Schema::create('hr_manager_recruiter_access_grants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('user_id');
            // SeAT role.id we created/attached. Null only briefly during
            // the create-role transaction; never null in steady state.
            $table->unsignedInteger('role_id')->nullable();
            $table->json('character_ids');      // applicant's main + alts
            $table->json('permission_set');     // snapshot of granted perms
            $table->timestamp('granted_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();

            $table->index('application_id');
            $table->index('user_id');
            $table->index('expires_at');
            $table->index('revoked_at');
            // Look up "active grant for this user on this app" in O(1)
            $table->unique(['application_id', 'user_id'], 'hr_app_user_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_recruiter_access_grants');
    }
}

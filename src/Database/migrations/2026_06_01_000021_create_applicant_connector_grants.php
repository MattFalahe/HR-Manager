<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary SeAT role grants issued to APPLICANTS so they can reach the
 * SeAT Connector identity page (/seat-connector/identities) and link
 * their Discord while their application is in flight.
 *
 * Why this exists: the Connector identity page is gated by the global
 * `seat-connector.view` permission (zenobio93/warlof seat-connector,
 * src/Http/routes.php -> ->middleware('can:seat-connector.view')). A
 * brand-new applicant doesn't hold it, so the "Link Discord" button on
 * the apply / confirmation page used to dead-end at an access-denied
 * screen. This grant mints `seat-connector.view` on a per-application
 * role and attaches the applicant to it, then pulls it once they join
 * the corp (or the application closes).
 *
 * Mirrors hr_manager_recruiter_access_grants, with two differences:
 *   - ONE global permission (seat-connector.view), NO character filter.
 *     It's a global ability, so SeAT's GlobalPolicy grants it purely on
 *     role possession; filters are never consulted (verified against
 *     web/src/Acl/Policies/GlobalPolicy.php).
 *   - Attached to the APPLICANT's user, not a recruiter's.
 *
 * Lifecycle:
 *   - Application submitted (feature on + Connector installed) -> grant
 *     inserted, SeAT role_user row created (raw insert, NO UserRoleAdded
 *     event, so the Connector driver isn't needlessly re-synced),
 *     expires_at = now + max_duration.
 *   - Applicant joins the corp (DetectCorpJoinsCommand) OR application
 *     rejected / withdrawn -> revoked_at + revoke_reason set, role_user
 *     row deleted, role dropped when empty.
 *   - Accepted-but-not-yet-joined KEEPS the grant (they still need to
 *     link before the in-game join).
 *   - Daily cron sweeps any still-active grant past expires_at.
 *
 * The permission title is stored per-row so a later change to the
 * configured title doesn't misrepresent what was actually granted.
 */
class CreateApplicantConnectorGrants extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_applicant_connector_grants')) {
            return;
        }

        Schema::create('hr_manager_applicant_connector_grants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('user_id');          // the applicant's SeAT user
            // SeAT role.id we created/attached. Null only briefly during
            // the create-role transaction; never null in steady state.
            $table->unsignedInteger('role_id')->nullable();
            $table->string('permission')->default('seat-connector.view');
            $table->timestamp('granted_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();

            $table->index('application_id');
            $table->index('user_id');
            $table->index('expires_at');
            $table->index('revoked_at');
            // One applicant per application -> one active grant row.
            $table->unique(['application_id', 'user_id'], 'hr_conn_app_user_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_applicant_connector_grants');
    }
}

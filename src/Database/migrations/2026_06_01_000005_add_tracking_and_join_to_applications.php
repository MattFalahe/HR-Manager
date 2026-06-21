<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds:
 *   tracking_token   - unguessable per-application slug for the public
 *                      progress page at /recruit/track/{token}. No auth
 *                      required. Backfilled for existing rows so old
 *                      apps get a link too.
 *   joined_corp_at   - timestamp populated by hr-manager:detect-corp-joins
 *                      when the accepted applicant actually appears in
 *                      character_corporation_histories for the target corp.
 *   joined_corp_id   - the corp they joined (usually matches
 *                      applications.corporation_id but recorded
 *                      separately to be explicit).
 *
 * All three columns are nullable + idempotent via Schema::hasColumn so
 * fresh installs that already provisioned them from the consolidated
 * migration skip cleanly.
 */
class AddTrackingAndJoinToApplications extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_applications')) {
            return;
        }

        Schema::table('hr_manager_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_applications', 'tracking_token')) {
                // Length 64 keeps Str::random(48) safe with headroom; unique
                // index makes findByToken a single index lookup.
                $table->string('tracking_token', 64)->nullable()->unique();
            }
            if (!Schema::hasColumn('hr_manager_applications', 'joined_corp_at')) {
                $table->timestamp('joined_corp_at')->nullable()->after('decided_at');
            }
            if (!Schema::hasColumn('hr_manager_applications', 'joined_corp_id')) {
                $table->unsignedBigInteger('joined_corp_id')->nullable()->after('joined_corp_at');
            }
        });

        // Backfill tracking_token for existing rows so old apps get a
        // public link without operator action. Each row gets its own
        // Str::random(48). Chunked to avoid a memory spike on large
        // installs; the UNIQUE constraint guards against collisions.
        if (Schema::hasColumn('hr_manager_applications', 'tracking_token')) {
            DB::table('hr_manager_applications')
                ->whereNull('tracking_token')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('hr_manager_applications')
                            ->where('id', $row->id)
                            ->update(['tracking_token' => Str::random(48)]);
                    }
                });
        }
    }

    /**
     * No-op. Rolling back would lose tokens already shared with
     * applicants + drop the joined-corp signal. Drop manually if
     * needed.
     */
    public function down(): void
    {
        // no-op
    }
}

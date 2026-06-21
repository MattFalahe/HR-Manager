<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intel scope-corp alerting: when an intel note is filed on a
 * character who is ALREADY inside a corp the operator watches (the
 * note's scope corp, or any tracked corp for a global note), HR fires
 * a heads-up to that corp's webhook. Same idea as the blacklist
 * corp-match detection, but for the lighter-weight intel database.
 *
 * Adds to hr_manager_intel_notes:
 *   scope_alert_corp_id   the corp_id we last alerted about for this
 *                         note. Lets the periodic scan re-fire if the
 *                         character moves to a DIFFERENT watched corp,
 *                         while staying idempotent for the same corp.
 *   scope_alert_sent_at   when that alert was sent (audit / display).
 *
 * Additive + guarded so it's safe to run on an install that already
 * has the consolidated schema.
 */
class AddIntelScopeAlertColumns extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_intel_notes')) {
            return;
        }

        Schema::table('hr_manager_intel_notes', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_intel_notes', 'scope_alert_corp_id')) {
                $table->unsignedBigInteger('scope_alert_corp_id')->nullable()->after('scope_corporation_id');
            }
            if (!Schema::hasColumn('hr_manager_intel_notes', 'scope_alert_sent_at')) {
                $table->timestamp('scope_alert_sent_at')->nullable()->after('scope_alert_corp_id');
            }
        });
    }

    public function down(): void
    {
        // Leave the columns in place on rollback; they carry alert
        // history and dropping them would re-fire alerts on the next
        // scan if the migration were re-run.
    }
}

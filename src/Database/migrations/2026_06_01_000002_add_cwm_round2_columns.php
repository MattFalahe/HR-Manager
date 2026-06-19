<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only safety net for the CWM Round-2 column additions that
 * landed in commit 40ce808.
 *
 * Those columns were appended to the v1.0.0 consolidated migration
 * (2026_06_01_000001_create_hr_manager_tables.php) AFTER some dev
 * installs had already executed the original version. Laravel's
 * migration tracker keys on filename + class name, so the consolidated
 * migration does not re-run on those installs — the table exists
 * without the new columns, and CorpStatusService crashes with
 * "Column not found: lifetime_contribution".
 *
 * Every ADD COLUMN is guarded with Schema::hasColumn so fresh installs
 * (where the consolidated migration already provisioned the columns)
 * skip cleanly. Stale installs (where the original ran first) pick up
 * the additions on next boot.
 *
 * Columns added across three tables:
 *   hr_manager_member_assessments:
 *     lifetime_contribution         decimal(20,2) null
 *     net_position_6mo              decimal(20,2) null
 *     wallet_compliance_pct_6mo     decimal(5,2)  null
 *     last_contribution_at          timestamp     null
 *   hr_manager_player_classifications:
 *     wallet_flags                  json          null
 *   hr_manager_webhook_configurations:
 *     notify_wallet_stalled              boolean default false
 *     notify_wallet_compliance_dropped   boolean default false
 *     notify_wallet_milestone            boolean default false
 */
class AddCwmRound2Columns extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_member_assessments')) {
            Schema::table('hr_manager_member_assessments', function (Blueprint $table) {
                if (!Schema::hasColumn('hr_manager_member_assessments', 'lifetime_contribution')) {
                    $table->decimal('lifetime_contribution', 20, 2)->nullable()->after('member_since');
                }
                if (!Schema::hasColumn('hr_manager_member_assessments', 'net_position_6mo')) {
                    $table->decimal('net_position_6mo', 20, 2)->nullable()->after('lifetime_contribution');
                }
                if (!Schema::hasColumn('hr_manager_member_assessments', 'wallet_compliance_pct_6mo')) {
                    $table->decimal('wallet_compliance_pct_6mo', 5, 2)->nullable()->after('net_position_6mo');
                }
                if (!Schema::hasColumn('hr_manager_member_assessments', 'last_contribution_at')) {
                    $table->timestamp('last_contribution_at')->nullable()->after('wallet_compliance_pct_6mo');
                }
            });
        }

        if (Schema::hasTable('hr_manager_player_classifications')) {
            Schema::table('hr_manager_player_classifications', function (Blueprint $table) {
                if (!Schema::hasColumn('hr_manager_player_classifications', 'wallet_flags')) {
                    $table->json('wallet_flags')->nullable()->after('last_activity_at');
                }
            });
        }

        if (Schema::hasTable('hr_manager_webhook_configurations')) {
            Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
                if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_wallet_stalled')) {
                    $table->boolean('notify_wallet_stalled')->default(false);
                }
                if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_wallet_compliance_dropped')) {
                    $table->boolean('notify_wallet_compliance_dropped')->default(false);
                }
                if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_wallet_milestone')) {
                    $table->boolean('notify_wallet_milestone')->default(false);
                }
            });
        }
    }

    /**
     * Intentionally a no-op. Dropping these columns on a downgrade would
     * destroy CWM aggregate cache data; operators who genuinely need to
     * roll back can drop the columns manually. The consolidated v1.0.0
     * migration's down() handles full schema teardown for clean
     * reinstalls.
     */
    public function down(): void
    {
        // no-op
    }
}

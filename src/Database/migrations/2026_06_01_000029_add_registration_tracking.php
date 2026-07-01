<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Unregistered member" tracking.
 *
 *   hr_manager_corp_members gains is_registered + registered_at: whether the
 *   character is linked to a SeAT account (has a refresh token). A member who
 *   joins the corp but is NOT in SeAT is flagged; the flag clears automatically
 *   once they register (as their own main, or as an alt of any account).
 *
 *   + a notify_member_unregistered webhook category for the "unregistered
 *     character joined" alert.
 *
 * Additive + guarded so re-running is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_corp_members')) {
            $addedColumn = !Schema::hasColumn('hr_manager_corp_members', 'is_registered');

            Schema::table('hr_manager_corp_members', function (Blueprint $table) {
                if (!Schema::hasColumn('hr_manager_corp_members', 'is_registered')) {
                    $table->boolean('is_registered')->default(true)->after('main_character_id');
                }
                if (!Schema::hasColumn('hr_manager_corp_members', 'registered_at')) {
                    $table->timestamp('registered_at')->nullable()->after('is_registered');
                }
                $table->index(['corporation_id', 'is_registered']);
            });

            // The column defaults to true, which would wrongly mark every
            // already-snapshotted member as registered. Reconcile existing rows
            // against the real SeAT link (a live refresh token) in one pass, so
            // members seeded before this migration are flagged correctly.
            if ($addedColumn && Schema::hasTable('refresh_tokens')) {
                \Illuminate\Support\Facades\DB::statement(
                    'UPDATE hr_manager_corp_members cm SET is_registered = ' .
                    'EXISTS (SELECT 1 FROM refresh_tokens rt WHERE rt.character_id = cm.character_id AND rt.deleted_at IS NULL)'
                );
            }
        }

        if (Schema::hasTable('hr_manager_webhook_configurations')
            && !Schema::hasColumn('hr_manager_webhook_configurations', 'notify_member_unregistered')) {
            Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
                $table->boolean('notify_member_unregistered')->default(true)->after('notify_join_no_application');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_manager_corp_members')) {
            Schema::table('hr_manager_corp_members', function (Blueprint $table) {
                if (Schema::hasColumn('hr_manager_corp_members', 'is_registered')) {
                    $table->dropIndex(['corporation_id', 'is_registered']);
                    $table->dropColumn('is_registered');
                }
                if (Schema::hasColumn('hr_manager_corp_members', 'registered_at')) {
                    $table->dropColumn('registered_at');
                }
            });
        }

        if (Schema::hasTable('hr_manager_webhook_configurations')
            && Schema::hasColumn('hr_manager_webhook_configurations', 'notify_member_unregistered')) {
            Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
                $table->dropColumn('notify_member_unregistered');
            });
        }
    }
};

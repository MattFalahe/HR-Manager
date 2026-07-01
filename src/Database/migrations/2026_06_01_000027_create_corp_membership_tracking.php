<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corp membership-change tracking.
 *
 *   hr_manager_corp_members
 *     HR's own forward-only snapshot of who is in each tracked corp. The
 *     membership detector diffs SeAT's live roster against this table to find
 *     joins + leaves. The FIRST time a corp is seen it is seeded silently (so
 *     existing members are never announced); only later joins / leaves notify.
 *     main_character_id is captured at seed/join time so a leave can still name
 *     the person after the character is gone.
 *
 *   + three webhook categories on hr_manager_webhook_configurations:
 *     notify_member_joined        — a known person joined (applied, or an alt of
 *                                   a current member); the message names the main
 *     notify_member_left          — a character left the corp
 *     notify_join_no_application  — a NEW person joined with no valid application
 *                                   (the security flag). Forward-only, so current
 *                                   members are never flagged.
 *
 * Additive + guarded so re-running is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_corp_members')) {
            Schema::create('hr_manager_corp_members', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('corporation_id');
                $table->unsignedBigInteger('character_id');
                $table->unsignedBigInteger('main_character_id')->nullable();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamps();

                $table->unique(['corporation_id', 'character_id']);
                $table->index('character_id');
                $table->index('main_character_id');
            });
        }

        if (Schema::hasTable('hr_manager_webhook_configurations')) {
            Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
                if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_member_joined')) {
                    $table->boolean('notify_member_joined')->default(false)->after('notify_player_status');
                }
                if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_member_left')) {
                    $table->boolean('notify_member_left')->default(false)->after('notify_member_joined');
                }
                if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_join_no_application')) {
                    $table->boolean('notify_join_no_application')->default(false)->after('notify_member_left');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_corp_members');

        if (Schema::hasTable('hr_manager_webhook_configurations')) {
            Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
                foreach (['notify_member_joined', 'notify_member_left', 'notify_join_no_application'] as $col) {
                    if (Schema::hasColumn('hr_manager_webhook_configurations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actor attribution on the history timeline: WHO performed each event.
 *
 * Distinct from user_id (the SUBJECT — the player the event is about).
 * actor_user_id is the SeAT user who took the action, captured automatically
 * from the authenticated request. NULL means no human actor — a cron /
 * queued / EventBus-driven event, rendered as "HR (automated)".
 *
 * Nullable + additive; existing rows keep NULL (the timeline only labels NULL
 * as automated for event types that are genuinely automated, so old manual
 * rows are not mislabelled).
 */
class AddActorToHistoryEvents extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_member_history_events')) {
            return;
        }

        Schema::table('hr_manager_member_history_events', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_member_history_events', 'actor_user_id')) {
                $table->unsignedBigInteger('actor_user_id')->nullable()->after('user_id');
                $table->index('actor_user_id', 'hr_history_actor_idx');
            }
        });
    }

    public function down(): void
    {
        // Leave the column in place on rollback (additive, harmless).
    }
}

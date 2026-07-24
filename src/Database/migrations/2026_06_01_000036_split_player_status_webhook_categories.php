<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the bundled notify_player_status category (LOA / marked-for-purge /
 * cleared all fired the same toggle) into three independent categories, so an
 * operator can route "marked for purge" to a channel members watch without
 * also routing LOA/cleared noise there. Also adds notify_purge_personal — a
 * standalone "notify the player" category that @-mentions the flagged player
 * (via their seat-connector-linked Discord identity) instead of the role, for
 * a member-facing channel.
 *
 * An earlier cut of this migration created a mention_player_on_purge modifier
 * column; that concept became the standalone notify_purge_personal category.
 * This migration folds it away — carrying any set value across, then dropping
 * the old column — so a re-run (delete the row + let it re-apply) fully heals
 * an install that ran the earlier cut. Every step is guarded, and the split
 * backfill only runs on first creation, so a re-run never clobbers hand-tuned
 * toggles. notify_player_status is left in place (never dropped) but is no
 * longer read.
 */
class SplitPlayerStatusWebhookCategories extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_webhook_configurations')) {
            return;
        }

        // Track whether the split columns are being created for the first time
        // — the backfill below is keyed off this so it can't re-run and reset
        // toggles an operator has since changed.
        $splitCreated = false;

        Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) use (&$splitCreated) {
            if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_loa_marked')) {
                $table->boolean('notify_loa_marked')->default(true)->after('notify_player_status');
                $splitCreated = true;
            }
            if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_marked_for_purge')) {
                $table->boolean('notify_marked_for_purge')->default(true)->after('notify_loa_marked');
            }
            if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_status_cleared')) {
                $table->boolean('notify_status_cleared')->default(true)->after('notify_marked_for_purge');
            }
            if (!Schema::hasColumn('hr_manager_webhook_configurations', 'notify_purge_personal')) {
                $table->boolean('notify_purge_personal')->default(false)->after('notify_status_cleared');
            }
        });

        // Fold the earlier modifier column into the new category: carry any set
        // value across, then drop it. Only fires where the old column exists
        // (an install that ran the earlier cut) — a no-op on fresh installs.
        if (Schema::hasColumn('hr_manager_webhook_configurations', 'mention_player_on_purge')) {
            DB::table('hr_manager_webhook_configurations')->update([
                'notify_purge_personal' => DB::raw('mention_player_on_purge'),
            ]);
            Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
                $table->dropColumn('mention_player_on_purge');
            });
        }

        // Backfill: every existing webhook keeps firing for all three
        // sub-events exactly as it did under the bundled toggle. First creation
        // only, so re-applying the migration doesn't overwrite later edits.
        if ($splitCreated) {
            DB::table('hr_manager_webhook_configurations')->update([
                'notify_loa_marked'       => DB::raw('notify_player_status'),
                'notify_marked_for_purge' => DB::raw('notify_player_status'),
                'notify_status_cleared'   => DB::raw('notify_player_status'),
            ]);
        }
    }

    public function down(): void
    {
        // Leave columns in place on rollback (additive, harmless).
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Watchlist v2: alliance scope, per-entry notification policy,
 * lifecycle audit trail, and a sibling table for detection events.
 *
 * Adds to hr_manager_watchlist_entries:
 *   scope_alliance_id            optional alliance-level scope
 *                                (entries can be corp / alliance /
 *                                global; corp + alliance can both be
 *                                set for "this corp in this alliance")
 *   status                       enum(active, cleared, expired) so
 *                                we keep cleared entries as audit
 *                                history rather than hard-deleting
 *   cleared_at / _by / _reason   who cleared the entry and why; null
 *                                until the entry is cleared
 *   notify_on_corp_match         fire detection when char appears in
 *                                a tracked corp covered by the scope
 *   notify_on_alliance_match     fire detection when char joins an
 *                                alliance covered by the scope
 *   notify_on_external_change    poll public ESI periodically and
 *                                fire when their current corp/alliance
 *                                changes (warn even outside our reach)
 *
 * New table hr_manager_watchlist_detections records every detection
 * event so the notification dispatch dedups (same entry + same
 * character + same detection-type + same target_id doesn't fire
 * twice).
 */
class ExtendWatchlistAllianceAudit extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_watchlist_entries')) {
            Schema::table('hr_manager_watchlist_entries', function (Blueprint $table) {
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'scope_alliance_id')) {
                    $table->unsignedBigInteger('scope_alliance_id')->nullable()->after('scope_corporation_id');
                }
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'status')) {
                    $table->enum('status', ['active', 'cleared', 'expired'])->default('active');
                }
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'cleared_at')) {
                    $table->timestamp('cleared_at')->nullable();
                }
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'cleared_by')) {
                    $table->unsignedBigInteger('cleared_by')->nullable();
                }
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'cleared_reason')) {
                    $table->text('cleared_reason')->nullable();
                }
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'notify_on_corp_match')) {
                    $table->boolean('notify_on_corp_match')->default(true);
                }
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'notify_on_alliance_match')) {
                    $table->boolean('notify_on_alliance_match')->default(true);
                }
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'notify_on_external_change')) {
                    $table->boolean('notify_on_external_change')->default(false);
                }
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'last_external_corp_id')) {
                    $table->unsignedBigInteger('last_external_corp_id')->nullable();
                }
                if (!Schema::hasColumn('hr_manager_watchlist_entries', 'last_external_check_at')) {
                    $table->timestamp('last_external_check_at')->nullable();
                }
            });

            // Index for scope_alliance_id + status filters
            if (Schema::hasColumn('hr_manager_watchlist_entries', 'scope_alliance_id')) {
                try {
                    Schema::table('hr_manager_watchlist_entries', function (Blueprint $table) {
                        $table->index('scope_alliance_id', 'hr_watchlist_alliance_idx');
                    });
                } catch (\Throwable $e) {
                    // index may already exist on re-runs; ignore
                }
            }
        }

        // Detection sibling table — keyed by entry + character +
        // detection_type so the same detection (e.g. "char X joined
        // our corp Y") doesn't fire twice for the same entry.
        if (!Schema::hasTable('hr_manager_watchlist_detections')) {
            Schema::create('hr_manager_watchlist_detections', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('watchlist_entry_id');
                $table->unsignedBigInteger('character_id');
                $table->enum('detection_type', [
                    'joined_managed_corp',
                    'joined_managed_alliance',
                    'external_corp_change',
                ]);
                // The corp / alliance the character was found in at
                // detection time.
                $table->unsignedBigInteger('detected_corporation_id')->nullable();
                $table->unsignedBigInteger('detected_alliance_id')->nullable();
                // Snapshot of the previous corp_id when we have it
                // (external-change detections always carry both).
                $table->unsignedBigInteger('previous_corporation_id')->nullable();
                $table->timestamp('detected_at')->useCurrent();
                $table->timestamp('notification_sent_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['watchlist_entry_id', 'character_id', 'detection_type', 'detected_corporation_id'],
                    'hr_watchlist_detection_unique'
                );
                $table->index(['watchlist_entry_id', 'detected_at']);
                $table->foreign('watchlist_entry_id')
                    ->references('id')
                    ->on('hr_manager_watchlist_entries')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        // Leave column additions in place on rollback so cleared-entry
        // audit history isn't destroyed. Drop only the detections
        // table.
        Schema::dropIfExists('hr_manager_watchlist_detections');
    }
}

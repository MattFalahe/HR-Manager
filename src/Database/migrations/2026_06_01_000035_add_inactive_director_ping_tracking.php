<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when the inactive-director alert was last sent for a classification.
 *
 * The alert used to fire once, on the transition into inactive-director, and
 * never again. Operators want a reminder while a director stays dark, on a
 * cadence they choose (once / daily / 3 days / weekly / biweekly / monthly —
 * the `inactive_director_repeat_days` setting). Measuring that cadence needs a
 * per-classification "last notified" stamp, which is what this column is.
 *
 * Cleared back to NULL when the director is no longer flagged, so a director
 * who goes dark again later gets a fresh first alert rather than being muted by
 * a stale stamp.
 */
class AddInactiveDirectorPingTracking extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_player_classifications')) {
            return;
        }

        if (!Schema::hasColumn('hr_manager_player_classifications', 'inactive_director_notified_at')) {
            Schema::table('hr_manager_player_classifications', function (Blueprint $table) {
                $table->timestamp('inactive_director_notified_at')->nullable()->after('is_inactive_director');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_manager_player_classifications', 'inactive_director_notified_at')) {
            Schema::table('hr_manager_player_classifications', function (Blueprint $table) {
                $table->dropColumn('inactive_director_notified_at');
            });
        }
    }
}

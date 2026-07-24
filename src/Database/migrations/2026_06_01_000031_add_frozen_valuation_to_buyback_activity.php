<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-contribution FROZEN valuation on hr_manager_buyback_activity.
 *
 * Originally every row was valued on read through the corp's CURRENT policy, so
 * editing the policy re-valued all history. Operators want an era model instead:
 * a contribution made under one policy keeps that policy's tier/weight/attribution
 * even after the policy changes, while new contributions pick up the new policy —
 * and both still count toward the total.
 *
 * These columns snapshot the policy that was in effect for a row. `frozen_at`
 * NULL = not frozen yet (still valued at the corp's current policy on read, which
 * preserves the old behaviour for legacy rows). When the operator changes the
 * policy, the ending era is frozen at the old policy; when they choose "apply to
 * all", every row is re-frozen at the new policy.
 */
class AddFrozenValuationToBuybackActivity extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_buyback_activity')) {
            return;
        }

        Schema::table('hr_manager_buyback_activity', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_buyback_activity', 'frozen_tier')) {
                $table->string('frozen_tier', 16)->nullable();
            }
            if (!Schema::hasColumn('hr_manager_buyback_activity', 'frozen_weight')) {
                $table->decimal('frozen_weight', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('hr_manager_buyback_activity', 'frozen_counted')) {
                $table->boolean('frozen_counted')->nullable();
            }
            if (!Schema::hasColumn('hr_manager_buyback_activity', 'frozen_attributed_corporation_id')) {
                $table->unsignedBigInteger('frozen_attributed_corporation_id')->nullable();
            }
            if (!Schema::hasColumn('hr_manager_buyback_activity', 'frozen_at')) {
                $table->timestamp('frozen_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('hr_manager_buyback_activity')) {
            return;
        }

        Schema::table('hr_manager_buyback_activity', function (Blueprint $table) {
            foreach (['frozen_tier', 'frozen_weight', 'frozen_counted', 'frozen_attributed_corporation_id', 'frozen_at'] as $col) {
                if (Schema::hasColumn('hr_manager_buyback_activity', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}

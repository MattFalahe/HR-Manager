<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-corp switch for the onboarding welcome.
 *
 * The feature had one global toggle, so every tracked corp queued welcomes
 * whether or not that corp was actually running onboarding. Harmless while an
 * undeliverable welcome was silently dropped; now that they wait for their
 * conditions instead, a corp nobody set up would accumulate pending welcomes
 * for a week and then log them as abandoned.
 *
 * Opt-in: a corp is OFF until someone turns it on. Flipping the master switch
 * shouldn't quietly start greeting new members of every corp HR can see, so the
 * global toggle arms the feature and each corp is chosen deliberately.
 *
 * The column default is largely academic — the settings form always writes this
 * explicitly, and a corp with no row at all is treated as off by the service —
 * but it matches the intent for any row created another way.
 */
class AddEnabledToOnboardingTemplates extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_onboarding_templates')) {
            return;
        }
        if (Schema::hasColumn('hr_manager_onboarding_templates', 'is_enabled')) {
            return;
        }

        Schema::table('hr_manager_onboarding_templates', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(false)->after('corporation_id');
        });
    }

    /** Forward-only: the released schema never removes columns. */
    public function down(): void
    {
        // no-op
    }
}

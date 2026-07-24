<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only migration adding next_steps_markdown to the recruitment
 * landings table for installs that ran the consolidated v1.0.0
 * migration BEFORE this column was appended.
 *
 * Always-visible Markdown notes shown on the post-submission "Next
 * steps" panel, independent of the post_submission_mode (Discord
 * invite / Connector link / custom message). Directors use it for the
 * "what happens now" copy that should always appear: expected review
 * timeline, who to DM with questions, status-check link, etc.
 *
 * Idempotent via Schema::hasColumn so fresh installs (where the
 * consolidated migration provisioned the column) skip cleanly.
 */
class AddNextStepsMarkdownToLandings extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_recruitment_landings')) {
            return;
        }

        if (Schema::hasColumn('hr_manager_recruitment_landings', 'next_steps_markdown')) {
            return;
        }

        Schema::table('hr_manager_recruitment_landings', function (Blueprint $table) {
            $table->text('next_steps_markdown')->nullable()->after('custom_confirmation_markdown');
        });
    }

    /**
     * No-op. Dropping the column would discard director-authored notes
     * on a downgrade. Operators who genuinely need to roll back can drop
     * the column manually.
     */
    public function down(): void
    {
        // no-op
    }
}

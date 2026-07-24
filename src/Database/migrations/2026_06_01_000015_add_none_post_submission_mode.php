<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only migration adding a 'none' value to
 * recruitment_landings.post_submission_mode. v1.0.0 shipped three modes
 * (discord_invite / seat_connector / custom); 'none' makes "this corp
 * runs no Discord onboarding step at all" a deliberate, labelled choice
 * instead of an empty custom field that reads like a misconfiguration.
 *
 * Idempotent: skips when 'none' is already part of the enum. Raw ALTER
 * because Doctrine DBAL can't represent MySQL enums (same reasoning as
 * the headline-widen migration).
 */
class AddNonePostSubmissionMode extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('hr_manager_recruitment_landings', 'post_submission_mode')) {
            return;
        }

        // Skip if 'none' is already in the enum definition so the migration
        // is safe to re-run.
        $col = DB::selectOne(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'hr_manager_recruitment_landings'
               AND COLUMN_NAME = 'post_submission_mode'"
        );
        if ($col && str_contains(strtolower((string) $col->COLUMN_TYPE), "'none'")) {
            return;
        }

        DB::statement(
            "ALTER TABLE `hr_manager_recruitment_landings`
             MODIFY `post_submission_mode`
             ENUM('discord_invite','seat_connector','custom','none')
             NOT NULL DEFAULT 'seat_connector'"
        );
    }

    /**
     * No-op. Narrowing the enum back could orphan rows already set to
     * 'none' and there is no safe value to coerce them to.
     */
    public function down(): void
    {
        // no-op
    }
}

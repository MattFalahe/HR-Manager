<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only migration widening recruitment_landings.headline from
 * VARCHAR(255) to TEXT. v1.0.0 shipped headline as a one-liner string;
 * directors asked for the same richer Markdown editor the body field
 * already uses (multi-paragraph copy, formatting toolbar, alignment).
 *
 * Idempotent. Uses raw ALTER TABLE because Doctrine DBAL is no longer
 * required for column type changes in our SeAT v5 baseline + we want
 * to avoid pulling that dep in. MySQL TEXT can hold ~65k bytes which
 * comfortably fits the new 8000-char ceiling the controller enforces.
 */
class WidenLandingHeadline extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_recruitment_landings')) {
            return;
        }

        if (!Schema::hasColumn('hr_manager_recruitment_landings', 'headline')) {
            // Defensive: if the column is missing entirely, recreate it as
            // text so the rest of the system has something to write into.
            Schema::table('hr_manager_recruitment_landings', function (Blueprint $table) {
                $table->text('headline')->nullable()->after('title');
            });
            return;
        }

        // Skip if already TEXT (or a longer text variant) so the migration
        // is safe to re-run.
        $col = DB::selectOne(
            "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'hr_manager_recruitment_landings'
               AND COLUMN_NAME = 'headline'"
        );
        if ($col && in_array(strtolower($col->DATA_TYPE), ['text', 'mediumtext', 'longtext'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `hr_manager_recruitment_landings` MODIFY `headline` TEXT NULL');
    }

    /**
     * No-op. Narrowing back to VARCHAR(255) would truncate director copy.
     */
    public function down(): void
    {
        // no-op
    }
}

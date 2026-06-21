<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only migration hardening the applications -> form_templates FK.
 *
 * v1.0.0 created `applications.template_id` with onDelete('cascade'), so a
 * HARD delete of a template would cascade-delete every application that used
 * it. The UI only soft-deletes templates (FormTemplate uses SoftDeletes), so
 * this never fires in normal use, but it is a fragile guard against
 * catastrophic data loss. Switch it to RESTRICT: a force/hard delete of a
 * used template is now blocked outright instead of silently wiping its
 * applications.
 *
 * Idempotent: inspects the current DELETE_RULE and only swaps when it is
 * still CASCADE, so it is safe under SeAT's auto-run-on-restart.
 */
class HardenApplicationTemplateFk extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_applications')
            || !Schema::hasColumn('hr_manager_applications', 'template_id')) {
            return;
        }

        $rule = DB::selectOne(
            "SELECT rc.DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.TABLE_NAME = 'hr_manager_applications'
               AND rc.REFERENCED_TABLE_NAME = 'hr_manager_form_templates'"
        );

        // Already hardened (or the FK is absent) — nothing to do.
        if (!$rule || strtoupper((string) $rule->DELETE_RULE) !== 'CASCADE') {
            return;
        }

        Schema::table('hr_manager_applications', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->foreign('template_id')
                ->references('id')
                ->on('hr_manager_form_templates')
                ->onDelete('restrict');
        });
    }

    /**
     * No-op: reverting to cascade would re-introduce the data-loss risk.
     */
    public function down(): void
    {
        // intentionally empty
    }
}

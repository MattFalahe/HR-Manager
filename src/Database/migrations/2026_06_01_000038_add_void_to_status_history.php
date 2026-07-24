<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds void columns to the application status history so a director can undo an
 * accidental status change: the erroneous row is marked voided (who / when /
 * why) rather than deleted, so it stays in the internal audit trail but is
 * filtered out of the applicant-facing tracking timeline. See
 * Application::publicStatusHistory() and ApplicationService::revertLastStatus().
 */
class AddVoidToStatusHistory extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_application_status_history')) {
            return;
        }

        Schema::table('hr_manager_application_status_history', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_manager_application_status_history', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('comment');
            }
            if (!Schema::hasColumn('hr_manager_application_status_history', 'voided_by')) {
                $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            }
            if (!Schema::hasColumn('hr_manager_application_status_history', 'void_reason')) {
                $table->string('void_reason', 1000)->nullable()->after('voided_by');
            }
        });
    }

    public function down(): void
    {
        // Leave the columns in place on rollback (additive, harmless).
    }
}

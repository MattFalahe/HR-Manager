<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook category for "a handler wrote a note on your application".
 *
 * Joining an application's handler list has so far been a display + access
 * feature: it grants temporary SeAT access but subscribes you to nothing. Two
 * recruiters working one applicant had no way to know the other had written
 * anything short of reopening the page.
 *
 * Defaults to FALSE so an existing install doesn't start pinging people who
 * never asked for it.
 */
class AddHandlerNoteWebhookCategory extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_webhook_configurations')) {
            return;
        }
        if (Schema::hasColumn('hr_manager_webhook_configurations', 'notify_handler_note')) {
            return;
        }

        Schema::table('hr_manager_webhook_configurations', function (Blueprint $table) {
            $table->boolean('notify_handler_note')
                ->default(false)
                ->after('notify_status_change');
        });
    }

    /** Forward-only: the released schema never removes columns. */
    public function down(): void
    {
        // no-op
    }
}

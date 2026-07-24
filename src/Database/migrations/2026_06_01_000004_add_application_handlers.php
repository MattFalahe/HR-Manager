<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-handler tracking for applications.
 *
 * Lets several recruiters collaborate on the same application (one
 * talks on Discord, another runs the alt-check, etc.) and surfaces the
 * roster on the app detail page + the applications index column.
 *
 * Auto-tracking: ApplicationService::updateStatus inserts a handler
 * row for the actor when they change status. Recruiters can also
 * explicitly join / leave from the detail view.
 *
 * role_label is an optional free-text tag ("Reviewer", "Interviewer",
 * "Background check"). Blank is allowed.
 *
 * Unique (application_id, user_id) prevents double-rows. Hard-delete
 * on leave so re-joining is fresh; status_history captures the audit
 * trail of who-did-what.
 */
class AddApplicationHandlers extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_application_handlers')) {
            return;
        }

        Schema::create('hr_manager_application_handlers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('user_id');
            // Snapshot of the SeAT user's main_character_id at join
            // time. Used as the portrait id on the Handlers card and
            // the avatar list on the applications index. Nullable for
            // users who haven't picked a main yet (rare). Denormalized
            // so the index render doesn't need a user join + lookup.
            $table->unsignedBigInteger('character_id')->nullable();
            $table->string('role_label', 64)->nullable();
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->unique(['application_id', 'user_id'], 'hr_handler_app_user_unique');
            $table->index('application_id');
            $table->index('user_id');
            $table->foreign('application_id')
                ->references('id')
                ->on('hr_manager_applications')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_application_handlers');
    }
}

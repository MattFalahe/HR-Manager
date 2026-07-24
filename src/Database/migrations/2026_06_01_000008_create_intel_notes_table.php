<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intel notes — a director-tier database of observations about EVE
 * characters across time, scoped per-corp or globally. Independent
 * of corp membership: notes can be added about characters who've
 * never been in our corp, left long ago, or are tracked for
 * coalition-wide situational awareness.
 *
 * Intel is mostly director-only. The hr_manager_settings key
 * `intel.recruiter_view_enabled` controls whether recruiters can
 * view notes flagged `recruiter_visible`. Without that setting,
 * recruiters see nothing regardless of per-note flags.
 *
 * Notes are keyed by character_id (canonical identity from EVE);
 * character_name is a snapshot at add time so the record stays
 * meaningful when the character isn't in SeAT's character_infos.
 * The author is responsible for resolving the name via the
 * NameResolutionService at insert time.
 *
 * Tags is a JSON array of free-text labels (spy / fc /
 * industrialist / scammer / etc.) for the filter UI.
 */
class CreateIntelNotesTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_intel_notes')) {
            return;
        }

        Schema::create('hr_manager_intel_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('character_id');
            $table->string('character_name', 64)->nullable();
            // NULL = global note (relevant to every corp the viewer
            // has access to). Otherwise scoped to one corp.
            $table->unsignedBigInteger('scope_corporation_id')->nullable();
            $table->text('body');
            // Free-text tag list for the filter UI.
            $table->json('tags')->nullable();
            // Per-note recruiter-share flag. Only honored when the
            // global intel.recruiter_view_enabled setting is also true.
            $table->boolean('recruiter_visible')->default(false);
            $table->unsignedBigInteger('author_id');
            // Optional expiry — useful for "spy in $event 2024" notes
            // that lose relevance after a year.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('character_id');
            $table->index('scope_corporation_id');
            $table->index('recruiter_visible');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_intel_notes');
    }
}

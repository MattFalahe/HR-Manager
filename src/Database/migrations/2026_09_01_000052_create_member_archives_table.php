<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A frozen record of one completed membership: this character, in this corp,
 * between these dates, as they stood on the day they left.
 *
 * One row per STINT, not per person. Somebody who joins, leaves, rejoins and
 * leaves again gets two rows, which is the useful shape: you can see they are
 * on their second go, and each row is honestly frozen at its own departure. A
 * single row per person would have to be overwritten each cycle, destroying
 * exactly the history it exists to keep.
 *
 * Frozen rather than recomputed on demand because the source data does not
 * reliably survive. A departing member often revokes their ESI token, at which
 * point syncing stops and their history can be pruned outright -- so a figure
 * derived later would silently under-report with nothing to say it was partial.
 * Captured at departure it is at least complete as of a date we can name.
 *
 * Deliberately NOT a "former member" flag. Whether somebody is currently an
 * ex-member is derived: a closed stint, and not on the roster now. That way a
 * rejoin needs no write at all -- they reappear on the roster, stop matching
 * the query, and this row stays as the history of the stint that ended.
 */
class CreateMemberArchivesTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_member_archives')) {
            return;
        }

        Schema::create('hr_manager_member_archives', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('corporation_id');
            $table->string('character_name', 64)->nullable();

            // The account at the time. Kept as a column rather than resolved on
            // read: account membership can change afterwards, and this record
            // is about who they were then.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('main_character_id')->nullable();

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('days_in_corp')->nullable();

            // Where they went, when we could tell. Null means we could not.
            $table->unsignedBigInteger('destination_corporation_id')->nullable();
            $table->string('destination_corporation_name', 128)->nullable();

            // resigned | purged | kicked | unknown
            $table->string('departure_type', 16)->default('unknown');

            // Judgements that cannot be recomputed later: days-inactive keeps
            // growing after somebody leaves, so a recomputed tier would read
            // Dead Weight for everyone who ever left, however good they were.
            $table->string('tier_at_departure', 24)->nullable();
            $table->string('classification_at_departure', 24)->nullable();
            $table->unsignedInteger('days_inactive_at_departure')->nullable();

            // Contribution over THIS stint only.
            $table->decimal('wallet_contributed', 22, 2)->nullable();
            $table->decimal('mining_contributed', 22, 2)->nullable();
            $table->unsignedInteger('tax_compliance_pct')->nullable();

            // Whether we could still see them when we wrote this. A record
            // taken after the token went dark is not wrong, but it is only as
            // complete as the last sync, and the page should be able to say so.
            $table->boolean('token_valid_at_departure')->default(false);

            $table->unsignedInteger('note_count')->default(0);
            $table->unsignedInteger('intel_count')->default(0);
            $table->boolean('was_blacklisted')->default(false);

            // recorded  = snapshotted at departure, complete as of that date
            // reconstructed = rebuilt afterwards from whatever survived, so
            //                 partial by definition and never to be presented
            //                 as though it were the former
            $table->string('source', 16)->default('recorded');

            $table->timestamps();

            $table->unique(['character_id', 'corporation_id', 'left_at'], 'hr_archive_stint_uniq');
            $table->index(['corporation_id', 'left_at'], 'hr_archive_corp_left_idx');
            $table->index('user_id', 'hr_archive_user_idx');
            $table->index('main_character_id', 'hr_archive_main_idx');
        });
    }

    /** Forward-only: the released schema never drops tables. */
    public function down(): void
    {
        // no-op
    }
}

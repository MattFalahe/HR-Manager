<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far the donation scan has read each character's wallet journal.
 *
 * Without this every nightly run would re-read every journal row a character
 * has ever had, which is the difference between a scan that costs seconds and
 * one that costs minutes and grows forever. The flags table cannot serve as the
 * watermark on its own: it only holds rows that MATCHED, so a character with no
 * flagged donations would have nothing to mark the position and would be
 * re-scanned in full every night.
 *
 * last_journal_id is the real watermark (journal ids increase within a
 * character). last_journal_at is kept alongside for the UI and for diagnosing a
 * scan that has stalled.
 */
class CreateDonationScanStateTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_donation_scan_state')) {
            return;
        }

        Schema::create('hr_manager_donation_scan_state', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->primary();

            $table->unsignedBigInteger('last_journal_id')->default(0);
            $table->timestamp('last_journal_at')->nullable();
            $table->timestamp('scanned_at')->nullable();

            // First pass over a character walks its whole journal; later passes
            // only pick up what is new. Recorded so the UI can distinguish
            // "no flags found" from "never actually looked yet".
            $table->boolean('backfilled')->default(false);

            // Consecutive passes that stopped without moving the watermark
            // because a counterparty could not be identified. Usually ESI being
            // briefly unavailable, and holding position is right for that. But
            // /universe/names/ rejects an entire batch if one id in it is
            // invalid, so a single long-dead character could otherwise stall
            // this member's scan permanently. Counted so the scan can give up
            // on those rows after a few tries rather than never moving again.
            $table->unsignedTinyInteger('halt_count')->default(0);

            $table->timestamps();

            $table->index('scanned_at', 'hr_donscan_scanned_idx');
        });
    }

    /** Forward-only: the released schema never drops tables. */
    public function down(): void
    {
        // no-op
    }
}

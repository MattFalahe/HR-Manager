<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watchlist (blacklist + whitelist) for HR Manager.
 *
 * Both lists live in the same table with a `list_type` discriminator,
 * since each character can only be on ONE list per corporation scope
 * (mutually exclusive). The unique constraint enforces that.
 *
 * Entries are keyed by character_id (canonical) with a snapshot of
 * character_name at add time so the entry stays meaningful even if
 * the character is later renamed or if SeAT's character_infos cache
 * doesn't have the row (entries can be added by name or ID without
 * the character being authed in SeAT — name resolution dispatches a
 * SeAT names job in the background).
 *
 * Scope: per-corporation by default. NULL scope_corporation_id means
 * the entry is global across every corp the operator has access to.
 *
 * Reason + severity are admin-authored notes — severity drives the
 * blacklist banner color on application review (low = info, medium =
 * warning, high = critical red).
 */
class CreateWatchlistTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_watchlist_entries')) {
            return;
        }

        Schema::create('hr_manager_watchlist_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('list_type', ['blacklist', 'whitelist']);
            // NULL = global entry that matches in every corp the
            // operator has access to. Per-corp entries are filtered
            // out for other corps.
            $table->unsignedBigInteger('scope_corporation_id')->nullable();
            // Canonical character_id. Snapshot of the name at add
            // time means we don't depend on character_infos staying
            // populated.
            $table->unsignedBigInteger('character_id');
            $table->string('character_name', 64)->nullable();
            $table->text('reason')->nullable();
            // Only meaningful for blacklist — whitelist entries
            // ignore this column. low / medium / high drives the
            // visual severity on the application review banner.
            $table->enum('severity', ['low', 'medium', 'high'])->default('medium');
            $table->unsignedBigInteger('added_by');
            $table->timestamp('added_at')->useCurrent();
            // Optional auto-expiry. Whitelist entries for "5y tenure"
            // probably never expire; blacklist entries for "spy in
            // wormhole event" might be time-bound.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Unique (list_type, scope, character_id) — one character
            // can only be on each list once per scope. Different
            // scopes (different corps) can each have their own entry.
            $table->unique(
                ['list_type', 'scope_corporation_id', 'character_id'],
                'hr_watchlist_uniqueness'
            );
            $table->index('character_id');
            $table->index('scope_corporation_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manager_watchlist_entries');
    }
}

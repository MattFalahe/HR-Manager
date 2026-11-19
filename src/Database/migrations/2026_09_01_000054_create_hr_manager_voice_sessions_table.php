<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per stretch of time a SeAT account was seen in a Discord voice
 * channel.
 *
 * Built from polling: every few minutes HR asks Discord whether each account
 * linked through SeAT Connector is in voice. A session opens at the first
 * sample that finds them, stays open while later samples keep finding them in
 * the same channel, and closes when one does not. Each sample stands for its
 * whole interval, so started_at is set half an interval before the first
 * sighting and ended_at half an interval after the last: a single sighting
 * reads as one interval long, which is the honest middle of what it could be.
 *
 * Keyed on the SeAT account, not a character: Discord has one identity per
 * person, and that is the level HR judges people at.
 */
class CreateHrManagerVoiceSessionsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_manager_voice_sessions')) {
            return;
        }

        Schema::create('hr_manager_voice_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id');
            $table->string('discord_user_id', 32);

            // Null when Discord refused to say which channel: the member is in
            // voice somewhere the bot has no Connect permission on.
            $table->string('channel_id', 32)->nullable();
            $table->string('channel_name', 100)->nullable();
            $table->boolean('hidden_channel')->default(false);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('samples')->default(1);

            $table->timestamps();

            $table->index(['user_id', 'ended_at'], 'hr_voice_sess_user_open_idx');
            $table->index(['user_id', 'started_at'], 'hr_voice_sess_user_start_idx');
            $table->index('started_at', 'hr_voice_sess_start_idx');
        });
    }

    /** Forward-only: the released schema never drops tables. */
    public function down(): void
    {
        // no-op
    }
}

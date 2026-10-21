<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction. It also does not block writes while it builds.
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     *
     * Partial index for the nightly purge: the user messages that still have a recording, in id order.
     * The purge then reads only those rows instead of the whole table.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // A run that failed while building leaves an INVALID index behind, and "already exists" would stop every later run.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS debate_messages_user_audio_id_index');
        DB::statement(
            "CREATE INDEX CONCURRENTLY debate_messages_user_audio_id_index ON debate_messages (id) WHERE role = 'user' AND audio_path IS NOT NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS debate_messages_user_audio_id_index');
    }
};

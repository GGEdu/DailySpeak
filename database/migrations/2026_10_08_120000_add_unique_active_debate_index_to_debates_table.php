<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A user has at most one active debate per article. The partial unique index is what makes
     * User::startDebate() safe under a double submit. Duplicates left by the old firstOrCreate race
     * are completed first (the oldest one stays active), so the index can be created. Nothing is deleted.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE debates AS newer
            SET status = 'completed', ended_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE newer.status = 'active'
              AND EXISTS (
                  SELECT 1
                  FROM debates AS older
                  WHERE older.user_id = newer.user_id
                    AND older.news_article_id = newer.news_article_id
                    AND older.status = 'active'
                    AND older.id < newer.id
              )
            SQL);

        DB::statement(
            "CREATE UNIQUE INDEX debates_one_active_per_article ON debates (user_id, news_article_id) WHERE status = 'active'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS debates_one_active_per_article');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction. It also builds without locking out writes,
     * so the harvester keeps saving stories while the indexes are created.
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     *
     * Trigram indexes let the fallback search (ILIKE '%term%') use an index instead of reading every story.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        // A run that failed while building leaves an invalid index behind: drop it, or "IF NOT EXISTS" would keep it.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS news_articles_title_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS news_articles_summary_trgm');
        DB::statement('CREATE INDEX CONCURRENTLY news_articles_title_trgm ON news_articles USING gin (title gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY news_articles_summary_trgm ON news_articles USING gin (summary gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     *
     * The pg_trgm extension stays: dropping it is not needed to undo the indexes, and other objects may use it.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS news_articles_summary_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS news_articles_title_trgm');
    }
};

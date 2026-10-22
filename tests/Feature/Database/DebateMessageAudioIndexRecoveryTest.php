<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DebateMessageAudioIndexRecoveryTest extends TestCase
{
    // Not RefreshDatabase: CREATE INDEX CONCURRENTLY cannot run inside a transaction, and that trait wraps each test in one.
    use DatabaseMigrations;

    private const INDEX = 'debate_messages_user_audio_id_index';

    private const MIGRATION = 'database/migrations/2026_10_21_150000_add_user_audio_index_to_debate_messages_table.php';

    private const TUTOR_INDEX = 'debate_messages_assistant_audio_id_index';

    private const TUTOR_MIGRATION = 'database/migrations/2026_10_22_100000_add_tutor_audio_index_to_debate_messages_table.php';

    public function test_a_leftover_index_with_the_same_name_does_not_stop_the_migration(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial indexes are PostgreSQL only.');
        }

        // What a failed build leaves behind: an index that has the name but not the definition.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        DB::statement('CREATE INDEX '.self::INDEX.' ON debate_messages (id)');

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $this->assertSame('valid', $this->indexState());
        $this->assertMatchesRegularExpression(
            "/WHERE .*role.*'user'.*audio_path IS NOT NULL/",
            (string) DB::table('pg_indexes')->where('indexname', self::INDEX)->value('indexdef'),
        );
    }

    public function test_a_leftover_tutor_index_with_the_same_name_does_not_stop_its_migration(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial indexes are PostgreSQL only.');
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::TUTOR_INDEX);
        DB::statement('CREATE INDEX '.self::TUTOR_INDEX.' ON debate_messages (id)');

        $migration = require base_path(self::TUTOR_MIGRATION);
        $migration->up();

        $this->assertSame('valid', $this->indexState(self::TUTOR_INDEX));
        $this->assertMatchesRegularExpression(
            "/WHERE .*role.*'assistant'.*audio_path IS NOT NULL/",
            (string) DB::table('pg_indexes')->where('indexname', self::TUTOR_INDEX)->value('indexdef'),
        );
    }

    public function test_down_drops_the_index(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial indexes are PostgreSQL only.');
        }

        $this->assertSame('valid', $this->indexState());

        $migration = require base_path(self::MIGRATION);
        $migration->down();

        $this->assertNull($this->indexState());
    }

    /**
     * 'valid' or 'invalid' for the index, null when it does not exist. The catalog's boolean is read as text by
     * PDO, so it is compared in SQL.
     */
    private function indexState(string $index = self::INDEX): ?string
    {
        return DB::table('pg_index')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
            ->where('pg_class.relname', $index)
            ->selectRaw("CASE WHEN pg_index.indisvalid THEN 'valid' ELSE 'invalid' END AS state")
            ->value('state');
    }
}

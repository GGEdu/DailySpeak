<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DebateMessageAudioIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_nightly_purge_has_a_partial_index_on_user_recordings(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial indexes are PostgreSQL only.');
        }

        $definition = DB::table('pg_indexes')
            ->where('indexname', 'debate_messages_user_audio_id_index')
            ->value('indexdef');

        $this->assertNotNull($definition);
        $this->assertMatchesRegularExpression('/\(id\)/', $definition);
        $this->assertMatchesRegularExpression("/WHERE .*role.*'user'.*audio_path IS NOT NULL/", $definition);
    }
}

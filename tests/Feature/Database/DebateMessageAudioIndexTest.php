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

    public function test_the_nightly_purge_has_a_partial_index_on_tutor_replies(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial indexes are PostgreSQL only.');
        }

        $definition = DB::table('pg_indexes')
            ->where('indexname', 'debate_messages_assistant_audio_id_index')
            ->value('indexdef');

        $this->assertNotNull($definition);
        $this->assertMatchesRegularExpression('/\(id\)/', $definition);
        $this->assertMatchesRegularExpression("/WHERE .*role.*'assistant'.*audio_path IS NOT NULL/", $definition);
    }

    public function test_the_purge_queries_for_both_roles_can_use_their_partial_index(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial indexes are PostgreSQL only.');
        }

        // The tables are empty here, so the planner would scan them; with sequential scans off it must pick the index.
        DB::statement('SET enable_seqscan = off');

        try {
            foreach (['user' => 'debate_messages_user_audio_id_index', 'assistant' => 'debate_messages_assistant_audio_id_index'] as $role => $index) {
                $plan = collect(DB::select(
                    'EXPLAIN SELECT id FROM debate_messages WHERE role = ? AND audio_path IS NOT NULL AND created_at < ? ORDER BY id LIMIT 500',
                    [$role, now()],
                ))->pluck('QUERY PLAN')->implode("\n");

                $this->assertStringContainsString($index, $plan, "The {$role} purge does not use {$index}:\n{$plan}");
            }
        } finally {
            DB::statement('RESET enable_seqscan');
        }
    }
}

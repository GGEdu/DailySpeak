<?php

namespace Tests\Feature\Web;

use App\Enums\DebateStatus;
use App\Jobs\EvaluateDebate;
use App\Models\Debate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinishDebateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_finishing_closes_the_debate_and_queues_the_report(): void
    {
        $this->freezeSecond();
        $debate = Debate::factory()->create();

        $this->actingAs($debate->user)
            ->post("/debates/{$debate->id}/finish")
            ->assertRedirect("/debates/{$debate->id}");

        $debate->refresh();
        $this->assertSame(DebateStatus::Completed, $debate->status);
        $this->assertTrue($debate->ended_at->equalTo(now()));
        Queue::assertPushedOn('debates', EvaluateDebate::class, fn (EvaluateDebate $job) => $job->debate->is($debate));
    }

    public function test_a_failed_report_can_be_requested_again(): void
    {
        $debate = Debate::factory()->create();
        $debate->finish();
        $endedAt = $debate->ended_at;

        $this->travel(5)->minutes();
        $this->actingAs($debate->user)->post("/debates/{$debate->id}/finish")->assertRedirect();

        Queue::assertPushed(EvaluateDebate::class);
        $this->assertTrue($debate->fresh()->ended_at->equalTo($endedAt));
    }

    public function test_a_debate_that_already_has_its_report_cannot_be_finished_again(): void
    {
        $debate = Debate::factory()->completed()->create();

        $this->actingAs($debate->user)->post("/debates/{$debate->id}/finish")->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_finished_debates_do_not_accept_new_voice_turns(): void
    {
        $debate = Debate::factory()->create();
        $this->actingAs($debate->user)->post("/debates/{$debate->id}/finish");

        $this->actingAs($debate->user, 'web')
            ->postJson("/api/debates/{$debate->id}/audio", ['audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm')])
            ->assertForbidden();
    }

    public function test_only_the_owner_can_finish_a_debate(): void
    {
        $debate = Debate::factory()->create();

        $this->actingAs(User::factory()->create())->post("/debates/{$debate->id}/finish")->assertForbidden();
        $this->post('/logout');
        $this->post("/debates/{$debate->id}/finish")->assertRedirect('/login');

        $this->assertSame(DebateStatus::Active, $debate->fresh()->status);
    }

    public function test_the_report_is_part_of_the_debate_page(): void
    {
        $debate = Debate::factory()->completed()->create();

        $this->actingAs($debate->user)
            ->get("/debates/{$debate->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('debate.status', 'completed')
                ->where('debate.ai_feedback.crutch_words', ['very', 'thing'])
                ->has('debate.ai_feedback.recommended_vocabulary', 1));
    }
}

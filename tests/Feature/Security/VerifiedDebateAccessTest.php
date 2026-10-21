<?php

namespace Tests\Feature\Security;

use App\Enums\DebateStatus;
use App\Jobs\EvaluateDebate;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Starting, opening, finishing and speaking in a debate spend AI budget or open a paid session, so they
 * sit behind the "verified" middleware. Dropping it from either route group must fail these tests.
 */
class VerifiedDebateAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $unverified;

    private Debate $debate;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        $this->unverified = User::factory()->unverified()->create();
        $this->debate = Debate::factory()->for($this->unverified)->create();
    }

    public function test_unverified_users_cannot_send_a_voice_turn(): void
    {
        Sanctum::actingAs($this->unverified);

        $this->postJson("/api/debates/{$this->debate->id}/audio", ['audio' => $this->recording()])
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_unverified_users_cannot_start_a_debate_through_the_api(): void
    {
        $article = NewsArticle::factory()->create();
        Sanctum::actingAs($this->unverified);

        $this->postJson("/api/news-articles/{$article->id}/debates")->assertForbidden();

        $this->assertSame(1, Debate::count(), 'The request must not create a debate.');
        Queue::assertNothingPushed();
    }

    public function test_unverified_users_cannot_start_a_debate_on_the_web(): void
    {
        $article = NewsArticle::factory()->create();

        $this->actingAs($this->unverified)
            ->post("/news-articles/{$article->id}/debate")
            ->assertRedirect('/email/verify');

        $this->assertSame(1, Debate::count(), 'The request must not create a debate.');
        Queue::assertNothingPushed();
    }

    public function test_unverified_users_cannot_open_a_debate(): void
    {
        $this->actingAs($this->unverified)
            ->get("/debates/{$this->debate->id}")
            ->assertRedirect('/email/verify');

        Queue::assertNothingPushed();
    }

    public function test_unverified_users_cannot_finish_a_debate(): void
    {
        $this->actingAs($this->unverified)
            ->post("/debates/{$this->debate->id}/finish")
            ->assertRedirect('/email/verify');

        $this->assertSame(DebateStatus::Active, $this->debate->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_verified_owners_reach_the_same_pages(): void
    {
        // Control: the refusals above come from the verification check, not from the debate itself.
        $owner = User::factory()->create();
        $debate = Debate::factory()->for($owner)->create();
        $article = NewsArticle::factory()->create();

        $this->actingAs($owner)->get("/debates/{$debate->id}")->assertOk();
        $this->actingAs($owner)->post("/news-articles/{$article->id}/debate")->assertRedirect();
        $this->actingAs($owner)->post("/debates/{$debate->id}/finish")->assertRedirect("/debates/{$debate->id}");

        Queue::assertPushed(EvaluateDebate::class, 1);
    }

    private function recording(): UploadedFile
    {
        return UploadedFile::fake()->create('turn.webm', 50, 'audio/webm');
    }
}

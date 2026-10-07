<?php

namespace Tests\Feature\Web;

use App\Enums\MessageRole;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DebatePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_debate_opens_it(): void
    {
        $user = User::factory()->create();
        $article = NewsArticle::factory()->create();

        $response = $this->actingAs($user)->post("/news-articles/{$article->id}/debate");

        $debate = $user->debates()->sole();
        $response->assertRedirect("/debates/{$debate->id}");
        $this->assertTrue($debate->newsArticle->is($article));

        // Starting again resumes the same active debate.
        $this->actingAs($user)->post("/news-articles/{$article->id}/debate")->assertRedirect("/debates/{$debate->id}");
        $this->assertSame(1, $user->debates()->count());
    }

    public function test_the_debate_page_has_everything_the_voice_client_needs(): void
    {
        $debate = Debate::factory()->create();
        DebateMessage::factory()->for($debate)->fromUser()->create(['transcript' => 'Opening argument.']);
        DebateMessage::factory()->for($debate)->fromAssistant()->create(['transcript' => 'Why do you think so?']);

        $this->actingAs($debate->user)
            ->get("/debates/{$debate->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Debate')
                ->where('debate.id', $debate->id)
                ->where('debate.status', 'active')
                ->where('debate.channel', "debates.{$debate->id}")
                ->where('debate.audio_upload_url', url("/api/debates/{$debate->id}/audio"))
                ->where('article.id', $debate->news_article_id)
                ->has('article.key_vocabulary', 5)
                ->has('messages', 2)
                ->where('messages.0.role', MessageRole::User->value)
                ->where('messages.0.transcript', 'Opening argument.')
                ->where('messages.1.role', MessageRole::Assistant->value)
                ->where('messages.1.audio_url', fn (string $url) => str_contains($url, 'signature=')));
    }

    public function test_users_cannot_open_someone_elses_debate(): void
    {
        $debate = Debate::factory()->create();

        $this->actingAs(User::factory()->create())->get("/debates/{$debate->id}")->assertForbidden();
    }

    public function test_guests_must_log_in_to_debate(): void
    {
        $debate = Debate::factory()->create();

        $this->get("/debates/{$debate->id}")->assertRedirect('/login');
        $this->post("/news-articles/{$debate->news_article_id}/debate")->assertRedirect('/login');
    }

    public function test_the_web_client_can_upload_turns_to_the_api_with_its_session(): void
    {
        $debate = Debate::factory()->create();

        // auth:sanctum accepts the web (session) guard, so the Inertia page can call the API directly.
        $this->actingAs($debate->user, 'web')
            ->withHeader('Origin', config('app.url'))
            ->postJson("/api/debates/{$debate->id}/audio")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audio');
    }
}

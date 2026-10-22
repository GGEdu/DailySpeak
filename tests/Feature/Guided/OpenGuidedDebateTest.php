<?php

namespace Tests\Feature\Guided;

use App\Enums\EnglishLevel;
use App\Enums\MessageRole;
use App\Events\TutorOpened;
use App\Jobs\OpenGuidedDebate;
use App\Models\Debate;
use App\Models\DebateBrief;
use App\Models\DebateMessage;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Prompts\AudioPrompt;
use RuntimeException;
use Tests\TestCase;

class OpenGuidedDebateTest extends TestCase
{
    use RefreshDatabase;

    private const OPENING = 'The council has voted to scrap bus fares. Should public transport be free for everyone?';

    private Debate $debate;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Event::fake([TutorOpened::class]);

        $article = NewsArticle::factory()->create();
        DebateBrief::factory()->for($article, 'newsArticle')->create(['opening' => self::OPENING, 'level' => EnglishLevel::B1]);
        $this->debate = Debate::factory()->guided(EnglishLevel::B1)->for(User::factory())->for($article)->create();
    }

    public function test_the_tutor_opens_with_the_brief_opening_and_speaks_it(): void
    {
        Audio::fake([base64_encode('opening-mp3')])->preventStrayAudio();

        OpenGuidedDebate::dispatch($this->debate);

        $opening = $this->debate->messages()->sole();
        $this->assertSame(MessageRole::Assistant, $opening->role);
        $this->assertSame(self::OPENING, $opening->transcript);
        $this->assertNull($opening->reply_to_id);
        $this->assertStringStartsWith("debates/{$this->debate->id}/replies/", $opening->audio_path);
        $this->assertSame('opening-mp3', Storage::disk('local')->get($opening->audio_path));

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === self::OPENING
            && $prompt->voice === config('debate.tts.voice'));
        Event::assertDispatched(TutorOpened::class, fn (TutorOpened $event) => $event->message->is($opening));
    }

    public function test_the_opening_event_carries_the_text_and_a_playable_audio_url(): void
    {
        Audio::fake([base64_encode('opening-mp3')]);

        OpenGuidedDebate::dispatch($this->debate);

        $opening = $this->debate->messages()->sole();
        $payload = (new TutorOpened($this->debate, $opening))->broadcastWith();

        $this->assertSame($this->debate->id, $payload['debate_id']);
        $this->assertSame($opening->id, $payload['message_id']);
        $this->assertSame(self::OPENING, $payload['transcript']);
        $this->assertIsString($payload['audio_url']);
    }

    public function test_a_debate_that_already_has_messages_is_not_opened_again(): void
    {
        Audio::fake([base64_encode('never-used')]);
        DebateMessage::factory()->for($this->debate)->fromUser()->create();

        OpenGuidedDebate::dispatch($this->debate);

        $this->assertSame(1, $this->debate->messages()->count());
        $this->assertSame(MessageRole::User, $this->debate->messages()->sole()->role);
        Audio::assertNothingGenerated();
        Event::assertNotDispatched(TutorOpened::class);
    }

    public function test_the_opening_is_written_once_when_the_job_runs_twice(): void
    {
        Audio::fake([base64_encode('opening-mp3')]);

        OpenGuidedDebate::dispatch($this->debate);
        OpenGuidedDebate::dispatch($this->debate);

        $this->assertSame(1, $this->debate->messages()->count());
    }

    public function test_a_failed_voice_leaves_the_opening_as_text(): void
    {
        config(['debate.tts.fallback.model' => null]);
        Audio::fake(fn () => throw new RuntimeException('Voice down'));

        OpenGuidedDebate::dispatch($this->debate);

        $opening = $this->debate->messages()->sole();
        $this->assertSame(self::OPENING, $opening->transcript);
        $this->assertNull($opening->audio_path);
        Event::assertDispatched(TutorOpened::class, fn (TutorOpened $event) => $event->message->is($opening)
            && $event->message->audioUrl() === null);
    }

    public function test_nothing_opens_without_a_brief_or_in_a_finished_debate(): void
    {
        Audio::fake([base64_encode('never-used')]);
        DebateBrief::query()->delete();

        OpenGuidedDebate::dispatch($this->debate);
        $this->assertSame(0, $this->debate->messages()->count());

        DebateBrief::factory()->for($this->debate->newsArticle, 'newsArticle')->create(['level' => EnglishLevel::B1]);
        $this->debate->finish();

        OpenGuidedDebate::dispatch($this->debate);
        $this->assertSame(0, $this->debate->messages()->count());
        Audio::assertNothingGenerated();
    }

    public function test_the_opening_shares_the_debate_lock_with_the_voice_turns(): void
    {
        $job = new OpenGuidedDebate($this->debate);
        $middleware = $job->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        $this->assertSame(1, $middleware->releaseAfter);
        // Shared: the key is the debate's, whichever job class takes it.
        $this->assertSame('laravel-queue-overlap:'.$this->debate->jobLockKey(), $middleware->getLockKey($job));
    }
}

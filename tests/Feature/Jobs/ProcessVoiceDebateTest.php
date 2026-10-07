<?php

namespace Tests\Feature\Jobs;

use App\Ai\Agents\DebateTutor;
use App\Enums\EnglishLevel;
use App\Enums\MessageRole;
use App\Events\AIResponseGenerated;
use App\Events\DebateTurnFailed;
use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Transcription;
use RuntimeException;
use Tests\TestCase;

class ProcessVoiceDebateTest extends TestCase
{
    use RefreshDatabase;

    private const RECORDING = 'debates/1/turn.webm';

    private const USER_SAYS = 'I think public transport should be completely free.';

    private const TUTOR_SAYS = 'Free for whom, though? Someone still has to pay for it.';

    private Debate $debate;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put(self::RECORDING, 'recorded-audio');
        Event::fake([AIResponseGenerated::class, DebateTurnFailed::class]);

        $this->debate = Debate::factory()
            ->for(User::factory()->level(EnglishLevel::B2))
            ->for(NewsArticle::factory()->state([
                'title' => 'City approves free public transport',
                'summary' => 'The council voted to scrap bus fares from next year.',
            ]))
            ->create();
    }

    public function test_it_transcribes_the_turn_answers_it_and_broadcasts_the_reply(): void
    {
        Transcription::fake([self::USER_SAYS])->preventStrayTranscriptions();
        DebateTutor::fake([self::TUTOR_SAYS])->preventStrayPrompts();
        Audio::fake([base64_encode('synthesised-mp3')])->preventStrayAudio();

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        [$turn, $reply] = $this->debate->messages()->orderBy('id')->get()->all();

        $this->assertSame(MessageRole::User, $turn->role);
        $this->assertSame(self::USER_SAYS, $turn->transcript);
        $this->assertSame(self::RECORDING, $turn->audio_path);

        $this->assertSame(MessageRole::Assistant, $reply->role);
        $this->assertSame(self::TUTOR_SAYS, $reply->transcript);
        $this->assertStringStartsWith("debates/{$this->debate->id}/", $reply->audio_path);
        $this->assertStringEndsWith('.mp3', $reply->audio_path);
        $this->assertSame('synthesised-mp3', Storage::disk('local')->get($reply->audio_path));

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->audio->content() === 'recorded-audio'
            && $prompt->language === 'en'
            && $prompt->provider->name() === 'openai'
            && $prompt->model === 'whisper-1');

        DebateTutor::assertPrompted(function (AgentPrompt $prompt) {
            $instructions = (string) $prompt->agent->instructions();

            return $prompt->prompt === self::USER_SAYS
                && $prompt->provider->name() === 'gemini'
                && str_contains($instructions, 'You are an expert native English tutor and debate partner.')
                && str_contains($instructions, 'Title: City approves free public transport')
                && str_contains($instructions, 'The council voted to scrap bus fares from next year.')
                && str_contains($instructions, 'The user is an advanced (B2) English speaker.')
                && str_contains($instructions, 'Do NOT correct grammar');
        });

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === self::TUTOR_SAYS && $prompt->voice === 'alloy');

        Event::assertDispatched(AIResponseGenerated::class, fn (AIResponseGenerated $event) => $event->reply->is($reply)
            && $event->turn->is($turn));
        Event::assertNotDispatched(DebateTurnFailed::class);
    }

    public function test_the_tutor_receives_the_previous_turns_as_history(): void
    {
        $earlier = [
            DebateMessage::factory()->for($this->debate)->fromUser()->create(['transcript' => 'Opening argument.']),
            DebateMessage::factory()->for($this->debate)->fromAssistant()->create(['transcript' => 'Counter argument.']),
        ];
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake([base64_encode('mp3')]);

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        DebateTutor::assertPrompted(function (AgentPrompt $prompt) use ($earlier) {
            $history = collect($prompt->agent->messages());

            return $history->count() === 2
                && $history[0] instanceof UserMessage && $history[0]->content === $earlier[0]->transcript
                && $history[1] instanceof AssistantMessage && $history[1]->content === $earlier[1]->transcript;
        });
        $this->assertSame(4, $this->debate->messages()->count());
    }

    public function test_it_uses_the_configured_models_and_voice(): void
    {
        config([
            'debate.stt' => ['provider' => 'groq', 'model' => 'whisper-large-v3-turbo', 'language' => 'en'],
            'debate.llm.provider' => 'openai',
            'debate.llm.model' => 'gpt-4o-mini',
            'debate.tts' => ['provider' => 'eleven', 'model' => 'eleven_flash_v2_5', 'voice' => 'voice-123'],
        ]);
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake([base64_encode('mp3')]);

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->provider->name() === 'groq'
            && $prompt->model === 'whisper-large-v3-turbo');
        DebateTutor::assertPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() === 'openai'
            && $prompt->model === 'gpt-4o-mini');
        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->provider->name() === 'eleven'
            && $prompt->model === 'eleven_flash_v2_5'
            && $prompt->voice === 'voice-123');
    }

    public function test_silence_is_reported_without_asking_the_tutor(): void
    {
        Transcription::fake(['  ']);
        DebateTutor::fake();
        Audio::fake();

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        $this->assertSame(0, $this->debate->messages()->count());
        DebateTutor::assertNeverPrompted();
        Audio::assertNothingGenerated();
        Event::assertDispatched(DebateTurnFailed::class, fn (DebateTurnFailed $event) => $event->reason === DebateTurnFailed::NO_SPEECH);
        Event::assertNotDispatched(AIResponseGenerated::class);
    }

    public function test_a_retried_job_reuses_the_turn_that_was_already_transcribed(): void
    {
        DebateMessage::factory()->for($this->debate)->create([
            'role' => MessageRole::User,
            'transcript' => self::USER_SAYS,
            'audio_path' => self::RECORDING,
        ]);
        Transcription::fake();
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake([base64_encode('mp3')]);

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        Transcription::assertNothingGenerated();
        $this->assertSame(1, $this->debate->messages()->where('role', MessageRole::User)->count());
        $this->assertSame(1, $this->debate->messages()->where('role', MessageRole::Assistant)->count());
    }

    public function test_the_client_is_told_when_the_turn_cannot_be_answered(): void
    {
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake(fn () => throw new RuntimeException('Provider overloaded'));
        Audio::fake();

        try {
            ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);
            $this->fail('The job should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Provider overloaded', $e->getMessage());
        }

        $this->assertSame(0, $this->debate->messages()->where('role', MessageRole::Assistant)->count());
        Audio::assertNothingGenerated();
        Event::assertDispatched(DebateTurnFailed::class, fn (DebateTurnFailed $event) => $event->debate->is($this->debate)
            && $event->reason === DebateTurnFailed::PROCESSING_FAILED);
    }

    public function test_a_broadcasting_outage_does_not_fail_an_answered_turn(): void
    {
        // Really broadcast the reply, to a Reverb server that is not listening.
        Event::fake([DebateTurnFailed::class]);
        Exceptions::fake();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => array_merge(config('broadcasting.connections.reverb'), [
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
            ]),
        ]);
        Broadcast::purge();
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake([base64_encode('mp3')]);

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        $this->assertSame(1, $this->debate->messages()->where('role', MessageRole::Assistant)->count());
        Exceptions::assertReported(BroadcastException::class);
        Event::assertNotDispatched(DebateTurnFailed::class);
    }
}

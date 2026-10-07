<?php

namespace Tests\Feature\Jobs;

use App\Ai\Agents\DebateTutor;
use App\Enums\EnglishLevel;
use App\Enums\MessageRole;
use App\Events\AIResponseGenerated;
use App\Events\DebateTurnFailed;
use App\Events\UserTurnTranscribed;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Transcription;
use Mockery;
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
        Event::fake([UserTurnTranscribed::class, AIResponseGenerated::class, DebateTurnFailed::class]);

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
        $this->assertStringStartsWith("debates/{$this->debate->id}/replies/", $reply->audio_path);
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
                && str_contains($instructions, 'The user is an upper-intermediate (B2) English speaker.')
                && str_contains($instructions, '5. Speak naturally and use some idiomatic expressions')
                && str_contains($instructions, 'Do NOT correct grammar');
        });

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === self::TUTOR_SAYS && $prompt->voice === 'alloy');

        Event::assertDispatched(UserTurnTranscribed::class, fn (UserTurnTranscribed $event) => $event->turn->is($turn));
        Event::assertDispatched(AIResponseGenerated::class, fn (AIResponseGenerated $event) => $event->reply->is($reply)
            && $event->turn->is($turn));
        Event::assertNotDispatched(DebateTurnFailed::class);
    }

    public function test_the_transcript_is_broadcast_before_the_tutor_answers(): void
    {
        Transcription::fake([self::USER_SAYS]);
        Audio::fake([base64_encode('mp3')]);
        DebateTutor::fake(function () {
            // By the time the tutor is asked, the client has already been told what was understood.
            Event::assertDispatched(UserTurnTranscribed::class);

            return self::TUTOR_SAYS;
        });

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        DebateTutor::assertPromptedTimes(1);
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

    public function test_only_the_most_recent_messages_are_sent_as_history(): void
    {
        config(['debate.llm.history_messages' => 2]);
        foreach (['First claim.', 'First reply.', 'Second claim.', 'Second reply.'] as $i => $transcript) {
            DebateMessage::factory()->for($this->debate)->state(['role' => $i % 2 ? MessageRole::Assistant : MessageRole::User])
                ->create(['transcript' => $transcript]);
        }
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake([base64_encode('mp3')]);

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        DebateTutor::assertPrompted(fn (AgentPrompt $prompt) => collect($prompt->agent->messages())
            ->map(fn ($message) => $message->content)
            ->all() === ['Second claim.', 'Second reply.']);
    }

    public function test_the_configured_options_are_sent_to_the_tutors_provider(): void
    {
        // A faster, low-effort answer from an OpenAI-compatible reasoning model.
        config([
            'ai.providers.nvidia' => ['driver' => 'openai-compatible', 'url' => 'https://llm.test/v1', 'key' => 'test-key'],
            'debate.llm.provider' => 'nvidia',
            'debate.llm.model' => 'openai/gpt-oss-20b',
            'debate.llm.options' => ['reasoning_effort' => 'low'],
        ]);
        Http::preventStrayRequests()->fake(['llm.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-1',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => self::TUTOR_SAYS], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ])]);
        Transcription::fake([self::USER_SAYS]);
        Audio::fake([base64_encode('mp3')]);

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        Http::assertSent(fn ($request) => $request['model'] === 'openai/gpt-oss-20b' && $request['reasoning_effort'] === 'low');
        $this->assertSame(self::TUTOR_SAYS, $this->debate->messages()->latest('id')->value('transcript'));
    }

    public function test_each_answered_turn_logs_where_the_time_went(): void
    {
        Log::spy();
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake([base64_encode('mp3')]);

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        $reply = $this->debate->messages()->where('role', MessageRole::Assistant)->sole();
        $timings = Mockery::on(fn (array $context) => $context['debate_id'] === $this->debate->id
            && $context['message_id'] === $reply->id
            && $context['llm'] === 'gemini/default'
            && collect(['queue_ms', 'stt_ms', 'llm_ms', 'tts_ms', 'total_ms'])->every(fn (string $key) => is_int($context[$key]) && $context[$key] >= 0)
            && $context['total_ms'] >= $context['stt_ms'] + $context['llm_ms'] + $context['tts_ms']);

        Log::shouldHaveReceived('info')->once()->with('Voice turn answered.', $timings);
    }

    public function test_it_uses_the_configured_models_and_voice(): void
    {
        config([
            'debate.stt' => ['provider' => 'groq', 'model' => 'whisper-large-v3-turbo', 'language' => 'en', 'timeout' => 30],
            'debate.llm.provider' => 'openai',
            'debate.llm.model' => 'gpt-4o-mini',
            'debate.tts' => ['provider' => 'eleven', 'model' => 'eleven_flash_v2_5', 'voice' => 'voice-123', 'timeout' => 30],
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
        Event::assertNotDispatched(UserTurnTranscribed::class);
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

    public function test_a_retry_after_a_failed_synthesis_does_not_ask_the_tutor_again(): void
    {
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake(fn () => throw new RuntimeException('Speech service down'));

        try {
            ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);
            $this->fail('The first attempt should have failed.');
        } catch (RuntimeException) {
            //
        }

        // The retry reuses the transcript and the reply text, and only repeats the speech synthesis.
        Audio::fake([base64_encode('mp3')]);
        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        DebateTutor::assertPromptedTimes(1);
        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === self::TUTOR_SAYS);
        $this->assertSame(1, $this->debate->messages()->where('role', MessageRole::Assistant)->count());
        $this->assertSame(self::TUTOR_SAYS, $this->debate->messages()->where('role', MessageRole::Assistant)->sole()->transcript);
    }

    public function test_a_turn_queued_before_the_debate_was_finished_is_dropped_without_a_reply(): void
    {
        // The debate was closed, and its report written, while this turn was waiting in the queue.
        $this->debate->finish();
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake([base64_encode('mp3')]);

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        Transcription::assertNothingGenerated();
        DebateTutor::assertNeverPrompted();
        Audio::assertNothingGenerated();
        $this->assertSame(0, $this->debate->messages()->count());
        Event::assertNotDispatched(UserTurnTranscribed::class);
        Event::assertNotDispatched(AIResponseGenerated::class);
        Event::assertNotDispatched(DebateTurnFailed::class);
    }

    public function test_each_provider_call_uses_its_configured_timeout(): void
    {
        config(['debate.stt.timeout' => 45, 'debate.llm.timeout' => 50, 'debate.tts.timeout' => 40]);
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake([base64_encode('mp3')]);

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->timeout === 45);
        DebateTutor::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->timeout() === 50);
        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->timeout === 40);
    }

    public function test_the_default_timeouts_fit_within_the_voice_turn_budget(): void
    {
        // The job is killed after its $timeout: STT, tutor and TTS run one after another inside it.
        $this->assertSame(30, config('debate.stt.timeout'));
        $this->assertSame(30, config('debate.llm.timeout'));
        $this->assertSame(30, config('debate.tts.timeout'));

        $job = new ProcessVoiceDebate($this->debate, self::RECORDING);

        // The backup voice only runs after the main one failed, inside the same turn.
        $this->assertLessThanOrEqual($job->timeout, config('debate.stt.timeout') + config('debate.llm.timeout')
            + config('debate.tts.timeout') + config('debate.tts.fallback.timeout'));
    }

    public function test_a_backup_voice_speaks_when_the_main_one_fails(): void
    {
        config(['debate.tts.fallback' => ['provider' => 'openai', 'model' => 'tts-backup', 'voice' => 'eve', 'timeout' => 20]]);
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS])->preventStrayPrompts();
        Audio::fake(fn (AudioPrompt $prompt) => $prompt->voice === 'eve'
            ? base64_encode('backup-mp3')
            : throw new RuntimeException('Main voice down'));

        ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);

        $reply = $this->debate->messages()->where('role', MessageRole::Assistant)->sole();
        $this->assertSame(self::TUTOR_SAYS, $reply->transcript);
        $this->assertSame('backup-mp3', Storage::disk('local')->get($reply->audio_path));
        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->model === 'tts-backup' && $prompt->voice === 'eve' && $prompt->timeout === 20);
        Event::assertDispatched(AIResponseGenerated::class);
    }

    public function test_without_a_backup_voice_a_failed_synthesis_fails_the_turn_as_before(): void
    {
        config(['debate.tts.fallback.model' => null]);
        Transcription::fake([self::USER_SAYS]);
        DebateTutor::fake([self::TUTOR_SAYS]);
        Audio::fake(fn () => throw new RuntimeException('Main voice down'));

        try {
            ProcessVoiceDebate::dispatch($this->debate, self::RECORDING);
        } catch (RuntimeException) {
        }

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === config('debate.tts.voice'));
        Audio::assertNotGenerated(fn (AudioPrompt $prompt) => $prompt->voice !== config('debate.tts.voice'));
        $this->assertSame(0, $this->debate->messages()->where('role', MessageRole::Assistant)->count());
    }
}

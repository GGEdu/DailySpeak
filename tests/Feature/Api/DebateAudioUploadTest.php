<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class DebateAudioUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Debate $debate;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        $this->user = User::factory()->create();
        $this->debate = Debate::factory()->for($this->user)->create();
    }

    public function test_a_recording_is_stored_and_queued_for_processing(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm')])
            ->assertAccepted();

        $files = Storage::disk('local')->files("debates/{$this->debate->id}/recordings");
        $this->assertCount(1, $files);
        // "audio/webm" is guessed as "weba", which Whisper rejects, so it is stored as ".webm".
        $this->assertStringEndsWith('.webm', $files[0]);
        // The turn id is the recording's name, so the client can match the events to this turn.
        $response->assertExactJson(['status' => 'processing', 'turn' => pathinfo($files[0], PATHINFO_FILENAME)]);

        Queue::assertPushedOn('debates', ProcessVoiceDebate::class, fn (ProcessVoiceDebate $job) => $job->debate->is($this->debate)
            && $job->audioPath === $files[0]
            && collect($job->middleware())->contains(fn ($middleware) => $middleware instanceof WithoutOverlapping));
    }

    public function test_common_browser_formats_are_accepted(): void
    {
        Sanctum::actingAs($this->user);

        foreach (['turn.ogg' => 'audio/ogg', 'turn.m4a' => 'audio/mp4', 'turn.mp3' => 'audio/mpeg', 'turn.wav' => 'audio/wav'] as $name => $mime) {
            $this->post($this->url(), ['audio' => UploadedFile::fake()->create($name, 50, $mime)])->assertAccepted();
        }

        Queue::assertPushed(ProcessVoiceDebate::class, 4);
    }

    public function test_the_recording_is_validated(): void
    {
        Sanctum::actingAs($this->user);
        $maxKilobytes = config('debate.audio.max_upload_kilobytes');

        $this->postJson($this->url())->assertJsonValidationErrors('audio');
        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->assertJsonValidationErrors('audio');
        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('long.webm', $maxKilobytes + 1, 'audio/webm')])
            ->assertJsonValidationErrors('audio');

        Queue::assertNothingPushed();
    }

    public function test_an_empty_recording_is_rejected_before_it_is_queued(): void
    {
        Sanctum::actingAs($this->user);

        // An empty upload would still be sent to the speech-to-text provider, and paid for.
        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 0, 'audio/webm')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audio');

        Queue::assertNothingPushed();
        $this->assertSame([], Storage::disk('local')->files("debates/{$this->debate->id}/recordings"));
    }

    public function test_a_text_file_renamed_to_webm_is_rejected(): void
    {
        Sanctum::actingAs($this->user);
        // A real file with real text in it, not a generated fake: the check looks at the content.
        $path = storage_path('framework/testing/renamed-note.txt');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("This is a note, not a recording.\n", 40));
        $this->beforeApplicationDestroyed(fn () => File::delete($path));

        $this->postJson($this->url(), ['audio' => new UploadedFile($path, 'turn.webm', 'audio/webm', null, true)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audio');

        Queue::assertNothingPushed();
    }

    public function test_users_cannot_speak_in_someone_elses_debate(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm')])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_finished_debates_do_not_accept_new_turns(): void
    {
        Sanctum::actingAs($this->user);
        $this->debate->update(['status' => 'completed', 'ended_at' => now()]);

        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm')])
            ->assertForbidden()
            ->assertJsonPath('message', 'This debate has already finished.');

        Queue::assertNothingPushed();
    }

    public function test_unverified_users_cannot_speak(): void
    {
        $unverified = User::factory()->unverified()->create();
        $debate = Debate::factory()->for($unverified)->create();
        Sanctum::actingAs($unverified);

        $this->postJson("/api/debates/{$debate->id}/audio", ['audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm')])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_guests_cannot_upload_recordings(): void
    {
        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm')])
            ->assertUnauthorized();
    }

    public function test_the_daily_number_of_voice_turns_is_capped(): void
    {
        config(['debate.audio.daily_turns' => 2]);
        Sanctum::actingAs($this->user);

        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 50, 'audio/webm')])->assertAccepted();
        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 50, 'audio/webm')])->assertAccepted();

        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 50, 'audio/webm')])
            ->assertStatus(429)
            ->assertJsonPath('message', "You have reached today's limit of voice turns. Try again later.");

        Queue::assertPushed(ProcessVoiceDebate::class, 2);
    }

    public function test_the_per_minute_limit_asks_the_user_to_slow_down(): void
    {
        Sanctum::actingAs($this->user);
        $audio = fn () => ['audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm')];

        for ($i = 0; $i < AppServiceProvider::AUDIO_UPLOADS_PER_MINUTE; $i++) {
            $this->post($this->url(), $audio())->assertAccepted();
        }

        $this->postJson($this->url(), $audio())
            ->assertStatus(429)
            ->assertJsonPath('message', 'You are sending voice turns too fast. Wait a moment and try again.');
    }

    public function test_the_daily_cap_does_not_apply_to_admins(): void
    {
        config(['debate.audio.daily_turns' => 1]);
        $admin = User::factory()->admin()->create();
        $debate = Debate::factory()->for($admin)->create();
        Sanctum::actingAs($admin);

        for ($i = 0; $i < 3; $i++) {
            $this->post("/api/debates/{$debate->id}/audio", ['audio' => UploadedFile::fake()->create('turn.webm', 50, 'audio/webm')])
                ->assertAccepted();
        }
    }

    public function test_the_default_daily_cap_is_300_turns(): void
    {
        $this->assertSame(300, config('debate.audio.daily_turns'));
    }

    public function test_the_same_upload_sent_twice_is_stored_and_queued_once(): void
    {
        Sanctum::actingAs($this->user);
        $turn = '3f9b2a6e-7c1d-4e8a-9b5f-2d6c8a1e4f70';

        $first = $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => $turn])
            ->assertAccepted()
            ->assertExactJson(['status' => 'processing', 'turn' => $turn]);
        // The client retried after its POST timed out: the same blob, the same turn.
        $second = $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => $turn])
            ->assertAccepted()
            ->assertExactJson(['status' => 'processing', 'turn' => $turn]);

        $this->assertSame($first->json('turn'), $second->json('turn'));
        $this->assertCount(1, Storage::disk('local')->files("debates/{$this->debate->id}/recordings"));
        Queue::assertPushedTimes(ProcessVoiceDebate::class, 1);
    }

    public function test_the_turn_is_recognised_whatever_the_case_of_its_id(): void
    {
        Sanctum::actingAs($this->user);
        $turn = '3f9b2a6e-7c1d-4e8a-9b5f-2d6c8a1e4f70';

        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => $turn])->assertAccepted();
        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => strtoupper($turn)])
            ->assertAccepted()
            ->assertJsonPath('turn', $turn);

        Queue::assertPushedTimes(ProcessVoiceDebate::class, 1);
    }

    public function test_a_recording_already_stored_under_the_turn_is_not_stored_again(): void
    {
        Sanctum::actingAs($this->user);
        $turn = '3f9b2a6e-7c1d-4e8a-9b5f-2d6c8a1e4f70';
        Storage::disk('local')->put("debates/{$this->debate->id}/recordings/{$turn}.mp3", 'earlier-copy');

        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => $turn])
            ->assertAccepted()
            ->assertJsonPath('turn', $turn);

        $this->assertSame(['earlier-copy'], [Storage::disk('local')->get("debates/{$this->debate->id}/recordings/{$turn}.mp3")]);
        $this->assertCount(1, Storage::disk('local')->files("debates/{$this->debate->id}/recordings"));
        Queue::assertNothingPushed();
    }

    public function test_a_turn_that_already_has_a_message_is_not_queued_again(): void
    {
        Sanctum::actingAs($this->user);
        $turn = '3f9b2a6e-7c1d-4e8a-9b5f-2d6c8a1e4f70';
        DebateMessage::factory()->for($this->debate)->fromUser()->create([
            'audio_path' => "debates/{$this->debate->id}/recordings/{$turn}.webm",
        ]);

        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => $turn])
            ->assertAccepted()
            ->assertJsonPath('turn', $turn);

        Queue::assertNothingPushed();
    }

    public function test_a_late_retry_is_still_recognised_after_its_recording_was_dropped(): void
    {
        Sanctum::actingAs($this->user);
        $turn = '3f9b2a6e-7c1d-4e8a-9b5f-2d6c8a1e4f70';
        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => $turn])->assertAccepted();
        // The turn failed for good and its recording was deleted; the retry arrives after that.
        Storage::disk('local')->deleteDirectory("debates/{$this->debate->id}/recordings");

        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => $turn])
            ->assertAccepted()
            ->assertJsonPath('turn', $turn);

        Queue::assertPushedTimes(ProcessVoiceDebate::class, 1);
    }

    public function test_a_turn_whose_dispatch_fails_leaves_nothing_behind_so_the_retry_queues_it(): void
    {
        Sanctum::actingAs($this->user);
        $turn = '3f9b2a6e-7c1d-4e8a-9b5f-2d6c8a1e4f70';

        $healthy = app(Dispatcher::class);

        // The queue is down: the job cannot be dispatched.
        $down = \Mockery::mock(Dispatcher::class);
        $down->shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is down.'));
        $this->app->instance(Dispatcher::class, $down);

        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => $turn])
            ->assertStatus(500);

        $this->assertSame([], Storage::disk('local')->files("debates/{$this->debate->id}/recordings"));

        // The queue is back: the client's retry of the same turn is queued, not answered as already received.
        $this->app->instance(Dispatcher::class, $healthy);

        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => $turn])
            ->assertAccepted()
            ->assertJsonPath('turn', $turn);

        Queue::assertPushedTimes(ProcessVoiceDebate::class, 1);
    }

    public function test_the_turn_id_must_be_a_uuid(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm'), 'turn' => '../../etc/passwd'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('turn');

        Queue::assertNothingPushed();
    }

    private function url(): string
    {
        return "/api/debates/{$this->debate->id}/audio";
    }
}

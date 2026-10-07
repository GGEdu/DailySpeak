<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
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

        $this->post($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 120, 'audio/webm')])
            ->assertAccepted()
            ->assertExactJson(['status' => 'processing']);

        $files = Storage::disk('local')->files("debates/{$this->debate->id}/recordings");
        $this->assertCount(1, $files);
        // "audio/webm" is guessed as "weba", which Whisper rejects, so it is stored as ".webm".
        $this->assertStringEndsWith('.webm', $files[0]);

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

    public function test_guests_cannot_upload_recordings(): void
    {
        $this->postJson($this->url(), ['audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm')])
            ->assertUnauthorized();
    }

    private function url(): string
    {
        return "/api/debates/{$this->debate->id}/audio";
    }
}

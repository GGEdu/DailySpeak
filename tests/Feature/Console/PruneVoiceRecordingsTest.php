<?php

namespace Tests\Feature\Console;

use App\Models\Debate;
use App\Models\DebateMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneVoiceRecordingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recordings_older_than_the_retention_period_are_deleted(): void
    {
        $disk = Storage::fake('local');
        $debate = Debate::factory()->create();

        $old = $this->userTurn($debate, 'old.webm', now()->subDays(8));
        $recent = $this->userTurn($debate, 'recent.webm', now()->subDays(6));
        $oldReply = DebateMessage::factory()->for($debate)->fromAssistant()->create([
            'audio_path' => "debates/{$debate->id}/replies/reply.mp3",
            'created_at' => now()->subDays(30),
        ]);
        $disk->put($oldReply->audio_path, 'tutor voice');

        $this->artisan('debates:prune-recordings')
            ->expectsOutputToContain('Deleted 1 voice recordings older than 7 days.')
            ->assertSuccessful();

        $this->assertNull($old->fresh()->audio_path);
        $this->assertSame('Old turn.', $old->fresh()->transcript); // the transcript is kept
        $disk->assertMissing("debates/{$debate->id}/recordings/old.webm");

        $this->assertNotNull($recent->fresh()->audio_path);
        $disk->assertExists($recent->audio_path);

        // Synthesised tutor replies are not personal data and stay.
        $this->assertNotNull($oldReply->fresh()->audio_path);
        $disk->assertExists($oldReply->audio_path);
    }

    public function test_orphan_recordings_are_deleted_too(): void
    {
        $disk = Storage::fake('local');
        $debate = Debate::factory()->create();
        // Recordings that never became a message: silence, or a turn that failed.
        $disk->put("debates/{$debate->id}/recordings/silence.webm", 'audio');
        $disk->put("debates/{$debate->id}/recordings/just-uploaded.webm", 'audio');
        touch($disk->path("debates/{$debate->id}/recordings/silence.webm"), now()->subDays(8)->getTimestamp());

        $this->artisan('debates:prune-recordings')->assertSuccessful();

        $disk->assertMissing("debates/{$debate->id}/recordings/silence.webm");
        $disk->assertExists("debates/{$debate->id}/recordings/just-uploaded.webm");
    }

    public function test_the_retention_period_is_configurable(): void
    {
        $disk = Storage::fake('local');
        config(['debate.audio.retention_days' => 30]);
        $message = $this->userTurn(Debate::factory()->create(), 'turn.webm', now()->subDays(8));

        $this->artisan('debates:prune-recordings')->assertSuccessful();

        $disk->assertExists($message->audio_path);
    }

    public function test_a_recording_that_cannot_be_deleted_keeps_its_path_and_fails_the_command(): void
    {
        $disk = Storage::fake('local');
        $debate = Debate::factory()->create();
        $stuck = $this->userTurn($debate, 'stuck.webm', now()->subDays(8));
        $orphan = "debates/{$debate->id}/recordings/orphan.webm";
        $disk->put($orphan, 'user voice');
        touch($disk->path($orphan), now()->subDays(8)->getTimestamp());
        Storage::set('local', $this->undeletable($disk));
        Log::spy();

        $this->assertSame(1, Artisan::call('debates:prune-recordings'));

        $output = Artisan::output();
        $this->assertStringContainsString('Deleted 0 voice recordings older than 7 days.', $output);
        $this->assertStringContainsString('2 voice recordings could not be deleted', $output);
        // The files are still on the disk, so their paths must survive for the next run.
        $this->assertSame($stuck->audio_path, $stuck->fresh()->audio_path);
        $disk->assertExists($stuck->audio_path);
        $disk->assertExists($orphan);
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_a_failed_deletion_is_retried_on_the_next_run(): void
    {
        $disk = Storage::fake('local');
        $message = $this->userTurn(Debate::factory()->create(), 'retry.webm', now()->subDays(8));
        Storage::set('local', $this->undeletable($disk));
        $this->artisan('debates:prune-recordings')->assertExitCode(1);

        Storage::set('local', $disk);
        $this->artisan('debates:prune-recordings')->assertSuccessful();

        $this->assertNull($message->fresh()->audio_path);
        $disk->assertMissing("debates/{$message->debate_id}/recordings/retry.webm");
    }

    public function test_a_recording_that_is_already_gone_has_its_path_cleared(): void
    {
        $disk = Storage::fake('local');
        $message = $this->userTurn(Debate::factory()->create(), 'gone.webm', now()->subDays(8));
        $disk->delete($message->audio_path);

        $this->artisan('debates:prune-recordings')->assertSuccessful();

        $this->assertNull($message->fresh()->audio_path);
    }

    public function test_it_runs_every_day_at_four_am_madrid_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'debates:prune-recordings'));

        $this->assertNotNull($event);
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertSame('Europe/Madrid', $event->timezone);
        $this->assertTrue($event->onOneServer);
    }

    /**
     * A disk whose deletes fail the way the local disk does with throw=false: delete() returns
     * false and the file stays where it is.
     */
    private function undeletable(FilesystemAdapter $disk): FilesystemAdapter
    {
        return new class($disk->getDriver(), $disk->getAdapter()) extends FilesystemAdapter
        {
            public function delete($paths)
            {
                return false;
            }
        };
    }

    private function userTurn(Debate $debate, string $file, $createdAt): DebateMessage
    {
        $path = "debates/{$debate->id}/recordings/{$file}";
        Storage::disk('local')->put($path, 'user voice');
        touch(Storage::disk('local')->path($path), $createdAt->getTimestamp());

        return DebateMessage::factory()->for($debate)->fromUser()->create([
            'transcript' => ucfirst(pathinfo($file, PATHINFO_FILENAME)).' turn.',
            'audio_path' => $path,
            'created_at' => $createdAt,
        ]);
    }
}

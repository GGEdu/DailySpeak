<?php

namespace Tests\Feature\Console;

use App\Models\Debate;
use App\Models\DebateMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

        // Tutor replies follow their own retention, which defaults to the recordings' one.
        $this->assertNull($oldReply->fresh()->audio_path);
        $disk->assertMissing("debates/{$debate->id}/replies/reply.mp3");
    }

    public function test_tutor_replies_have_their_own_retention_period(): void
    {
        $disk = Storage::fake('local');
        config(['privacy.replies_retention_days' => 3]);
        $debate = Debate::factory()->create();
        $old = DebateMessage::factory()->for($debate)->fromAssistant()->create([
            'audio_path' => "debates/{$debate->id}/replies/old.mp3",
            'created_at' => now()->subDays(4),
        ]);
        $recent = DebateMessage::factory()->for($debate)->fromAssistant()->create([
            'audio_path' => "debates/{$debate->id}/replies/recent.mp3",
            'created_at' => now()->subDays(2),
        ]);
        $disk->put($old->audio_path, 'tutor voice');
        $disk->put($recent->audio_path, 'tutor voice');

        $this->artisan('debates:prune-recordings')
            ->expectsOutputToContain('Deleted 1 tutor reply older than 3 days.')
            ->assertSuccessful();

        $this->assertNull($old->fresh()->audio_path);
        $disk->assertMissing("debates/{$debate->id}/replies/old.mp3");
        $this->assertNotNull($recent->fresh()->audio_path);
        $disk->assertExists("debates/{$debate->id}/replies/recent.mp3");
    }

    public function test_replies_default_to_the_recordings_retention(): void
    {
        $disk = Storage::fake('local');
        config(['privacy.replies_retention_days' => null, 'debate.audio.retention_days' => 7]);
        $debate = Debate::factory()->create();
        $reply = DebateMessage::factory()->for($debate)->fromAssistant()->create([
            'audio_path' => "debates/{$debate->id}/replies/reply.mp3",
            'created_at' => now()->subDays(3),
        ]);
        $disk->put($reply->audio_path, 'tutor voice');

        $this->artisan('debates:prune-recordings')->assertSuccessful();

        $this->assertNotNull($reply->fresh()->audio_path);
        $disk->assertExists($reply->audio_path);
    }

    public function test_a_retention_of_zero_or_empty_still_keeps_recordings_for_a_day(): void
    {
        $disk = Storage::fake('local');
        $debate = Debate::factory()->create();

        foreach ([0, '', null] as $retention) {
            config(['debate.audio.retention_days' => $retention]);
            // A recording still being uploaded or processed must never be deleted.
            $inProgress = $this->userTurn($debate, 'in-progress-'.var_export($retention, true).'.webm', now()->subHours(12));
            $old = $this->userTurn($debate, 'old-'.var_export($retention, true).'.webm', now()->subDays(2));

            $this->artisan('debates:prune-recordings')->assertSuccessful();

            $this->assertNotNull($inProgress->fresh()->audio_path);
            $disk->assertExists($inProgress->audio_path);
            $this->assertNull($old->fresh()->audio_path);
        }
    }

    public function test_files_of_debates_that_no_longer_exist_are_deleted_whatever_their_age(): void
    {
        $disk = Storage::fake('local');
        $gone = 987654;
        // Left behind by an erased account or a deleted article: nothing refers to them any more.
        $disk->put("debates/{$gone}/recordings/fresh.webm", 'voice');
        $disk->put("debates/{$gone}/replies/fresh.mp3", 'tutor voice');

        $this->artisan('debates:prune-recordings')
            ->expectsOutputToContain('Removed the leftover audio of 1 deleted debate.')
            ->assertSuccessful();

        $this->assertSame([], $disk->allFiles("debates/{$gone}"));
    }

    public function test_orphan_replies_are_deleted_too(): void
    {
        $disk = Storage::fake('local');
        $debate = Debate::factory()->create();
        $disk->put("debates/{$debate->id}/replies/unreferenced.mp3", 'tutor voice');
        $disk->put("debates/{$debate->id}/replies/just-made.mp3", 'tutor voice');
        touch($disk->path("debates/{$debate->id}/replies/unreferenced.mp3"), now()->subDays(8)->getTimestamp());

        $this->artisan('debates:prune-recordings')->assertSuccessful();

        $disk->assertMissing("debates/{$debate->id}/replies/unreferenced.mp3");
        $disk->assertExists("debates/{$debate->id}/replies/just-made.mp3");
    }

    public function test_the_audio_path_is_cleared_in_one_update_per_batch(): void
    {
        $disk = Storage::fake('local');
        $debate = Debate::factory()->create();
        foreach (['a', 'b', 'c'] as $name) {
            $this->userTurn($debate, "{$name}.webm", now()->subDays(8));
        }
        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'debate_messages')) {
                $updates++;
            }
        });

        $this->artisan('debates:prune-recordings')->assertSuccessful();

        $this->assertSame(1, $updates);
        $this->assertSame(0, DebateMessage::query()->whereNotNull('audio_path')->count());
    }

    public function test_transcripts_are_kept_unless_a_transcript_retention_is_configured(): void
    {
        $disk = Storage::fake('local');
        config(['privacy.transcripts_retention_days' => 0]);
        $debate = Debate::factory()->completed()->create(['ended_at' => now()->subYear()]);
        DebateMessage::factory()->for($debate)->fromUser()->create(['audio_path' => null]);

        $this->artisan('debates:prune-recordings')->assertSuccessful();

        $this->assertDatabaseHas('debates', ['id' => $debate->id]);
        $this->assertDatabaseHas('debate_messages', ['debate_id' => $debate->id]);
    }

    public function test_finished_debates_past_the_transcript_retention_go_with_their_audio(): void
    {
        $disk = Storage::fake('local');
        config(['privacy.transcripts_retention_days' => 30]);
        $expired = Debate::factory()->completed()->create(['ended_at' => now()->subDays(31)]);
        $expiredMessage = DebateMessage::factory()->for($expired)->fromUser()->create([
            'audio_path' => "debates/{$expired->id}/recordings/turn.webm",
        ]);
        $disk->put($expiredMessage->audio_path, 'voice');
        $recent = Debate::factory()->completed()->create(['ended_at' => now()->subDays(29)]);
        $active = Debate::factory()->create();

        $this->artisan('debates:prune-recordings')
            ->expectsOutputToContain('Deleted 1 debate ended more than 30 days ago.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('debates', ['id' => $expired->id]);
        $this->assertDatabaseMissing('debate_messages', ['debate_id' => $expired->id]);
        $disk->assertMissing($expiredMessage->audio_path);
        $this->assertDatabaseHas('debates', ['id' => $recent->id]);
        $this->assertDatabaseHas('debates', ['id' => $active->id]);
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
        $this->assertStringContainsString('2 audio files could not be deleted', $output);
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

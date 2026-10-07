<?php

namespace Tests\Feature\Broadcasting;

use App\Events\AIResponseGenerated;
use App\Events\DebateTurnFailed;
use App\Events\UserTurnTranscribed;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DebateBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ai_reply_is_broadcast_immediately_on_the_debate_channel(): void
    {
        // The real "local" driver (Storage::fake() would replace its signed URL generator).
        $root = storage_path('framework/testing/disks/signed-local');
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

        $debate = Debate::factory()->create();
        $turn = DebateMessage::factory()->for($debate)->fromUser()->create(['transcript' => 'Taxes are too high.']);
        $reply = DebateMessage::factory()->for($debate)->fromAssistant()->create(['transcript' => 'Compared to what?']);
        Storage::disk('local')->put($reply->audio_path, 'mp3-bytes');

        $event = new AIResponseGenerated($reply, $turn);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertEquals([new PrivateChannel("debates.{$debate->id}")], $event->broadcastOn());

        $payload = $event->broadcastWith();
        $this->assertSame($debate->id, $payload['debate_id']);
        $this->assertSame($reply->id, $payload['message_id']);
        $this->assertSame('Compared to what?', $payload['transcript']);
        $this->assertSame(['id' => $turn->id, 'transcript' => 'Taxes are too high.'], $payload['user_message']);

        // A short-lived signed URL to the private recording, playable by the browser.
        $this->assertStringContainsString('signature=', $payload['audio_url']);
        $this->assertSame('mp3-bytes', $this->get($payload['audio_url'])->assertOk()->streamedContent());
        $this->get(strtok($payload['audio_url'], '?'))->assertForbidden();
    }

    public function test_the_users_transcript_is_broadcast_on_the_debate_channel(): void
    {
        $turn = DebateMessage::factory()->fromUser()->create(['transcript' => 'Taxes are too high.']);

        $event = new UserTurnTranscribed($turn);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertEquals([new PrivateChannel("debates.{$turn->debate_id}")], $event->broadcastOn());
        $this->assertSame(['debate_id' => $turn->debate_id, 'message_id' => $turn->id, 'transcript' => 'Taxes are too high.'], $event->broadcastWith());
    }

    public function test_turn_failures_are_broadcast_on_the_debate_channel(): void
    {
        $debate = Debate::factory()->create();

        $event = new DebateTurnFailed($debate, DebateTurnFailed::NO_SPEECH);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertEquals([new PrivateChannel("debates.{$debate->id}")], $event->broadcastOn());
        $this->assertSame(['debate_id' => $debate->id, 'reason' => 'no_speech'], $event->broadcastWith());
    }

    public function test_only_the_owner_can_join_the_debate_channel(): void
    {
        $this->useReverbBroadcaster();
        $debate = Debate::factory()->create();
        $payload = ['socket_id' => '1234.5678', 'channel_name' => "private-debates.{$debate->id}"];

        $this->actingAs($debate->user)->post('/broadcasting/auth', $payload)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs(User::factory()->create())->post('/broadcasting/auth', $payload)
            ->assertForbidden();
    }

    /**
     * Channel authorisation is skipped by the "null" broadcaster used in tests.
     */
    private function useReverbBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        Broadcast::purge();
        require base_path('routes/channels.php');
    }
}

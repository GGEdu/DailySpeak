<?php

namespace Tests\Feature\Models;

use App\Enums\MessageRole;
use App\Models\Debate;
use App\Models\DebateMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebateMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_cast_and_only_created_at_is_tracked(): void
    {
        $message = DebateMessage::factory()->fromAssistant()->create();

        $fresh = $message->fresh();

        $this->assertSame(MessageRole::Assistant, $fresh->role);
        $this->assertNotNull($fresh->created_at);
        $this->assertArrayNotHasKey('updated_at', $fresh->getAttributes());
        $this->assertStringEndsWith('.mp3', $fresh->audio_path);
    }

    public function test_audio_path_is_optional(): void
    {
        $message = DebateMessage::factory()->create(['audio_path' => null]);

        $this->assertNull($message->fresh()->audio_path);
    }

    public function test_message_belongs_to_debate(): void
    {
        $debate = Debate::factory()->create();
        $message = DebateMessage::factory()->for($debate)->create();

        $this->assertTrue($message->debate->is($debate));
    }
}

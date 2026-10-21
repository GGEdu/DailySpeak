<?php

namespace Tests\Feature\Models;

use App\Enums\MessageRole;
use App\Models\Debate;
use App\Models\DebateMessage;
use Illuminate\Database\UniqueConstraintViolationException;
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

    public function test_a_voice_turn_has_at_most_one_reply(): void
    {
        $turn = DebateMessage::factory()->fromUser()->create();
        DebateMessage::factory()->for($turn->debate)->fromAssistant()->create(['reply_to_id' => $turn->id]);

        // The database refuses a second answer to the same turn, even if two attempts race to write one.
        $this->expectException(UniqueConstraintViolationException::class);
        DebateMessage::factory()->for($turn->debate)->fromAssistant()->create(['reply_to_id' => $turn->id]);
    }

    public function test_messages_that_answer_no_turn_do_not_clash(): void
    {
        // Rows from before the link existed have no reply_to_id; NULLs never clash under the unique index.
        DebateMessage::factory(3)->fromAssistant()->create();

        $this->assertSame(3, DebateMessage::query()->whereNull('reply_to_id')->count());
    }

    public function test_a_user_message_knows_its_voice_turn_from_the_recording_name(): void
    {
        $message = DebateMessage::factory()->fromUser()->create(['audio_path' => 'debates/3/recordings/0f3c-turn.webm']);

        $this->assertSame('0f3c-turn', $message->turnId());
        $this->assertNull(DebateMessage::factory()->create(['audio_path' => null])->turnId());
    }

    public function test_a_turn_can_be_answered_by_its_reply(): void
    {
        $turn = DebateMessage::factory()->fromUser()->create();
        $reply = DebateMessage::factory()->for($turn->debate)->fromAssistant()->create(['reply_to_id' => $turn->id]);

        $this->assertTrue($turn->reply->is($reply));
    }
}

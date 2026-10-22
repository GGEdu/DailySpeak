<?php

namespace Tests\Unit\Http;

use App\Enums\MessageRole;
use App\Http\Resources\DebateMessageResource;
use App\Models\DebateMessage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DebateMessageResourceTest extends TestCase
{
    public function test_a_user_message_names_the_voice_turn_it_belongs_to(): void
    {
        Storage::fake(config('debate.audio.disk'));
        $message = new DebateMessage(['role' => MessageRole::User, 'audio_path' => 'debates/user/0f8c2c1e-3b1a-4c55-9d2e-7a1b2c3d4e5f.webm']);

        $resolved = DebateMessageResource::make($message)->resolve();

        $this->assertSame('0f8c2c1e-3b1a-4c55-9d2e-7a1b2c3d4e5f', $resolved['turn']);
        $this->assertNull($resolved['reply_to_id']);
    }

    public function test_a_tutor_reply_names_the_user_message_it_answers_and_no_turn_of_its_own(): void
    {
        Storage::fake(config('debate.audio.disk'));
        // The reply's audio file has a name of its own: it is not the voice turn.
        $reply = new DebateMessage([
            'role' => MessageRole::Assistant,
            'audio_path' => 'debates/assistant/7d2e9a10-5b6c-4f8e-a1d3-9c0b4e6f7a8b.mp3',
            'reply_to_id' => 12,
        ]);

        $resolved = DebateMessageResource::make($reply)->resolve();

        $this->assertSame(12, $resolved['reply_to_id']);
        $this->assertNull($resolved['turn']);
    }

    public function test_a_user_message_without_audio_has_no_turn(): void
    {
        $message = new DebateMessage(['role' => MessageRole::User, 'audio_path' => null]);

        $this->assertNull(DebateMessageResource::make($message)->resolve()['turn']);
    }
}

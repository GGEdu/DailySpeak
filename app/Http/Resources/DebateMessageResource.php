<?php

namespace App\Http\Resources;

use App\Enums\MessageRole;
use App\Models\DebateMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DebateMessage
 */
class DebateMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'transcript' => $this->transcript,
            'audio_url' => $this->audioUrl(),
            // The voice turn of a user message: a reply's own audio name is not one, so a reply names the message it answers.
            'turn' => $this->role === MessageRole::User ? $this->turnId() : null,
            'reply_to_id' => $this->reply_to_id,
            // The learner's written corrections: null until reviewed, and always null for the tutor's messages.
            'corrections' => $this->role === MessageRole::User ? $this->presentedCorrections() : null,
            'created_at' => $this->created_at,
        ];
    }
}

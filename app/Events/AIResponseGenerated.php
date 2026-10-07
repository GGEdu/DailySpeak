<?php

namespace App\Events;

use App\Models\DebateMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The tutor has answered a voice turn; the client should play the reply.
 */
class AIResponseGenerated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public DebateMessage $reply,
        public DebateMessage $turn,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel($this->reply->debate->broadcastChannel()),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'debate_id' => $this->reply->debate_id,
            'message_id' => $this->reply->id,
            'transcript' => $this->reply->transcript,
            'audio_url' => $this->reply->audioUrl(),
            'user_message' => [
                'id' => $this->turn->id,
                'transcript' => $this->turn->transcript,
            ],
        ];
    }
}

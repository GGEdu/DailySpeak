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
 * The user's turn has been transcribed; the tutor is still thinking about the reply.
 */
class UserTurnTranscribed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DebateMessage $turn) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel($this->turn->debate->broadcastChannel()),
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
            'debate_id' => $this->turn->debate_id,
            'message_id' => $this->turn->id,
            'transcript' => $this->turn->transcript,
        ];
    }
}

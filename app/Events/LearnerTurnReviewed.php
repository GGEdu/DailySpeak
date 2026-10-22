<?php

namespace App\Events;

use App\Models\Debate;
use App\Models\DebateMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The written corrections of a learner's turn are ready. They are text under the turn: nothing is said aloud.
 */
class LearnerTurnReviewed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{index: int, original: string, better: string, note: ?string, dismissed: bool}>  $corrections
     */
    public function __construct(
        public Debate $debate,
        public DebateMessage $turn,
        public array $corrections,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel($this->debate->broadcastChannel()),
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
            'debate_id' => $this->debate->id,
            'turn' => $this->turn->turnId(),
            'message_id' => $this->turn->id,
            'corrections' => $this->corrections,
        ];
    }
}

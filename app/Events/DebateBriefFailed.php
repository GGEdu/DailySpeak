<?php

namespace App\Events;

use App\Models\Debate;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The brief of a guided debate could not be written. The learner can retry it.
 */
class DebateBriefFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The daily AI budget is used up, so the brief was not written.
     */
    public const BUDGET = 'budget';

    /**
     * Writing or checking the brief failed.
     */
    public const FAILED = 'failed';

    public function __construct(
        public Debate $debate,
        public string $reason,
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
            'reason' => $this->reason,
        ];
    }
}

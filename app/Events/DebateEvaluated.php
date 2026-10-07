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
 * The post-session fluency report is ready.
 */
class DebateEvaluated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<string>  $addedWords  Recommended words that were added to the user's vocabulary.
     */
    public function __construct(
        public Debate $debate,
        public array $addedWords = [],
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
            'ai_feedback' => $this->debate->ai_feedback,
            'added_words' => $this->addedWords,
        ];
    }
}

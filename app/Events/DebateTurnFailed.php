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
 * A voice turn could not be answered, so the client should stop waiting and let the user retry.
 */
class DebateTurnFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The recording contained no recognisable speech.
     */
    public const NO_SPEECH = 'no_speech';

    /**
     * Transcription, the tutor or speech synthesis failed.
     */
    public const PROCESSING_FAILED = 'processing_failed';

    /**
     * Create a new event instance.
     */
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

<?php

namespace App\Http\Resources;

use App\Models\Debate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Debate
 */
class DebateResource extends JsonResource
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
            'news_article_id' => $this->news_article_id,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            // Fluency report, null until the debate is finished and evaluated.
            'ai_feedback' => $this->ai_feedback,
            // Private channel to subscribe to (with Laravel Echo) for AIResponseGenerated / DebateTurnFailed.
            'channel' => $this->broadcastChannel(),
        ];
    }
}

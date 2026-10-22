<?php

namespace App\Services\Guided;

use App\Enums\DebateFlow;
use App\Enums\DebateStatus;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use App\Services\Ai\AiCalls;
use App\Services\Ai\AiConnections;

/**
 * Starts a debate about an article, or resumes the user's active one. Every new debate is guided: it starts from a brief
 * of the article at the user's level. Debates started before that keep the flow they have.
 */
class DebateStarter
{
    public function __construct(
        private DebateBriefs $briefs,
        private AiConnections $connections,
        private AiCalls $calls,
    ) {}

    public function start(User $user, NewsArticle $article): Debate
    {
        $active = [
            'news_article_id' => $article->id,
            'status' => DebateStatus::Active,
        ];

        if ($existing = $user->debates()->where($active)->first()) {
            return $existing;
        }

        // A new debate needs the user's own connections for speech, the tutor and the voice, and room under their cap.
        // Resuming one costs nothing, so it is not refused (AiNotConfigured answers 409, AiCapReached 429).
        $this->connections->forUserTasks($user, ['stt', 'conversation', 'tts']);
        $this->calls->assertWithinCap($user);

        // The brief is written at the level the debate starts at; the debate keeps that level.
        $debate = $user->debates()->createOrFirst($active, [
            'flow' => DebateFlow::Guided,
            'level' => $user->current_level,
        ]);

        // Only the request that created the debate starts its brief; a race that lost resumes the winner's.
        if ($debate->wasRecentlyCreated) {
            $this->briefs->prepare($debate);
        }

        return $debate;
    }
}

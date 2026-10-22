<?php

namespace App\Services\Guided;

use App\Enums\DebateFlow;
use App\Enums\DebateStatus;
use App\Enums\EnglishLevel;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;

/**
 * Starts a debate about an article, or resumes the user's active one. The flow is decided here, once, from the
 * user's level and the admin's switch, and stored on the debate.
 */
class DebateStarter
{
    public function __construct(private GuidedSettings $settings) {}

    public function start(User $user, NewsArticle $article): Debate
    {
        $active = [
            'news_article_id' => $article->id,
            'status' => DebateStatus::Active,
        ];

        if ($existing = $user->debates()->where($active)->first()) {
            return $existing;
        }

        $level = $user->current_level;

        return $user->debates()->createOrFirst($active, $this->attributesFor($level));
    }

    /**
     * The flow of a new debate at this level. A guided debate keeps the level its brief is written for.
     *
     * @return array{flow: DebateFlow, level?: EnglishLevel}
     */
    private function attributesFor(EnglishLevel $level): array
    {
        if (! $this->settings->enabled($level)) {
            return ['flow' => DebateFlow::Classic];
        }

        return ['flow' => DebateFlow::Guided, 'level' => $level];
    }
}

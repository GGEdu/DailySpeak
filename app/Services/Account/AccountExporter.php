<?php

namespace App\Services\Account;

use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\User;
use App\Models\UserVocabulary;

/**
 * Everything the account holds, as plain data (GDPR art. 20). No audio, no file paths, no password
 * hash and no tokens.
 */
class AccountExporter
{
    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $debates = $user->debates()
            ->with([
                'newsArticle:id,title,source_url,published_at',
                'messages' => fn ($query) => $query->select(['id', 'debate_id', 'role', 'transcript', 'created_at'])->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        $vocabulary = $user->vocabularies()->orderBy('id')->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'current_level' => $user->current_level->value,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'debates' => $debates->map(fn (Debate $debate) => [
                'id' => $debate->id,
                'status' => $debate->status->value,
                'started_at' => $debate->started_at?->toIso8601String(),
                'ended_at' => $debate->ended_at?->toIso8601String(),
                'news_article' => [
                    'title' => $debate->newsArticle?->title,
                    'source_url' => $debate->newsArticle?->source_url,
                    'published_at' => $debate->newsArticle?->published_at?->toIso8601String(),
                ],
                'ai_feedback' => $debate->ai_feedback,
                'messages' => $debate->messages->map(fn (DebateMessage $message) => [
                    'role' => $message->role->value,
                    'transcript' => $message->transcript,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'vocabulary' => $vocabulary->map(fn (UserVocabulary $word) => [
                'word' => $word->word,
                'context' => $word->context,
                'translation' => $word->translation,
                'analysis' => $word->analysis,
                'mastery_level' => $word->mastery_level,
                'next_review_at' => $word->next_review_at?->toIso8601String(),
                'created_at' => $word->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}

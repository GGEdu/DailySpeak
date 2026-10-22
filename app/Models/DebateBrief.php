<?php

namespace App\Models;

use App\Enums\EnglishLevel;
use Database\Factories\DebateBriefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The structured summary of an article that a guided debate starts from, for one English level. Written once per
 * article and level (App\Services\Guided\BriefWriter) and shared by every debate on it.
 */
#[Fillable(['news_article_id', 'level', 'headline', 'what_happened', 'why_it_matters', 'background', 'side_for', 'side_against', 'question', 'opening', 'key_words'])]
class DebateBrief extends Model
{
    /** @use HasFactory<DebateBriefFactory> */
    use HasFactory;

    /**
     * Briefs are not edited, so only created_at is tracked.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => EnglishLevel::class,
            'side_for' => 'array',
            'side_against' => 'array',
            'key_words' => 'array',
        ];
    }

    /**
     * @return BelongsTo<NewsArticle, $this>
     */
    public function newsArticle(): BelongsTo
    {
        return $this->belongsTo(NewsArticle::class);
    }

    /**
     * The brief a debate starts from: the one of its article at the debate's level. Null for a classic debate, and
     * while the brief is not written yet.
     */
    public static function forDebate(Debate $debate): ?self
    {
        if (! $debate->isGuided() || $debate->level === null) {
            return null;
        }

        return self::query()
            ->where('news_article_id', $debate->news_article_id)
            ->where('level', $debate->level->value)
            ->first();
    }

    /**
     * The brief as the Debate page shows it. The opening question is not part of it: it is the first tutor message.
     *
     * @return array{headline: string, what_happened: string, why_it_matters: string, background: ?string, sides: array{for: list<string>, against: list<string>}, question: string, key_words: list<string>}
     */
    public function payload(): array
    {
        return [
            'headline' => $this->headline,
            'what_happened' => $this->what_happened,
            'why_it_matters' => $this->why_it_matters,
            'background' => $this->background,
            'sides' => [
                'for' => $this->side_for,
                'against' => $this->side_against,
            ],
            'question' => $this->question,
            'key_words' => $this->key_words,
        ];
    }
}

<?php

namespace App\Models;

use Database\Factories\UserVocabularyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'word', 'context', 'translation', 'analysis', 'mastery_level', 'next_review_at'])]
class UserVocabulary extends Model
{
    /** @use HasFactory<UserVocabularyFactory> */
    use HasFactory;

    /**
     * Spaced-repetition mastery bounds (enforced by a CHECK constraint on Postgres).
     */
    public const MIN_MASTERY = 1;

    public const MAX_MASTERY = 5;

    /**
     * Leitner boxes: days until the next review once a word reaches each mastery level.
     */
    public const INTERVAL_DAYS = [1 => 1, 2 => 2, 3 => 4, 4 => 8, 5 => 16];

    /**
     * A forgotten word comes back later in the same study session.
     */
    public const RELEARN_MINUTES = 10;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'mastery_level' => self::MIN_MASTERY,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mastery_level' => 'integer',
            'next_review_at' => 'datetime',
            'analysis' => 'array',
        ];
    }

    /**
     * Normalise words so the (user_id, word) unique index treats "Nuance" and "nuance" as one entry.
     *
     * @return Attribute<string, string>
     */
    protected function word(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => mb_strtolower(trim($value)),
        );
    }

    /**
     * Record a review: move the word up a box when remembered, back to the first box when not.
     */
    public function review(bool $remembered): void
    {
        $this->mastery_level = $remembered
            ? min($this->mastery_level + 1, self::MAX_MASTERY)
            : self::MIN_MASTERY;

        $this->next_review_at = $remembered
            ? now()->addDays(self::INTERVAL_DAYS[$this->mastery_level])
            : now()->addMinutes(self::RELEARN_MINUTES);

        $this->save();
    }

    /**
     * Words whose review is due.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->where('next_review_at', '<=', now());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

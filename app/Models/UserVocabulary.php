<?php

namespace App\Models;

use Database\Factories\UserVocabularyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'word', 'context', 'mastery_level', 'next_review_at'])]
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

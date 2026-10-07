<?php

namespace App\Models;

use App\Enums\DebateStatus;
use Database\Factories\DebateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'news_article_id', 'status', 'ai_feedback', 'started_at', 'ended_at'])]
class Debate extends Model
{
    /** @use HasFactory<DebateFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => DebateStatus::Active->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DebateStatus::class,
            'ai_feedback' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Debate $debate) {
            $debate->started_at ??= now();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NewsArticle, $this>
     */
    public function newsArticle(): BelongsTo
    {
        return $this->belongsTo(NewsArticle::class);
    }

    /**
     * @return HasMany<DebateMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DebateMessage::class);
    }

    public function isActive(): bool
    {
        return $this->status === DebateStatus::Active;
    }

    /**
     * Get the private channel route the debate's realtime events are authorised on.
     */
    public function broadcastChannelRoute(): string
    {
        return 'debates.{debate}';
    }

    /**
     * Get the private channel the debate's realtime events are sent to.
     */
    public function broadcastChannel(): string
    {
        return 'debates.'.$this->getKey();
    }
}

<?php

namespace App\Models;

use App\Enums\DebateFlow;
use App\Enums\DebateStatus;
use App\Enums\EnglishLevel;
use Database\Factories\DebateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'news_article_id', 'status', 'flow', 'level', 'ai_feedback', 'started_at', 'ended_at'])]
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
        // Every new debate is guided (App\Services\Guided\DebateStarter); classic is only what older debates have.
        'flow' => DebateFlow::Guided->value,
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
            'flow' => DebateFlow::class,
            // The brief's level, set for guided debates only (see DebateBrief::forDebate()).
            'level' => EnglishLevel::class,
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
     * Whether the debate runs the guided flow (fixed when it started).
     */
    public function isGuided(): bool
    {
        return $this->flow === DebateFlow::Guided;
    }

    /**
     * Lock shared by every queued job that writes to this debate, so turns and the
     * final evaluation run one at a time and in order.
     */
    public function jobLockKey(): string
    {
        return "debate:{$this->getKey()}";
    }

    /**
     * Close the debate (idempotent). The update is conditional on the row still being active, so two requests
     * racing to finish it cannot both close it; the in-memory copy then reflects what the database holds.
     */
    public function finish(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', DebateStatus::Active->value)
            ->update([
                'status' => DebateStatus::Completed->value,
                'ended_at' => now(),
                'updated_at' => now(),
            ]);

        $this->refresh();
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

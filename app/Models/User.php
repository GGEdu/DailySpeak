<?php

namespace App\Models;

use App\Enums\DebateStatus;
use App\Enums\EnglishLevel;
use App\Notifications\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'current_level'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The version of the privacy notice the sign-up checkbox and the consent page refer to. Change it when the
     * notice changes: everyone who accepted an older version is asked again.
     */
    public const CONSENT_VERSION = '2026-10-22';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'current_level' => EnglishLevel::B2->value,
        // Deliberately not fillable: only `php artisan user:admin` grants it.
        'is_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'current_level' => EnglishLevel::class,
            'is_admin' => 'boolean',
            'consented_at' => 'datetime',
        ];
    }

    /**
     * Whether the user accepted the privacy notice in its current version: the tutor sends their voice and texts to
     * AI providers only after this.
     */
    public function hasCurrentConsent(): bool
    {
        return $this->consented_at !== null && $this->consent_version === self::CONSENT_VERSION;
    }

    /**
     * Records the acceptance of the current privacy notice, with its date and version (the proof of consent).
     */
    public function recordConsent(): void
    {
        // Not fillable: the acceptance is written here, never taken from the request.
        $this->forceFill([
            'consented_at' => now(),
            'consent_version' => self::CONSENT_VERSION,
        ])->save();
    }

    /**
     * Sent through the queue, so an outage of the mail server does not fail the sign-up.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    /**
     * @return HasMany<Debate, $this>
     */
    public function debates(): HasMany
    {
        return $this->hasMany(Debate::class);
    }

    /**
     * @return HasMany<UserVocabulary, $this>
     */
    public function vocabularies(): HasMany
    {
        return $this->hasMany(UserVocabulary::class);
    }

    /**
     * Start a debate about the article, or resume the active one the user already has.
     *
     * The partial unique index on debates rejects a second active debate for the same article, so when
     * a double submit loses the race, createOrFirst() returns the debate that won it.
     */
    public function startDebate(NewsArticle $article): Debate
    {
        $active = [
            'news_article_id' => $article->id,
            'status' => DebateStatus::Active,
        ];

        return $this->debates()->where($active)->first() ?? $this->debates()->createOrFirst($active);
    }
}

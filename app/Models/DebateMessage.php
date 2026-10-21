<?php

namespace App\Models;

use App\Enums\MessageRole;
use Database\Factories\DebateMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable(['debate_id', 'role', 'transcript', 'audio_path', 'reply_to_id'])]
class DebateMessage extends Model
{
    /** @use HasFactory<DebateMessageFactory> */
    use HasFactory;

    /**
     * Messages are not edited, so only created_at is tracked. A turn that gets no reply is deleted.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
        ];
    }

    /**
     * @return BelongsTo<Debate, $this>
     */
    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }

    /**
     * The tutor's reply to this user turn, if one was stored. The unique index allows one per turn.
     *
     * @return HasOne<DebateMessage, $this>
     */
    public function reply(): HasOne
    {
        return $this->hasOne(DebateMessage::class, 'reply_to_id');
    }

    /**
     * The voice turn a user message belongs to: its recording's name without the extension. The client gets the
     * same id in the 202 response, so it can match the events to the turn it is waiting for.
     */
    public function turnId(): ?string
    {
        return $this->audio_path === null ? null : pathinfo($this->audio_path, PATHINFO_FILENAME);
    }

    /**
     * Get a playable URL for the message audio (signed and short-lived when the disk supports it).
     */
    public function audioUrl(): ?string
    {
        if ($this->audio_path === null) {
            return null;
        }

        $disk = Storage::disk(config('debate.audio.disk'));

        return $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($this->audio_path, now()->addMinutes(config('debate.audio.url_ttl_minutes')))
            : $disk->url($this->audio_path);
    }
}

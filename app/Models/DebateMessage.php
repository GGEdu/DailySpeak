<?php

namespace App\Models;

use App\Enums\MessageRole;
use Database\Factories\DebateMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['debate_id', 'role', 'transcript', 'audio_path'])]
class DebateMessage extends Model
{
    /** @use HasFactory<DebateMessageFactory> */
    use HasFactory;

    /**
     * Messages are append-only, so only created_at is tracked.
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
}

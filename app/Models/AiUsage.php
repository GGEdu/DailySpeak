<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI call, without its content (App\Services\Ai\AiCalls). Counts and sizes only: never the text sent or received.
 */
#[Fillable(['user_id', 'task', 'provider', 'model', 'paid_by', 'input_tokens', 'output_tokens', 'characters', 'seconds', 'ok', 'created_at'])]
class AiUsage extends Model
{
    /**
     * The table is named for the record, not for the plural of its class name.
     */
    protected $table = 'ai_usage';

    /**
     * Rows are written once: the only timestamp is when the call happened.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'seconds' => 'float',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

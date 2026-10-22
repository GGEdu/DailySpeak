<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own API key for one provider of the closed list (App\Services\Ai\ProviderCatalog). The key is encrypted at
 * rest and hidden from every serialisation: nothing that reaches a page, a log or a job payload carries it.
 */
#[Fillable(['user_id', 'provider', 'key', 'last4', 'verified_at'])]
#[Hidden(['key'])]
class UserAiKey extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => 'encrypted',
            'verified_at' => 'datetime',
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

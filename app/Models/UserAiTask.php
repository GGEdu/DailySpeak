<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The provider, model and voice a user picked for one task (App\Services\Ai\AiConnections). The provider is an id of the
 * closed list, or "platform".
 */
#[Fillable(['user_id', 'task', 'provider', 'model', 'voice'])]
class UserAiTask extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

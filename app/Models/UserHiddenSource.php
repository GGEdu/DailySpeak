<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A catalogue source one user has hidden from their own feed.
 */
#[Fillable(['user_id', 'news_source_id', 'created_at'])]
class UserHiddenSource extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NewsSource, $this>
     */
    public function newsSource(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class);
    }
}

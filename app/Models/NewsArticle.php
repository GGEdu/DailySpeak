<?php

namespace App\Models;

use Database\Factories\NewsArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'source_url', 'summary', 'key_vocabulary', 'published_at'])]
class NewsArticle extends Model
{
    /** @use HasFactory<NewsArticleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_vocabulary' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Debate, $this>
     */
    public function debates(): HasMany
    {
        return $this->hasMany(Debate::class);
    }
}

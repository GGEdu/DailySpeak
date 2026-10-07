<?php

namespace App\Models;

use Database\Factories\NewsArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

#[Fillable(['news_source_id', 'title', 'source_url', 'summary', 'key_vocabulary', 'published_at'])]
class NewsArticle extends Model
{
    /** @use HasFactory<NewsArticleFactory> */
    use HasFactory, Searchable;

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
     * Get the indexable data for the search engine (searchable fields are set in config/scout.php).
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'key_vocabulary' => $this->key_vocabulary,
            'published_at' => $this->published_at?->getTimestamp(),
        ];
    }

    /**
     * The RSS feed the article was harvested from (null for ad-hoc --feed runs).
     *
     * @return BelongsTo<NewsSource, $this>
     */
    public function newsSource(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class);
    }

    /**
     * @return HasMany<Debate, $this>
     */
    public function debates(): HasMany
    {
        return $this->hasMany(Debate::class);
    }
}

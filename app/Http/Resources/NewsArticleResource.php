<?php

namespace App\Http\Resources;

use App\Models\NewsArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NewsArticle
 */
class NewsArticleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'key_vocabulary' => $this->key_vocabulary,
            'source_url' => $this->source_url,
            'source' => preg_replace('/^www\./', '', (string) parse_url($this->source_url, PHP_URL_HOST)),
            'published_at' => $this->published_at,
        ];
    }
}

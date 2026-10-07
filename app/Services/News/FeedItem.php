<?php

namespace App\Services\News;

use Carbon\CarbonImmutable;

/**
 * A single entry read from an RSS feed.
 */
final readonly class FeedItem
{
    public function __construct(
        public string $title,
        public string $url,
        public CarbonImmutable $publishedAt,
    ) {}
}

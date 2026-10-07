<?php

namespace App\Services\News;

/**
 * What one run over a feed produced.
 */
final readonly class HarvestResult
{
    public function __construct(
        public int $created = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public ?string $readError = null,
    ) {}
}

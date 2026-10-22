<?php

namespace App\Services\News;

use Carbon\CarbonImmutable;

/**
 * What the usage-policy check found for one source. Shown to admins only; it never blocks a source.
 */
final readonly class SourcePolicyResult
{
    /** Nothing found that restricts AI or LLM use of the articles. */
    public const OK = 'ok';

    /** A robots.txt rule, a comment or a known publisher condition restricts that use. */
    public const WARNING = 'warning';

    /** Something could not be checked. The note says what. */
    public const UNKNOWN = 'unknown';

    /** The check is queued or running; the result from before it, if any, is still in the note. */
    public const CHECKING = 'checking';

    public function __construct(
        public string $status,
        public string $note,
        public CarbonImmutable $checkedAt,
    ) {}

    public function needsAttention(): bool
    {
        return $this->status !== self::OK;
    }
}

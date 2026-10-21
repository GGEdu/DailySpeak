<?php

namespace App\Services\News;

use App\Models\NewsSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Checks whether a source's publisher restricts using its articles for AI: the robots.txt of the feed's
 * host and of the articles' hosts, plus the conditions config/source_policies.php knows about.
 *
 * Makes no LLM call. A check that cannot be made is `unknown`, never an error for the caller.
 */
class SourceUsagePolicy
{
    private const ROBOTS_MAX_BYTES = 512 * 1024;

    /** Hosts checked for one feed: the feed's own, plus up to three article hosts. */
    private const MAX_ORIGINS = 4;

    /** Articles sampled per feed; their paths are what the robots rules are tested against. */
    private const SAMPLE_ARTICLES = 5;

    private const AI_TERMS = '/\b(LLMs?|large language models?|generative AI|AI|artificial intelligence|machine learning|text and data mining|TDM|data mining)\b/i';

    private const PROHIBITION_TERMS = '/\b(no|not|never|don\'?t|do not|prohibit\w*|forbid\w*|forbidden|without|may not|must not|reserv\w*|opt[- ]?out|disallow\w*|unauthori[sz]ed)\b/i';

    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
        private readonly RssFeedReader $feeds,
    ) {}

    /**
     * Check a saved source and store the result on it.
     *
     * @param  ?Collection<int, FeedItem>  $items  the feed's items when the caller has just read them; read again otherwise
     */
    public function check(NewsSource $source, ?Collection $items = null): SourcePolicyResult
    {
        $unknown = [];

        if ($items === null) {
            try {
                $items = $this->feeds->read($source->feed_url);
            } catch (Throwable $e) {
                $this->reportUnexpected($e);
                $items = collect();
                $unknown[] = 'The feed could not be read, so its article pages were not checked.';
            }
        }

        $result = $this->assess($source->feed_url, $items, $unknown);

        $source->forceFill([
            'policy_status' => $result->status,
            'policy_note' => $result->note,
            'policy_checked_at' => $result->checkedAt,
        ])->save();

        return $result;
    }

    /**
     * Check a feed that has not been saved yet.
     *
     * @param  Collection<int, FeedItem>  $items  the feed's items, already read
     * @param  list<string>  $unknown  checks that failed before this one
     */
    public function assess(string $feedUrl, Collection $items, array $unknown = []): SourcePolicyResult
    {
        $findings = [];
        $hosts = [];

        foreach ($this->origins($feedUrl, $items) as $origin => $paths) {
            $hosts[] = parse_url($origin, PHP_URL_HOST);
            $robots = $this->fetchRobots($origin);

            if (is_string($robots)) {
                $unknown[] = $robots;

                continue;
            }

            $findings = [...$findings, ...$this->findingsFor($robots, parse_url($origin, PHP_URL_HOST), $paths)];
        }

        $warnings = [...$findings, ...$this->publisherNotes($hosts)];

        if ($warnings !== []) {
            return $this->result(SourcePolicyResult::WARNING, [...$warnings, ...$unknown]);
        }

        if ($unknown !== []) {
            return $this->result(SourcePolicyResult::UNKNOWN, $unknown);
        }

        return $this->result(SourcePolicyResult::OK, ['No AI or LLM restriction found in robots.txt for the pages checked.']);
    }

    /**
     * The feed's origin, and the origins of the sampled articles, each with the paths to test.
     *
     * @param  Collection<int, FeedItem>  $items
     * @return array<string, list<string>> origin => paths; the site root when no article is on that origin
     */
    private function origins(string $feedUrl, Collection $items): array
    {
        $origins = [$this->origin($feedUrl) => []];

        foreach ($items->take(self::SAMPLE_ARTICLES) as $item) {
            $origin = $this->origin($item->url);

            if ($origin === null || (! isset($origins[$origin]) && count($origins) >= self::MAX_ORIGINS)) {
                continue;
            }

            $origins[$origin][] = $this->pathAndQuery($item->url);
        }

        return array_map(fn (array $paths) => $paths === [] ? ['/'] : array_values(array_unique($paths)), $origins);
    }

    /**
     * @return ?string null for an http(s) URL with a host
     */
    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function pathAndQuery(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';

        return isset($parts['query']) ? "{$path}?{$parts['query']}" : $path;
    }

    /**
     * @return RobotsTxt|string the parsed file, or the sentence saying why it was not checked
     */
    private function fetchRobots(string $origin): RobotsTxt|string
    {
        $host = parse_url($origin, PHP_URL_HOST);

        try {
            $robots = RobotsTxt::parse($this->fetcher->get("{$origin}/robots.txt", self::ROBOTS_MAX_BYTES));
        } catch (RequestException $e) {
            $status = $e->response->status();

            // A missing file restricts nothing (RFC 9309).
            if (in_array($status, [404, 410], true)) {
                return RobotsTxt::empty();
            }

            return "robots.txt on {$host} could not be read (HTTP {$status}), so it was not checked.";
        } catch (Throwable $e) {
            $this->reportUnexpected($e);

            return "robots.txt on {$host} could not be read, so it was not checked.";
        }

        return $robots->isRobotsFile() ? $robots : "robots.txt on {$host} is not a robots file, so it was not checked.";
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function findingsFor(RobotsTxt $robots, string $host, array $paths): array
    {
        $findings = [];

        $comment = $this->prohibitingComment($robots);

        if ($comment !== null) {
            $findings[] = "robots.txt on {$host} says: “{$comment}”";
        }

        $named = [];
        $unnamed = [];

        foreach (config('source_policies.ai_crawlers') as $crawler) {
            if (! $this->blocks($robots, $crawler, $paths)) {
                continue;
            }

            if ($robots->namesAgent($crawler)) {
                $named[] = $crawler;
            } else {
                $unnamed[] = $crawler;
            }
        }

        if ($named !== []) {
            $findings[] = "robots.txt on {$host} blocks ".implode(', ', $named).' from the pages checked.';
        }

        if ($unnamed !== []) {
            $findings[] = "robots.txt on {$host} blocks all crawlers without their own rules (User-agent: *) from the pages checked, AI crawlers included.";
        }

        return $findings;
    }

    /**
     * @param  list<string>  $paths
     */
    private function blocks(RobotsTxt $robots, string $crawler, array $paths): bool
    {
        return collect($paths)->contains(fn (string $path) => ! $robots->allows($crawler, $path));
    }

    /**
     * The first comment that mentions AI, LLM or text-and-data mining together with a prohibition.
     */
    private function prohibitingComment(RobotsTxt $robots): ?string
    {
        foreach ($robots->comments() as $comment) {
            if (preg_match(self::AI_TERMS, $comment) && preg_match(self::PROHIBITION_TERMS, $comment)) {
                return Str::limit($comment, 160);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private function publisherNotes(array $hosts): array
    {
        return collect(config('source_policies.publishers'))
            ->filter(fn (array $publisher) => collect($hosts)->contains(
                fn (string $host) => collect($publisher['domains'])->contains(
                    fn (string $domain) => $host === $domain || str_ends_with($host, ".{$domain}"),
                ),
            ))
            ->pluck('note')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $sentences
     */
    private function result(string $status, array $sentences): SourcePolicyResult
    {
        return new SourcePolicyResult(
            status: $status,
            note: implode(' ', $sentences),
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Unreachable, non-public or oversized responses are ordinary outcomes. Anything else is a bug and is logged.
     */
    private function reportUnexpected(Throwable $e): void
    {
        // UnsafeUrlException is an UnexpectedValueException.
        $ordinary = $e instanceof ConnectionException || $e instanceof RequestException || $e instanceof UnexpectedValueException;

        if (! $ordinary) {
            report($e);
        }
    }
}

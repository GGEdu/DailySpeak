<?php

namespace App\Services\News;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SimpleXMLElement;
use UnexpectedValueException;

class RssFeedReader
{
    private const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';

    public function __construct(private readonly SafeHttpFetcher $fetcher) {}

    /**
     * Download and parse an RSS 2.0 or Atom feed.
     *
     * @return Collection<int, FeedItem>
     *
     * @throws RequestException
     * @throws ConnectionException
     * @throws UnsafeUrlException
     * @throws UnexpectedValueException
     */
    public function read(string $url): Collection
    {
        return $this->parse($this->fetcher->get($url, config('news.max_feed_bytes'), retries: 2));
    }

    /**
     * Parse the items of an RSS 2.0 or Atom document, keeping the feed's order.
     *
     * @return Collection<int, FeedItem>
     *
     * @throws UnexpectedValueException
     */
    public function parse(string $xml): Collection
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $feed = simplexml_load_string($xml, options: LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $entries = match (true) {
            $feed === false => null,
            isset($feed->channel) => $this->rssItems($feed),
            $this->isAtom($feed) => $this->atomEntries($feed),
            default => null,
        };

        if ($entries === null) {
            throw new UnexpectedValueException('The document is not a valid RSS 2.0 or Atom feed.');
        }

        return $entries
            ->filter(fn (FeedItem $item) => $item->title !== '' && Str::isUrl($item->url, ['http', 'https']))
            ->unique('url')
            ->values();
    }

    /**
     * @return Collection<int, FeedItem>
     */
    private function rssItems(SimpleXMLElement $feed): Collection
    {
        return collect(iterator_to_array($feed->channel->item, false))
            ->map(fn (SimpleXMLElement $item) => new FeedItem(
                title: trim((string) $item->title),
                url: $this->withoutTrackingParameters(trim((string) $item->link)),
                publishedAt: $this->parseDate((string) $item->pubDate),
            ));
    }

    /**
     * @return Collection<int, FeedItem>
     */
    private function atomEntries(SimpleXMLElement $feed): Collection
    {
        return collect(iterator_to_array($feed->entry, false))
            ->map(fn (SimpleXMLElement $entry) => new FeedItem(
                title: trim((string) $entry->title),
                url: $this->withoutTrackingParameters($this->atomArticleLink($entry)),
                publishedAt: $this->parseDate($this->atomDate($entry)),
            ));
    }

    private function isAtom(SimpleXMLElement $feed): bool
    {
        return $feed->getName() === 'feed' && in_array(self::ATOM_NAMESPACE, $feed->getNamespaces(), true);
    }

    /**
     * The article is the link with rel="alternate", or a link without a rel (which Atom defaults to alternate).
     */
    private function atomArticleLink(SimpleXMLElement $entry): string
    {
        foreach ($entry->link as $link) {
            if ((string) ($link['rel'] ?? 'alternate') === 'alternate') {
                return trim((string) $link['href']);
            }
        }

        return '';
    }

    /**
     * Atom entries carry "published" (first publication) or "updated", or both.
     */
    private function atomDate(SimpleXMLElement $entry): string
    {
        $published = trim((string) $entry->published);

        return $published !== '' ? $published : trim((string) $entry->updated);
    }

    /**
     * Strip the analytics parameters (utm_*, BBC's at_*, traffic_source, fbclid) and the #fragment, so the URL
     * identifies the article. The rest of the query is kept exactly as the feed wrote it: it is not decoded
     * and encoded again.
     */
    private function withoutTrackingParameters(string $url): string
    {
        $url = Str::before($url, '#');

        if (! str_contains($url, '?')) {
            return $url;
        }

        [$base, $query] = explode('?', $url, 2);

        $kept = array_values(array_filter(
            explode('&', $query),
            fn (string $pair) => $pair !== '' && ! $this->isTrackingParameter(Str::before($pair, '=')),
        ));

        return $kept === [] ? $base : $base.'?'.implode('&', $kept);
    }

    private function isTrackingParameter(string $name): bool
    {
        return preg_match('/^(utm_|at_)/i', $name) === 1
            || in_array(strtolower($name), ['traffic_source', 'fbclid'], true);
    }

    /**
     * Parse an RFC 822 or RFC 3339 date into the application timezone, falling back to now. A date in the
     * future (a wrong clock, a typo) is brought back to now, or the article would stay at the top of the feed.
     */
    private function parseDate(string $date): CarbonImmutable
    {
        $parsed = rescue(
            fn () => CarbonImmutable::parse($date)->setTimezone(config('app.timezone')),
            fn () => CarbonImmutable::now(),
            report: false,
        );

        return $parsed->greaterThan(CarbonImmutable::now()) ? CarbonImmutable::now() : $parsed;
    }
}

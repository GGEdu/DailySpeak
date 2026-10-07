<?php

namespace App\Services\News;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use UnexpectedValueException;

class RssFeedReader
{
    /**
     * Download and parse an RSS 2.0 feed.
     *
     * @return Collection<int, FeedItem>
     *
     * @throws RequestException
     * @throws ConnectionException
     * @throws UnexpectedValueException
     */
    public function read(string $url): Collection
    {
        $xml = Http::timeout(15)
            ->retry(2, 500)
            ->withUserAgent(config('app.name').'/1.0 (+'.config('app.url').')')
            ->get($url)
            ->body();

        return $this->parse($xml);
    }

    /**
     * Parse the items of an RSS 2.0 document, keeping the feed's order.
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

        if ($feed === false || ! isset($feed->channel)) {
            throw new UnexpectedValueException('The document is not a valid RSS 2.0 feed.');
        }

        return collect(iterator_to_array($feed->channel->item, false))
            ->map(fn (SimpleXMLElement $item) => new FeedItem(
                title: trim((string) $item->title),
                url: $this->withoutTrackingParameters(trim((string) $item->link)),
                publishedAt: $this->parseDate((string) $item->pubDate),
            ))
            ->filter(fn (FeedItem $item) => $item->title !== '' && Str::isUrl($item->url, ['http', 'https']))
            ->unique('url')
            ->values();
    }

    /**
     * Strip analytics parameters (utm_*, BBC's at_*) so the URL identifies the article.
     */
    private function withoutTrackingParameters(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query)) {
            return $url;
        }

        parse_str($query, $parameters);

        $parameters = array_filter(
            $parameters,
            fn (string|int $key) => ! preg_match('/^(utm|at)_/', (string) $key),
            ARRAY_FILTER_USE_KEY,
        );

        $base = Str::before($url, '?');

        return $parameters === [] ? $base : $base.'?'.http_build_query($parameters);
    }

    /**
     * Parse an RFC 822 date into the application timezone, falling back to now.
     */
    private function parseDate(string $date): CarbonImmutable
    {
        return rescue(
            fn () => CarbonImmutable::parse($date)->setTimezone(config('app.timezone')),
            fn () => CarbonImmutable::now(),
            report: false,
        );
    }
}

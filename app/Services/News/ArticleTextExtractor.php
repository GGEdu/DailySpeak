<?php

namespace App\Services\News;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use UnexpectedValueException;

class ArticleTextExtractor
{
    /**
     * Paragraphs that belong to the article body, skipping image captions,
     * "related stories" lists and page chrome.
     */
    private const BODY_PARAGRAPHS = './/p[not(ancestor::figure) and not(ancestor::li) and not(ancestor::a)'
        .' and not(ancestor::aside) and not(ancestor::nav) and not(ancestor::footer)]';

    public function __construct(private readonly SafeHttpFetcher $fetcher) {}

    /**
     * Download an article page and return its plain-text body, or null if the page is unavailable
     * or has no usable text. Use download() to tell an unavailable page from one without text.
     */
    public function extract(string $url): ?string
    {
        try {
            return $this->fromHtml($this->download($url));
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Download an article page as HTML.
     *
     * @throws RequestException when the server answers with an error status
     * @throws ConnectionException when the host cannot be resolved or reached
     * @throws UnsafeUrlException when the URL is not a public http(s) address
     * @throws UnexpectedValueException when the page is larger than the configured limit
     */
    public function download(string $url): string
    {
        return $this->fetcher->get($url, config('news.max_page_bytes'));
    }

    /**
     * Extract the article body from an HTML document as paragraphs separated by blank lines.
     */
    public function fromHtml(string $html): ?string
    {
        $document = new DOMDocument;

        // The XML declaration makes libxml read the page as UTF-8 regardless of its <meta> tags.
        if (! @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET)) {
            return null;
        }

        $xpath = new DOMXPath($document);

        $root = $xpath->query('//article')->item(0)
            ?? $xpath->query('//main')->item(0)
            ?? $document->documentElement;

        $text = collect($xpath->query(self::BODY_PARAGRAPHS, $root))
            ->map(fn (DOMNode $paragraph) => trim((string) preg_replace('/\s+/u', ' ', $paragraph->textContent)))
            ->filter()
            ->implode("\n\n");

        return $text === '' ? null : Str::limit($text, config('news.max_article_characters'), '');
    }
}

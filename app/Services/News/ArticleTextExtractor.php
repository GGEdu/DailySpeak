<?php

namespace App\Services\News;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ArticleTextExtractor
{
    /**
     * Paragraphs that belong to the article body, skipping image captions,
     * "related stories" lists and page chrome.
     */
    private const BODY_PARAGRAPHS = './/p[not(ancestor::figure) and not(ancestor::li) and not(ancestor::a)'
        .' and not(ancestor::aside) and not(ancestor::nav) and not(ancestor::footer)]';

    /**
     * Download an article page and return its plain-text body, or null if unavailable.
     */
    public function extract(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withUserAgent(config('app.name').'/1.0 (+'.config('app.url').')')
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $this->fromHtml($response->body()) : null;
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

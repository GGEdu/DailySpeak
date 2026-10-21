<?php

namespace App\Services\News;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use UnexpectedValueException;

class ArticleTextExtractor
{
    /**
     * Where body text never is: image captions, navigation, page chrome.
     */
    private const OUTSIDE_BODY = 'not(ancestor::figure) and not(ancestor::a) and not(ancestor::aside)'
        .' and not(ancestor::nav) and not(ancestor::header) and not(ancestor::footer)';

    /**
     * The units of the article body: its paragraphs, and bullet points written without a paragraph inside.
     * "Related stories" teasers are list items with a link, so they are left out; plain bullet points of the
     * body are kept.
     */
    private const BODY_UNITS = './/p[not(ancestor::li[.//a]) and '.self::OUTSIDE_BODY.']'
        .' | .//li[not(.//p) and not(.//a) and '.self::OUTSIDE_BODY.']';

    /**
     * A page with fewer paragraphs is a video, a gallery or a listing, not an article.
     */
    private const MIN_PARAGRAPHS = 3;

    public function __construct(private readonly SafeHttpFetcher $fetcher) {}

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
     * Extract the article body from an HTML document as paragraphs separated by blank lines, or null when
     * there are fewer than MIN_PARAGRAPHS of them.
     */
    public function fromHtml(string $html): ?string
    {
        $document = new DOMDocument;

        // The XML declaration makes libxml read the page as UTF-8 regardless of its <meta> tags.
        if (! @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET)) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $paragraphs = $this->bestParagraphs($xpath, $document);

        // Some pages keep the full article in their JSON-LD; use it when it has more text than the markup.
        $structured = $this->structuredParagraphs($xpath);

        if ($structured->sum(fn (string $text) => mb_strlen($text)) > $paragraphs->sum(fn (string $text) => mb_strlen($text))) {
            $paragraphs = $structured;
        }

        if ($paragraphs->count() < self::MIN_PARAGRAPHS) {
            return null;
        }

        return $this->truncate($paragraphs->implode("\n\n"), config('news.max_article_characters'));
    }

    /**
     * The paragraphs of the <article> with the most text (live pages have one <article> per post, and the
     * first one can be a short intro), or of <main>, or of the whole document when there is neither.
     *
     * @return Collection<int, string>
     */
    private function bestParagraphs(DOMXPath $xpath, DOMDocument $document): Collection
    {
        $articles = collect(iterator_to_array($xpath->query('//article')));

        $roots = $articles->isNotEmpty()
            ? $articles
            : collect([$xpath->query('//main')->item(0) ?? $document->documentElement]);

        return $roots
            ->map(fn (DOMNode $root) => $this->paragraphs($xpath, $root))
            ->sortByDesc(fn (Collection $paragraphs) => $paragraphs->sum(fn (string $text) => mb_strlen($text)))
            ->first() ?? collect();
    }

    /**
     * @return Collection<int, string>
     */
    private function paragraphs(DOMXPath $xpath, DOMNode $root): Collection
    {
        return collect(iterator_to_array($xpath->query(self::BODY_UNITS, $root)))
            ->map(fn (DOMNode $paragraph) => $this->normalise($paragraph->textContent))
            ->filter()
            ->values();
    }

    /**
     * The articleBody of the first JSON-LD block that has one, as paragraphs.
     *
     * @return Collection<int, string>
     */
    private function structuredParagraphs(DOMXPath $xpath): Collection
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $body = $this->articleBody(json_decode($script->textContent, true));

            if ($body !== null) {
                return collect(preg_split('/\R+/u', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
                    ->map(fn (string $text) => $this->normalise($text))
                    ->filter()
                    ->values();
            }
        }

        return collect();
    }

    private function articleBody(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        if (is_string($data['articleBody'] ?? null) && trim($data['articleBody']) !== '') {
            return $data['articleBody'];
        }

        foreach ($data as $value) {
            $body = $this->articleBody($value);

            if ($body !== null) {
                return $body;
            }
        }

        return null;
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Cut to the limit without leaving the last word in half.
     */
    private function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $head = mb_substr($text, 0, $limit);
        $whole = (string) preg_replace('/\s\S*$/u', '', $head);

        return $whole === '' ? $head : $whole;
    }
}

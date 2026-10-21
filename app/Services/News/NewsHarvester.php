<?php

namespace App\Services\News;

use App\Ai\Agents\NewsSummarizer;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Reads one feed, summarises its new articles and stores them. Used by `news:fetch`
 * (the daily run and the console) and by App\Jobs\FetchNewsSource ("Fetch now").
 */
class NewsHarvester
{
    /**
     * Downloads allowed per article wanted, so a feed full of videos is not read forever.
     */
    private const DOWNLOADS_PER_ARTICLE = 3;

    public const SAVED = 'saved';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /**
     * Paths of pages that are never articles (video, live blogs, audio, podcasts). Checked before
     * downloading, so they cost no request and no LLM call.
     */
    private const NON_ARTICLE_PATH = '#/(videos?|live|audio|podcasts?)(/|$)#i';

    public function __construct(
        private readonly RssFeedReader $reader,
        private readonly ArticleTextExtractor $extractor,
    ) {}

    /**
     * Read a feed and summarise up to $limit of its new articles, in feed order.
     *
     * Items whose page has no text are remembered for a few days so they are not downloaded
     * every run. The run stops when $limit articles are saved, when it has made
     * DOWNLOADS_PER_ARTICLE downloads per wanted article, or when $until has passed (checked
     * before each article, so the last one may run past it).
     *
     * @param  (Closure(string $title, string $status, ?string $detail): void)|null  $onItem  one call per item, for the console
     */
    public function harvest(
        string $feedUrl,
        ?NewsSource $source,
        int $limit,
        ?CarbonInterface $until = null,
        ?Closure $onItem = null,
    ): HarvestResult {
        try {
            $items = $this->reader->read($feedUrl);
        } catch (Throwable $e) {
            report($e);
            $message = Str::limit($e->getMessage(), 1000);
            $source?->update(['last_error' => $message]);

            return new HarvestResult(failed: 1, readError: $message);
        }

        $known = NewsArticle::whereIn('source_url', $items->pluck('url'))->pluck('source_url');
        $saved = $skipped = $failed = $downloads = 0;
        $firstError = null;
        $outOfTime = false;

        foreach ($items->whereNotIn('url', $known) as $item) {
            if ($saved >= $limit || $downloads >= $limit * self::DOWNLOADS_PER_ARTICLE) {
                break;
            }

            if ($until !== null && now()->greaterThanOrEqualTo($until)) {
                $outOfTime = true;
                break;
            }

            if ($this->isNotAnArticleUrl($item->url)) {
                $skipped++;
                $this->notify($onItem, $item->title, self::SKIPPED, 'not an article (by its URL)');

                continue;
            }

            if (Cache::has($this->skipKey($item->url))) {
                $skipped++;
                $this->notify($onItem, $item->title, self::SKIPPED, 'no article text (checked recently)');

                continue;
            }

            $downloads++;
            [$status, $detail] = $this->harvestItem($item, $source);

            match ($status) {
                self::SAVED => $saved++,
                self::SKIPPED => $skipped++,
                default => $failed++,
            };

            if ($status === self::FAILED) {
                $firstError ??= $detail;
            }

            $this->notify($onItem, $item->title, $status, $detail);
        }

        $this->recordRun($source, $saved, $failed, $firstError, $outOfTime);

        return new HarvestResult(created: $saved, skipped: $skipped, failed: $failed);
    }

    /**
     * Download, summarise and store one item.
     *
     * @return array{0: string, 1: ?string} the outcome and a short detail for the console
     */
    private function harvestItem(FeedItem $item, ?NewsSource $source): array
    {
        try {
            $html = $this->extractor->download($item->url);
        } catch (Throwable $e) {
            // Logged, and not remembered: the page may be fine tomorrow.
            report($e);

            return [self::FAILED, 'could not download: '.$e->getMessage()];
        }

        $text = $this->extractor->fromHtml($html);

        if ($text === null || mb_strlen($text) < config('news.min_article_characters')) {
            Cache::put($this->skipKey($item->url), true, now()->addDays(config('news.skipped_items_days')));

            return [self::SKIPPED, 'no article text'];
        }

        try {
            $article = $this->summarise($item, $text);

            // The model says the page is not an article (a video, a live blog, a listing): remember it like a page without text.
            if ($article === null) {
                Cache::put($this->skipKey($item->url), true, now()->addDays(config('news.skipped_items_days')));

                return [self::SKIPPED, 'no article text'];
            }

            NewsArticle::create([
                'news_source_id' => $source?->id,
                'title' => Str::limit($item->title, 255, ''),
                'source_url' => $item->url,
                'summary' => $article['summary'],
                'key_vocabulary' => $article['vocabulary'],
                'published_at' => $item->publishedAt,
            ]);
        } catch (Throwable $e) {
            report($e);

            return [self::FAILED, $e->getMessage()];
        }

        return [self::SAVED, null];
    }

    /**
     * Ask the LLM for the summary and key vocabulary of an article.
     *
     * @return array{summary: string, vocabulary: list<string>}|null null when the model says the page is not an article
     */
    private function summarise(FeedItem $item, string $text): ?array
    {
        $response = NewsSummarizer::make()->prompt("Title: {$item->title}\n\n{$text}");

        if (($response['is_article'] ?? false) !== true) {
            return null;
        }

        // Models often separate paragraphs with a single newline; the UI expects blank lines.
        $summary = collect(preg_split('/\R+/u', (string) ($response['summary'] ?? '')))
            ->map(fn (string $paragraph) => trim($paragraph))
            ->filter()
            ->implode("\n\n");

        $vocabulary = collect($response['vocabulary'] ?? [])
            ->filter(fn ($word) => is_string($word) && trim($word) !== '')
            ->map(fn (string $word) => trim($word))
            ->unique(fn (string $word) => mb_strtolower($word))
            ->take(NewsSummarizer::VOCABULARY_SIZE)
            ->values()
            ->all();

        if ($summary === '' || $vocabulary === []) {
            throw new UnexpectedValueException('The model returned an empty summary or vocabulary.');
        }

        return ['summary' => $summary, 'vocabulary' => $vocabulary];
    }

    /**
     * Record the outcome of the run on the source, which the admin page shows: when it finished, and what
     * went wrong. Partial failures are reported too, even when some articles were saved.
     */
    private function recordRun(?NewsSource $source, int $saved, int $failed, ?string $firstError, bool $outOfTime): void
    {
        if ($source === null) {
            return;
        }

        $problems = [];

        if ($failed > 0) {
            $problems[] = "{$failed} article(s) failed. First error: {$firstError}";
        }

        if ($outOfTime) {
            $problems[] = "Stopped at the time limit after {$saved} article(s). Fetch the source again to read the rest.";
        }

        $source->update([
            'last_fetched_at' => now(),
            'last_error' => $problems === [] ? null : Str::limit(implode(' ', $problems), 1000),
        ]);
    }

    private function isNotAnArticleUrl(string $url): bool
    {
        return preg_match(self::NON_ARTICLE_PATH, (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    private function notify(?Closure $onItem, string $title, string $status, ?string $detail): void
    {
        if ($onItem !== null) {
            $onItem($title, $status, $detail);
        }
    }

    private function skipKey(string $url): string
    {
        return 'news:skipped:'.sha1($url);
    }
}

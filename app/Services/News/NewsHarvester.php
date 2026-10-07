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

        $source?->update(['last_fetched_at' => now(), 'last_error' => null]);

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
            // A failed download is not remembered: the page may be fine tomorrow.
            return [self::SKIPPED, 'could not download: '.$e->getMessage()];
        }

        $text = $this->extractor->fromHtml($html);

        if ($text === null || mb_strlen($text) < config('news.min_article_characters')) {
            Cache::put($this->skipKey($item->url), true, now()->addDays(config('news.skipped_items_days')));

            return [self::SKIPPED, 'no article text'];
        }

        try {
            [$summary, $vocabulary] = $this->summarise($item, $text);

            NewsArticle::create([
                'news_source_id' => $source?->id,
                'title' => Str::limit($item->title, 255, ''),
                'source_url' => $item->url,
                'summary' => $summary,
                'key_vocabulary' => $vocabulary,
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
     * @return array{0: string, 1: list<string>}
     */
    private function summarise(FeedItem $item, string $text): array
    {
        $response = NewsSummarizer::make()->prompt("Title: {$item->title}\n\n{$text}");

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

        return [$summary, $vocabulary];
    }

    /**
     * Keep the source's last_error in step with the run, so the admin page shows what went wrong.
     * A run that saved nothing because every summary failed is an error; a run stopped by its time
     * limit is not, but the admin should know the feed was not read to the end.
     */
    private function recordRun(?NewsSource $source, int $saved, int $failed, ?string $firstError, bool $outOfTime): void
    {
        if ($source === null) {
            return;
        }

        $error = match (true) {
            $failed > 0 && $saved === 0 => "{$failed} article(s) could not be summarised: {$firstError}",
            $outOfTime => "Stopped at the time limit after {$saved} article(s). Fetch the source again to read the rest.",
            default => null,
        };

        $source->update(['last_error' => $error === null ? null : Str::limit($error, 1000)]);
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

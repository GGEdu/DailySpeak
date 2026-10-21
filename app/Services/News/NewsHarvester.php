<?php

namespace App\Services\News;

use App\Ai\Agents\NewsSummarizer;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /** Longest URL the source_url column holds; a longer one would fail to store after the model was paid. */
    private const MAX_URL_LENGTH = 2048;

    /** Markers around the page text in the prompt; the instructions say what is between them is data. */
    private const TEXT_OPEN = '<article_text>';

    private const TEXT_CLOSE = '</article_text>';

    /** Paragraphs a summary may have. The prompt asks for three. */
    private const SUMMARY_PARAGRAPHS = [2, 5];

    /**
     * Terms the model gave that are not in the article are dropped. A few paraphrases are tolerated, but an
     * answer where fewer than this many terms are the article's own is refused.
     */
    private const MIN_VOCABULARY_IN_TEXT = 3;

    /** Common English words: a summary with at least this many different ones is written in English. */
    private const ENGLISH_WORDS = [
        'the', 'and', 'of', 'to', 'in', 'is', 'that', 'for', 'with', 'it', 'on', 'as', 'was', 'are', 'by',
        'from', 'this', 'be', 'have', 'has', 'their', 'they', 'which', 'at', 'or', 'not',
    ];

    private const ENGLISH_WORDS_NEEDED = 5;

    public function __construct(
        private readonly RssFeedReader $reader,
        private readonly ArticleTextExtractor $extractor,
    ) {}

    /**
     * Read a feed and summarise up to $limit of its new articles, in feed order.
     *
     * Items that cannot be summarised are remembered for a day, so a model failure is not paid for again
     * every run, and items whose page has no text for a few days. The run stops when $limit articles are
     * saved, when it has made DOWNLOADS_PER_ARTICLE downloads per wanted article, when
     * config('news.max_consecutive_failures') articles in a row failed (the source is probably broken or the
     * model is down), or when $until has passed (checked before each article, so the last one may run past it).
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

        // Looked up in slices, so a feed with thousands of items does not exceed the database's bind limit.
        $known = collect($items->pluck('url'))
            ->chunk(500)
            ->flatMap(fn (Collection $urls) => NewsArticle::whereIn('source_url', $urls)->pluck('source_url'));
        $saved = $skipped = $failed = $downloads = $consecutiveFailures = 0;
        $firstError = null;
        $outOfTime = $tooManyFailures = false;

        foreach ($items->whereNotIn('url', $known) as $item) {
            if ($saved >= $limit || $downloads >= $limit * self::DOWNLOADS_PER_ARTICLE) {
                break;
            }

            if ($until !== null && now()->greaterThanOrEqualTo($until)) {
                $outOfTime = true;
                break;
            }

            if ($consecutiveFailures >= config('news.max_consecutive_failures')) {
                $tooManyFailures = true;
                break;
            }

            if ($this->isNotAnArticleUrl($item->url)) {
                $skipped++;
                $this->notify($onItem, $item->title, self::SKIPPED, 'not an article (by its URL)');

                continue;
            }

            if (strlen($item->url) > self::MAX_URL_LENGTH) {
                $skipped++;
                $this->notify($onItem, $item->title, self::SKIPPED, 'URL is too long to store');

                continue;
            }

            if (Cache::has($this->skipKey($item->url))) {
                $skipped++;
                $this->notify($onItem, $item->title, self::SKIPPED, 'no article text (checked recently)');

                continue;
            }

            if (Cache::has($this->failedKey($item->url))) {
                $skipped++;
                $this->notify($onItem, $item->title, self::SKIPPED, 'failed recently, not retried yet');

                continue;
            }

            $downloads++;
            [$status, $detail] = $this->harvestItem($item, $source);

            match ($status) {
                self::SAVED => $saved++,
                self::SKIPPED => $skipped++,
                default => $failed++,
            };

            $consecutiveFailures = $status === self::FAILED ? $consecutiveFailures + 1 : 0;

            if ($status === self::FAILED) {
                $firstError ??= $detail;
            }

            $this->notify($onItem, $item->title, $status, $detail);
        }

        $this->recordRun($source, $saved, $failed, $firstError, $outOfTime, $tooManyFailures);

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

        // "Fetch now" and the daily run may both be reading this feed: do not pay for an article another run stored.
        if (NewsArticle::where('source_url', $item->url)->exists()) {
            return [self::SKIPPED, 'stored by another run'];
        }

        try {
            $article = $this->summarise($item, $text);
        } catch (Throwable $e) {
            report($e);
            Cache::put($this->failedKey($item->url), true, now()->addHours(config('news.failed_summary_hours')));

            return [self::FAILED, $e->getMessage()];
        }

        // The model says the page is not an article (a video, a live blog, a listing): remember it like a page without text.
        if ($article === null) {
            Cache::put($this->skipKey($item->url), true, now()->addDays(config('news.skipped_items_days')));

            return [self::SKIPPED, 'no article text'];
        }

        try {
            // Its own transaction, so a duplicate does not abort an enclosing one (a test's, or a caller's).
            DB::transaction(fn () => NewsArticle::create([
                'news_source_id' => $source?->id,
                'title' => Str::limit($item->title, 255, ''),
                'source_url' => $item->url,
                'summary' => $article['summary'],
                'key_vocabulary' => $article['vocabulary'],
                'published_at' => $item->publishedAt,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another run stored the same article while this one was summarising it.
            return [self::SKIPPED, 'stored by another run'];
        } catch (Throwable $e) {
            report($e);

            return [self::FAILED, $e->getMessage()];
        }

        return [self::SAVED, null];
    }

    /**
     * Ask the LLM for the summary and key vocabulary of an article, and check what it answers: the summary
     * must be a few English paragraphs with no links, and the vocabulary must be words of the article.
     *
     * @return array{summary: string, vocabulary: list<string>}|null null when the model says the page is not an article
     *
     * @throws UnexpectedValueException when the answer is not a usable summary
     */
    private function summarise(FeedItem $item, string $text): ?array
    {
        // The page text is data, not instructions: the closing marker is removed so it cannot end the block early.
        $delimited = str_ireplace(self::TEXT_CLOSE, '', $text);
        $response = NewsSummarizer::make()->prompt("Title: {$item->title}\n\n".self::TEXT_OPEN."\n{$delimited}\n".self::TEXT_CLOSE);

        if (($response['is_article'] ?? false) !== true) {
            return null;
        }

        // Models often separate paragraphs with a single newline; the UI expects blank lines.
        $paragraphs = collect(preg_split('/\R+/u', (string) ($response['summary'] ?? '')))
            ->map(fn (string $paragraph) => trim($paragraph))
            ->filter()
            ->values();
        $summary = $paragraphs->implode("\n\n");

        $vocabulary = collect($response['vocabulary'] ?? [])
            ->filter(fn ($word) => is_string($word) && trim($word) !== '')
            ->map(fn (string $word) => trim($word))
            ->unique(fn (string $word) => mb_strtolower($word))
            ->filter(fn (string $word) => str_contains(mb_strtolower($text), mb_strtolower($word)))
            ->take(NewsSummarizer::VOCABULARY_SIZE)
            ->values()
            ->all();

        if ($summary === '' || $vocabulary === []) {
            throw new UnexpectedValueException('The model returned an empty summary or vocabulary.');
        }

        if (count($vocabulary) < self::MIN_VOCABULARY_IN_TEXT) {
            throw new UnexpectedValueException('Only '.count($vocabulary).' of the vocabulary terms appear in the article.');
        }

        $this->assertPlausibleSummary($summary, $paragraphs->count());

        return ['summary' => $summary, 'vocabulary' => $vocabulary];
    }

    /**
     * @throws UnexpectedValueException
     */
    private function assertPlausibleSummary(string $summary, int $paragraphs): void
    {
        [$fewest, $most] = self::SUMMARY_PARAGRAPHS;

        if ($paragraphs < $fewest || $paragraphs > $most) {
            throw new UnexpectedValueException("The model returned a summary of {$paragraphs} paragraph(s), not {$fewest} to {$most}.");
        }

        if (mb_strlen($summary) > config('news.max_summary_characters')) {
            throw new UnexpectedValueException('The model returned a summary longer than '.config('news.max_summary_characters').' characters.');
        }

        if (preg_match('#https?://|www\.#i', $summary) === 1) {
            throw new UnexpectedValueException('The model put a link in the summary.');
        }

        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($summary), -1, PREG_SPLIT_NO_EMPTY);

        if (count(array_intersect(self::ENGLISH_WORDS, $words)) < self::ENGLISH_WORDS_NEEDED) {
            throw new UnexpectedValueException('The model did not write the summary in English.');
        }
    }

    /**
     * Record the outcome of the run on the source, which the admin page shows: when it finished, and what
     * went wrong. Partial failures are reported too, even when some articles were saved.
     */
    private function recordRun(
        ?NewsSource $source,
        int $saved,
        int $failed,
        ?string $firstError,
        bool $outOfTime,
        bool $tooManyFailures,
    ): void {
        if ($source === null) {
            return;
        }

        $problems = [];

        if ($failed > 0) {
            $problems[] = "{$failed} article(s) failed. First error: {$firstError}";
        }

        if ($tooManyFailures) {
            $problems[] = 'Stopped after '.config('news.max_consecutive_failures').' failures in a row. The rest will be tried in the next run.';
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

    private function failedKey(string $url): string
    {
        return 'news:failed:'.sha1($url);
    }
}

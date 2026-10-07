<?php

namespace App\Console\Commands;

use App\Ai\Agents\NewsSummarizer;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use App\Services\News\ArticleTextExtractor;
use App\Services\News\FeedItem;
use App\Services\News\RssFeedReader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

#[Signature('news:fetch
    {--source=* : ID of a news source to read (default: every active source)}
    {--feed=* : Ad-hoc RSS feed URL to read instead of the stored sources}
    {--limit= : Maximum number of new articles to summarise per feed}')]
#[Description('Harvest the latest news from RSS feeds and summarise them for C1 learners')]
class FetchDailyNews extends Command
{
    private int $created = 0;

    private int $skipped = 0;

    private int $failed = 0;

    /**
     * Execute the console command.
     */
    public function handle(RssFeedReader $reader, ArticleTextExtractor $extractor): int
    {
        $limit = (int) ($this->option('limit') ?? config('news.max_articles_per_feed'));

        foreach ($this->feeds() as [$feed, $source]) {
            $this->components->info("Reading {$feed}");

            try {
                $items = $reader->read($feed);
            } catch (Throwable $e) {
                report($e);
                $this->components->error("Could not read the feed: {$e->getMessage()}");
                $source?->update(['last_error' => Str::limit($e->getMessage(), 1000)]);
                $this->failed++;

                continue;
            }

            $source?->update(['last_fetched_at' => now(), 'last_error' => null]);

            $known = NewsArticle::whereIn('source_url', $items->pluck('url'))->pluck('source_url');

            $items->whereNotIn('url', $known)
                ->take($limit)
                ->each(fn (FeedItem $item) => $this->harvest($item, $extractor, $source));
        }

        $this->newLine();
        $this->components->info("Saved {$this->created}, skipped {$this->skipped}, failed {$this->failed}.");

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Feeds to read, each paired with its stored source (null for ad-hoc --feed URLs).
     *
     * @return list<array{0: string, 1: ?NewsSource}>
     */
    private function feeds(): array
    {
        if ($urls = $this->option('feed')) {
            return array_map(fn (string $url) => [$url, null], $urls);
        }

        // Explicit --source IDs may include paused sources (e.g. to test one from the admin page).
        $sources = NewsSource::query()
            ->when(
                $this->option('source'),
                fn ($query, array $ids) => $query->whereKey($ids),
                fn ($query) => $query->where('is_active', true),
            )
            ->orderBy('id')
            ->get();

        if ($sources->isEmpty()) {
            $this->components->warn('There are no active news sources. Add one at /admin/sources.');
        }

        return $sources->map(fn (NewsSource $source) => [$source->feed_url, $source])->all();
    }

    /**
     * Summarise a single feed item and store it as a news article.
     */
    private function harvest(FeedItem $item, ArticleTextExtractor $extractor, ?NewsSource $source): void
    {
        $text = $extractor->extract($item->url);

        if ($text === null || mb_strlen($text) < config('news.min_article_characters')) {
            $this->components->twoColumnDetail($item->title, '<fg=yellow>SKIPPED (no article text)</>');
            $this->skipped++;

            return;
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
            $this->components->twoColumnDetail($item->title, '<fg=red>FAILED</>');
            $this->components->bulletList([$e->getMessage()]);
            $this->failed++;

            return;
        }

        $this->components->twoColumnDetail($item->title, '<fg=green>SAVED</>');
        $this->created++;
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
}

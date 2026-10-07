<?php

namespace App\Console\Commands;

use App\Models\NewsSource;
use App\Services\News\NewsHarvester;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('news:fetch
    {--source=* : ID of a news source to read (default: every active source)}
    {--feed=* : Ad-hoc RSS feed URL to read instead of the stored sources}
    {--limit= : Maximum number of new articles to summarise per feed}')]
#[Description('Harvest the latest news from RSS feeds and summarise them for C1 learners')]
class FetchDailyNews extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NewsHarvester $harvester): int
    {
        $limit = (int) ($this->option('limit') ?? config('news.max_articles_per_feed'));
        $created = $skipped = $failed = 0;

        foreach ($this->feeds() as [$feed, $source]) {
            $this->components->info("Reading {$feed}");

            $result = $harvester->harvest($feed, $source, $limit, onItem: $this->printItem(...));

            if ($result->readError !== null) {
                $this->components->error("Could not read the feed: {$result->readError}");
            }

            $created += $result->created;
            $skipped += $result->skipped;
            $failed += $result->failed;
        }

        $this->newLine();
        $this->components->info("Saved {$created}, skipped {$skipped}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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
     * One console line per item: saved, skipped with the reason, or failed with the error.
     */
    private function printItem(string $title, string $status, ?string $detail): void
    {
        if ($status === NewsHarvester::SAVED) {
            $this->components->twoColumnDetail($title, '<fg=green>SAVED</>');

            return;
        }

        if ($status === NewsHarvester::SKIPPED) {
            $this->components->twoColumnDetail($title, "<fg=yellow>SKIPPED ({$detail})</>");

            return;
        }

        $this->components->twoColumnDetail($title, '<fg=red>FAILED</>');
        $this->components->bulletList([(string) $detail]);
    }
}

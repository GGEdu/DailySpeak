<?php

namespace App\Jobs;

use App\Models\NewsSource;
use App\Services\News\NewsHarvester;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Harvests one news source on demand ("Fetch now" in /admin/sources).
 *
 * A harvest downloads and summarises several articles, which takes minutes, so it runs on its own
 * connection and supervisor instead of the default queue (whose supervisor times out at 120 s).
 * The limits must stay in step: this timeout <= the `supervisor-news` timeout in config/horizon.php
 * < the `news` connection's retry_after in config/queue.php. Harvesting also stops starting new
 * articles after TIME_BUDGET_SECONDS, so a run ends before the timeout however slow the model is.
 */
class FetchNewsSource implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    /** Seconds after which no new article is started. The last one may still take its download and LLM time. */
    public const TIME_BUDGET_SECONDS = 1200;

    public $timeout = 1500;

    public $tries = 1;

    /** A second "Fetch now" for the same source is ignored while one is queued or running. */
    public $uniqueFor = 1500;

    public function __construct(public int $sourceId)
    {
        // Set through the trait's methods: Queueable declares $connection and $queue itself.
        $this->onConnection('news');
        $this->onQueue('news');
    }

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(NewsHarvester $harvester): void
    {
        $source = NewsSource::find($this->sourceId);

        // The source may have been deleted while the job waited.
        if ($source === null) {
            return;
        }

        $harvester->harvest(
            $source->feed_url,
            $source,
            config('news.max_articles_per_feed'),
            now()->addSeconds(self::TIME_BUDGET_SECONDS),
        );
    }

    /**
     * Show the failure on the admin page, which only reads the source's last_error.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        NewsSource::find($this->sourceId)?->update(['last_error' => Str::limit($exception->getMessage(), 1000)]);
    }
}

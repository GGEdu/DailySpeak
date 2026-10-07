<?php

namespace Tests\Feature\Jobs;

use App\Ai\Agents\NewsSummarizer;
use App\Jobs\FetchNewsSource;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class FetchNewsSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
    }

    public function test_its_timeouts_fit_its_supervisor_and_its_connection(): void
    {
        // A job that outlives its worker is killed; a job that outlives retry_after is delivered twice.
        $job = new FetchNewsSource(1);
        $supervisor = config('horizon.defaults.supervisor-news');

        $this->assertSame('news', $job->connection);
        $this->assertSame('news', $job->queue);
        $this->assertSame('news', $supervisor['connection']);
        $this->assertSame(['news'], $supervisor['queue']);
        $this->assertLessThanOrEqual($supervisor['timeout'], $job->timeout);
        $this->assertLessThan(config('queue.connections.news.retry_after'), $supervisor['timeout']);
    }

    public function test_the_time_budget_ends_before_the_job_timeout(): void
    {
        $this->assertLessThan((new FetchNewsSource(1))->timeout, FetchNewsSource::TIME_BUDGET_SECONDS);
    }

    public function test_a_second_fetch_of_the_same_source_is_not_queued_while_one_is_pending(): void
    {
        $job = new FetchNewsSource(7);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('7', $job->uniqueId());
    }

    public function test_it_harvests_the_source_even_when_it_is_paused(): void
    {
        $source = NewsSource::factory()->inactive()->create(['feed_url' => 'https://feeds.example.test/world.xml']);
        $this->fakeFeed();
        $this->fakeSummaries();

        FetchNewsSource::dispatchSync($source->id);

        $this->assertSame(2, $source->articles()->count());
        $this->assertNotNull($source->fresh()->last_fetched_at);
        $this->assertNull($source->fresh()->last_error);
    }

    public function test_it_stops_starting_articles_once_the_time_budget_is_spent(): void
    {
        $source = NewsSource::factory()->create(['feed_url' => 'https://feeds.example.test/world.xml']);
        $this->fakeFeed();
        NewsSummarizer::fake(function () {
            // A slow model: the first article uses up the whole budget.
            $this->travel(FetchNewsSource::TIME_BUDGET_SECONDS + 1)->seconds();

            return ['summary' => "One.\n\nTwo.\n\nThree.", 'vocabulary' => ['a', 'b', 'c', 'd', 'e']];
        })->preventStrayPrompts();

        FetchNewsSource::dispatchSync($source->id);

        NewsSummarizer::assertPromptedTimes(1);
        $this->assertSame(1, NewsArticle::count());
        $this->assertStringContainsString('time limit', $source->fresh()->last_error);
    }

    public function test_a_job_that_fails_records_the_error_on_its_source(): void
    {
        $source = NewsSource::factory()->create();

        (new FetchNewsSource($source->id))->failed(new RuntimeException('Worker ran out of time'));

        $this->assertSame('Worker ran out of time', $source->fresh()->last_error);
    }

    public function test_a_job_for_a_deleted_source_does_nothing(): void
    {
        FetchNewsSource::dispatchSync(999_999);

        Http::assertNothingSent();
    }

    private function fakeFeed(): void
    {
        Http::fake([
            'feeds.example.test/*' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml'))),
            'www.bbc.co.uk/news/articles/*' => Http::response(file_get_contents(base_path('tests/Fixtures/news/article.html'))),
            'www.bbc.co.uk/news/videos/*' => Http::response('<html><body><main><video src="clip.mp4"></video></main></body></html>'),
        ]);
    }

    private function fakeSummaries(): void
    {
        NewsSummarizer::fake(fn () => [
            'summary' => "One.\n\nTwo.\n\nThree.",
            'vocabulary' => ['a', 'b', 'c', 'd', 'e'],
        ])->preventStrayPrompts();
    }
}

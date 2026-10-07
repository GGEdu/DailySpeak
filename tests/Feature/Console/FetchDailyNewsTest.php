<?php

namespace Tests\Feature\Console;

use App\Ai\Agents\NewsSummarizer;
use App\Models\NewsArticle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

class FetchDailyNewsTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSPORT_URL = 'https://www.bbc.co.uk/news/articles/transport1';

    private const GENES_URL = 'https://www.bbc.co.uk/news/articles/genes2?page=2';

    private const SUMMARY = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";

    private const VOCABULARY = ['overhaul', 'congestion', 'long overdue', 'overly ambitious', 'stall'];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();

        config(['news.feeds' => ['https://feeds.example.test/world.xml']]);
    }

    public function test_it_summarises_new_articles_and_stores_them(): void
    {
        $this->fakeHttp();
        $this->fakeSummaries();

        $this->artisan('news:fetch')
            ->expectsOutputToContain('SKIPPED')
            ->expectsOutputToContain('Saved 2, skipped 1, failed 0.')
            ->assertSuccessful();

        $this->assertSame(2, NewsArticle::count());

        $article = NewsArticle::firstWhere('source_url', self::TRANSPORT_URL);
        $this->assertSame('City council approves sweeping overhaul of public transport', $article->title);
        $this->assertSame(self::SUMMARY, $article->summary);
        $this->assertSame(self::VOCABULARY, $article->key_vocabulary);
        $this->assertSame('2026-10-07 12:16:58', $article->published_at->toDateTimeString());
        $this->assertDatabaseHas('news_articles', ['source_url' => self::GENES_URL]);

        NewsSummarizer::assertPromptedTimes(2);
        NewsSummarizer::assertPrompted(fn (AgentPrompt $prompt) => str_starts_with($prompt->prompt, 'Title: City council approves')
            && str_contains($prompt->prompt, 'Mayor José Núñez said the reform')
            && ! str_contains($prompt->prompt, 'Commuters queue'));
    }

    public function test_it_uses_the_configured_prompt_provider_and_model(): void
    {
        config(['news.ai.provider' => 'openai', 'news.ai.model' => 'gpt-4o-mini']);
        $this->fakeHttp();
        $this->fakeSummaries();

        $this->artisan('news:fetch')->assertSuccessful();

        NewsSummarizer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() === 'openai'
            && $prompt->model === 'gpt-4o-mini'
            && str_contains($prompt->agent->instructions(), 'Resume esta noticia en 3 párrafos para un estudiante de inglés C1'));
    }

    public function test_it_does_not_summarise_articles_that_are_already_stored(): void
    {
        NewsArticle::factory()->create(['source_url' => self::TRANSPORT_URL]);
        $this->fakeHttp();
        $this->fakeSummaries();

        $this->artisan('news:fetch')->assertSuccessful();

        NewsSummarizer::assertPromptedTimes(1);
        NewsSummarizer::assertNotPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'City council'));
        $this->assertSame(2, NewsArticle::count());
    }

    public function test_it_skips_articles_whose_page_has_no_usable_text(): void
    {
        $this->fakeHttp(['www.bbc.co.uk/news/articles/genes2*' => Http::response('Not found', 404)]);
        $this->fakeSummaries();

        $this->artisan('news:fetch')
            ->expectsOutputToContain('Saved 1, skipped 2, failed 0.')
            ->assertSuccessful();

        NewsSummarizer::assertPromptedTimes(1);
        $this->assertDatabaseMissing('news_articles', ['source_url' => self::GENES_URL]);
    }

    public function test_a_failed_summary_does_not_stop_the_other_articles(): void
    {
        $this->fakeHttp();
        NewsSummarizer::fake(function (string $prompt) {
            throw_if(str_contains($prompt, 'City council'), new RuntimeException('Rate limit exceeded'));

            return ['summary' => self::SUMMARY, 'vocabulary' => self::VOCABULARY];
        })->preventStrayPrompts();

        $this->artisan('news:fetch')
            ->expectsOutputToContain('Rate limit exceeded')
            ->expectsOutputToContain('Saved 1, skipped 1, failed 1.')
            ->assertFailed();

        $this->assertDatabaseMissing('news_articles', ['source_url' => self::TRANSPORT_URL]);
        $this->assertDatabaseHas('news_articles', ['source_url' => self::GENES_URL]);
    }

    public function test_incomplete_model_output_is_not_stored(): void
    {
        $this->fakeHttp();
        NewsSummarizer::fake(fn () => ['summary' => ' ', 'vocabulary' => []])->preventStrayPrompts();

        $this->artisan('news:fetch')
            ->expectsOutputToContain('The model returned an empty summary or vocabulary')
            ->expectsOutputToContain('Saved 0, skipped 1, failed 2.')
            ->assertFailed();

        $this->assertSame(0, NewsArticle::count());
    }

    public function test_vocabulary_is_trimmed_deduplicated_and_capped(): void
    {
        $this->fakeHttp();
        NewsSummarizer::fake(fn () => [
            'summary' => self::SUMMARY,
            'vocabulary' => [' Overhaul ', 'overhaul', '', 'congestion', 'stall', 'nuance', 'scrutiny', 'bolster'],
        ])->preventStrayPrompts();

        $this->artisan('news:fetch', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(
            ['Overhaul', 'congestion', 'stall', 'nuance', 'scrutiny'],
            NewsArticle::sole()->key_vocabulary,
        );
    }

    public function test_feed_and_limit_options_override_the_configuration(): void
    {
        $this->fakeHttp(['feeds.other.test/*' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml')))]);
        $this->fakeSummaries();

        $this->artisan('news:fetch', ['--feed' => ['https://feeds.other.test/rss.xml'], '--limit' => 1])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://feeds.other.test/rss.xml');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'feeds.example.test'));
        NewsSummarizer::assertPromptedTimes(1);
    }

    public function test_summary_paragraphs_are_separated_by_blank_lines(): void
    {
        $this->fakeHttp();
        NewsSummarizer::fake(fn () => [
            'summary' => "  First paragraph.\nSecond paragraph.\r\n\r\n\r\nThird paragraph.  ",
            'vocabulary' => self::VOCABULARY,
        ])->preventStrayPrompts();

        $this->artisan('news:fetch', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(self::SUMMARY, NewsArticle::sole()->summary);
    }

    public function test_it_fails_when_a_feed_cannot_be_read(): void
    {
        $this->fakeHttp(['feeds.example.test/*' => Http::response('', 503)]);
        $this->fakeSummaries();

        $this->artisan('news:fetch')
            ->expectsOutputToContain('Could not read the feed')
            ->assertFailed();

        NewsSummarizer::assertNeverPrompted();
        $this->assertSame(0, NewsArticle::count());
    }

    public function test_it_is_scheduled_daily_at_three_am_madrid_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'news:fetch'));

        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Europe/Madrid', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);

        // 03:00 in Madrid is 01:00 UTC in summer (CEST) and 02:00 UTC in winter (CET).
        $this->assertSame('2026-10-08 01:00:00', $event->nextRunDate('2026-10-07 12:00:00')->setTimezone('UTC')->toDateTimeString());
        $this->assertSame('2026-12-02 02:00:00', $event->nextRunDate('2026-12-01 12:00:00')->setTimezone('UTC')->toDateTimeString());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeHttp(array $overrides = []): void
    {
        Http::fake($overrides + [
            'feeds.example.test/*' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml'))),
            'www.bbc.co.uk/news/articles/*' => Http::response(file_get_contents(base_path('tests/Fixtures/news/article.html'))),
            'www.bbc.co.uk/news/videos/*' => Http::response('<html><body><main><video src="clip.mp4"></video></main></body></html>'),
        ]);
    }

    private function fakeSummaries(): void
    {
        NewsSummarizer::fake(fn () => [
            'summary' => self::SUMMARY,
            'vocabulary' => self::VOCABULARY,
        ])->preventStrayPrompts();
    }
}

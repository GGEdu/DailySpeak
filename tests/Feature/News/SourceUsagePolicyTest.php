<?php

namespace Tests\Feature\News;

use App\Models\NewsSource;
use App\Services\News\FeedItem;
use App\Services\News\HostResolver;
use App\Services\News\SourcePolicyResult;
use App\Services\News\SourceUsagePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

class SourceUsagePolicyTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'https://feeds.example.test/rss.xml';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_a_disallow_for_an_ai_crawler_is_a_warning_that_names_it(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'news.example.test/robots.txt' => Http::response("User-agent: GPTBot\nDisallow: /\n"),
        ]);

        $result = $this->assess([$this->article('https://news.example.test/world/a')]);

        $this->assertSame(SourcePolicyResult::WARNING, $result->status);
        $this->assertSame('robots.txt on news.example.test blocks GPTBot from the pages checked.', $result->note);
        $this->assertTrue($result->needsAttention());
    }

    public function test_a_comment_that_prohibits_llm_use_is_a_warning_with_the_comment_quoted(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("# Content may not be used to train LLMs without permission.\nUser-agent: *\nAllow: /\n"),
        ]);

        $result = $this->assess([]);

        $this->assertSame(SourcePolicyResult::WARNING, $result->status);
        $this->assertStringContainsString('robots.txt on feeds.example.test says: “Content may not be used to train LLMs without permission.”', $result->note);
    }

    public function test_a_short_no_llm_comment_is_a_warning(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("# no LLM\nUser-agent: *\nAllow: /\n"),
        ]);

        $result = $this->assess([]);

        $this->assertSame(SourcePolicyResult::WARNING, $result->status);
        $this->assertStringContainsString('says: “no LLM”', $result->note);
    }

    public function test_a_comment_about_ai_without_a_prohibition_is_not_a_warning(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("# AI crawlers are welcome to index our pages.\nUser-agent: *\nAllow: /\n"),
        ]);

        $this->assertSame(SourcePolicyResult::OK, $this->assess([])->status);
    }

    public function test_a_wildcard_that_allows_everything_is_ok(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'news.example.test/robots.txt' => Http::response("User-agent: *\nDisallow:\n"),
        ]);

        $result = $this->assess([$this->article('https://news.example.test/world/a')]);

        $this->assertSame(SourcePolicyResult::OK, $result->status);
        $this->assertSame('No AI or LLM restriction found in robots.txt for the pages checked.', $result->note);
    }

    public function test_a_wildcard_that_blocks_the_articles_blocks_the_ai_crawlers_without_their_own_rules(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nDisallow: /\n"),
        ]);

        $result = $this->assess([]);

        $this->assertSame(SourcePolicyResult::WARNING, $result->status);
        $this->assertStringContainsString('blocks all crawlers without their own rules (User-agent: *) from the pages checked, AI crawlers included.', $result->note);
    }

    public function test_a_crawler_with_its_own_allow_is_not_blocked_by_the_wildcard(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /\n"),
        ]);

        $result = $this->assess([]);

        $this->assertSame(SourcePolicyResult::WARNING, $result->status);
        $this->assertStringNotContainsString('GPTBot', $result->note);
        $this->assertStringContainsString('AI crawlers included', $result->note);
    }

    public function test_a_robots_file_that_cannot_be_read_is_unknown_with_the_reason(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response('Service unavailable', 503),
        ]);

        $result = $this->assess([]);

        $this->assertSame(SourcePolicyResult::UNKNOWN, $result->status);
        $this->assertSame('robots.txt on feeds.example.test could not be read (HTTP 503), so it was not checked.', $result->note);
        $this->assertTrue($result->needsAttention());
    }

    public function test_a_connection_failure_is_unknown_not_an_error(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::failedConnection('Could not resolve host'),
        ]);

        $result = $this->assess([]);

        $this->assertSame(SourcePolicyResult::UNKNOWN, $result->status);
        $this->assertSame('robots.txt on feeds.example.test could not be read, so it was not checked.', $result->note);
    }

    public function test_a_missing_robots_file_restricts_nothing(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response('', 404),
        ]);

        $this->assertSame(SourcePolicyResult::OK, $this->assess([])->status);
    }

    public function test_a_robots_file_with_more_rules_than_can_be_checked_is_unknown(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\n".str_repeat("Disallow: /private\n", 2001)),
        ]);

        $result = $this->assess([]);

        $this->assertSame(SourcePolicyResult::UNKNOWN, $result->status);
        $this->assertSame('robots.txt on feeds.example.test has more rules than can be checked, so it was not checked.', $result->note);
    }

    public function test_an_error_page_served_as_robots_txt_is_unknown(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response('<html><body>Maintenance</body></html>'),
        ]);

        $result = $this->assess([]);

        $this->assertSame(SourcePolicyResult::UNKNOWN, $result->status);
        $this->assertSame('robots.txt on feeds.example.test is not a robots file, so it was not checked.', $result->note);
    }

    public function test_an_article_host_on_a_private_address_is_unknown_and_never_requested(): void
    {
        $this->app->instance(HostResolver::class, new FakeHostResolver(['internal.example.test' => ['10.0.0.5']]));
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
        ]);

        $result = $this->assess([$this->article('https://internal.example.test/news/a')]);

        $this->assertSame(SourcePolicyResult::UNKNOWN, $result->status);
        $this->assertSame('robots.txt on internal.example.test could not be read, so it was not checked.', $result->note);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'internal.example.test'));
    }

    public function test_the_robots_file_of_the_feed_and_of_the_articles_are_both_read_when_they_differ(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'news.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
        ]);

        $this->assess([$this->article('https://news.example.test/world/a'), $this->article('https://news.example.test/world/b')]);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://news.example.test/robots.txt');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://feeds.example.test/robots.txt');
    }

    public function test_the_known_publisher_conditions_are_warnings_on_their_domains(): void
    {
        $cases = [
            'https://theconversation.com/climate-1' => 'CC BY-ND',
            'https://www.bbc.co.uk/news/articles/abc' => 'personal, non-commercial',
            'https://www.theguardian.com/world/2026/oct/21/x' => 'The Guardian prohibits',
            'https://www.aljazeera.com/news/2026/10/21/x' => 'Al Jazeera prohibits',
        ];

        foreach ($cases as $articleUrl => $expected) {
            Http::fake([
                '*/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            ]);

            $result = $this->assess([$this->article($articleUrl)]);

            $this->assertSame(SourcePolicyResult::WARNING, $result->status, $articleUrl);
            $this->assertStringContainsString($expected, $result->note, $articleUrl);
        }
    }

    public function test_a_lookalike_domain_is_not_a_known_publisher(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'notbbc.co.uk/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
        ]);

        $result = $this->assess([$this->article('https://notbbc.co.uk/news/a')]);

        $this->assertSame(SourcePolicyResult::OK, $result->status);
    }

    public function test_check_reads_the_feed_and_saves_the_result_on_the_source(): void
    {
        $source = NewsSource::factory()->create(['feed_url' => self::FEED]);
        Http::fake([
            'feeds.example.test/rss.xml' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml'))),
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'www.bbc.co.uk/robots.txt' => Http::response("User-agent: GPTBot\nDisallow: /\n"),
        ]);

        $result = app(SourceUsagePolicy::class)->check($source);

        $fresh = $source->fresh();
        $this->assertSame(SourcePolicyResult::WARNING, $fresh->policy_status);
        $this->assertSame($result->note, $fresh->policy_note);
        $this->assertStringContainsString('GPTBot', $fresh->policy_note);
        // The timestamp column keeps whole seconds.
        $this->assertSame($result->checkedAt->toDateTimeString(), $fresh->policy_checked_at->toDateTimeString());
    }

    public function test_check_records_an_unreadable_feed_as_unknown(): void
    {
        $source = NewsSource::factory()->create(['feed_url' => self::FEED]);
        Http::fake([
            'feeds.example.test/rss.xml' => Http::response('', 500),
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
        ]);

        $result = app(SourceUsagePolicy::class)->check($source);

        $this->assertSame(SourcePolicyResult::UNKNOWN, $result->status);
        $this->assertSame('The feed could not be read, so its article pages were not checked.', $result->note);
        $this->assertSame(SourcePolicyResult::UNKNOWN, $source->fresh()->policy_status);
    }

    /**
     * @param  list<FeedItem>  $articles
     */
    private function assess(array $articles): SourcePolicyResult
    {
        return app(SourceUsagePolicy::class)->assess(self::FEED, collect($articles));
    }

    private function article(string $url): FeedItem
    {
        return new FeedItem(title: 'Article', url: $url, publishedAt: CarbonImmutable::now());
    }
}

<?php

namespace Tests\Feature\Console;

use App\Ai\Agents\NewsSummarizer;
use App\Models\NewsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckNewsSourcePoliciesTest extends TestCase
{
    use RefreshDatabase;

    private const FEED_WITH_ONE_ARTICLE = '<?xml version="1.0"?><rss version="2.0"><channel><title>Test</title>'
        .'<item><title>A story</title><link>https://news.example.test/a</link></item></channel></rss>';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_every_source_is_checked_and_no_llm_is_called(): void
    {
        NewsSource::query()->delete();
        NewsSource::factory()->create(['name' => 'Calm News', 'feed_url' => 'https://calm.example.test/rss.xml']);
        NewsSource::factory()->create(['name' => 'Blocked News', 'feed_url' => 'https://blocked.example.test/rss.xml']);
        Http::fake([
            'calm.example.test/rss.xml' => Http::response(self::FEED_WITH_ONE_ARTICLE),
            'calm.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'blocked.example.test/rss.xml' => Http::response(self::FEED_WITH_ONE_ARTICLE),
            'blocked.example.test/robots.txt' => Http::response("User-agent: *\nDisallow: /\n"),
            'news.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
        ]);
        NewsSummarizer::fake()->preventStrayPrompts();

        $this->artisan('news:check-policies')
            ->expectsOutputToContain('Blocked News: warning.')
            ->expectsOutputToContain('Calm News: ok. No AI or LLM restriction found in robots.txt for the pages checked.')
            ->assertSuccessful();

        NewsSummarizer::assertNeverPrompted();
        $this->assertSame('ok', NewsSource::where('name', 'Calm News')->value('policy_status'));
        $this->assertSame('warning', NewsSource::where('name', 'Blocked News')->value('policy_status'));
        $this->assertNotNull(NewsSource::where('name', 'Blocked News')->value('policy_checked_at'));
    }
}

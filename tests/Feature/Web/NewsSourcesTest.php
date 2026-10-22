<?php

namespace Tests\Feature\Web;

use App\Jobs\CheckNewsSourcePolicy;
use App\Jobs\FetchNewsSource;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use App\Models\User;
use App\Services\News\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

class NewsSourcesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_bbc_feed_is_the_default_source(): void
    {
        $source = NewsSource::sole();

        $this->assertSame('https://feeds.bbci.co.uk/news/world/rss.xml', $source->feed_url);
        $this->assertTrue($source->is_active);
    }

    public function test_admins_see_the_sources_with_their_article_counts(): void
    {
        NewsArticle::factory(2)->for(NewsSource::sole())->create();

        $this->actingAs($this->admin)
            ->get('/admin/sources')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Sources')
                ->has('sources', 1)
                ->where('sources.0.name', 'BBC News – World')
                ->where('sources.0.articles_count', 2)
                ->where('sources.0.is_active', true));
    }

    public function test_admins_can_add_a_readable_feed(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'www.bbc.co.uk/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'feeds.example.test/*' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml'))),
        ]);

        // The articles are BBC pages, so the known BBC licence is a heads-up even though robots.txt allows everything.
        $this->actingAs($this->admin)
            ->from('/admin/sources')
            ->post('/admin/sources', ['name' => 'Example – World', 'feed_url' => 'https://feeds.example.test/rss.xml', 'category' => 'world'])
            ->assertRedirect('/admin/sources')
            ->assertSessionHas('warning', 'Example – World added. Heads-up: The BBC licenses its content for personal, non-commercial use only.')
            ->assertSessionMissing('status');

        $this->assertDatabaseHas('news_sources', [
            'feed_url' => 'https://feeds.example.test/rss.xml',
            'category' => 'world',
            'is_active' => true,
        ]);
    }

    public function test_adding_a_source_queues_its_terms_check_and_reads_no_robots_file_in_the_request(): void
    {
        Queue::fake();
        Http::fake(['feeds.example.test/*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><item><title>Plain</title><link>https://news.example.test/a</link></item></channel></rss>')]);

        $this->actingAs($this->admin)
            ->post('/admin/sources', ['name' => 'Example – World', 'feed_url' => 'https://feeds.example.test/rss.xml', 'category' => 'world'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Example – World added. It will be read in the next daily run; its usage terms are being checked.')
            ->assertSessionMissing('warning');

        $source = NewsSource::where('feed_url', 'https://feeds.example.test/rss.xml')->sole();
        $this->assertSame('checking', $source->policy_status);
        Queue::assertPushedOn('default', CheckNewsSourcePolicy::class, fn (CheckNewsSourcePolicy $job) => $job->sourceId === $source->id);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/robots.txt'));
    }

    public function test_a_new_source_needs_a_category_from_the_list(): void
    {
        Http::fake(['feeds.example.test/*' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml')))]);
        $data = ['name' => 'Example', 'feed_url' => 'https://feeds.example.test/rss.xml'];

        $this->actingAs($this->admin)->post('/admin/sources', $data)->assertSessionHasErrors('category');
        $this->actingAs($this->admin)->post('/admin/sources', [...$data, 'category' => 'gardening'])->assertSessionHasErrors('category');

        $this->assertDatabaseMissing('news_sources', ['feed_url' => 'https://feeds.example.test/rss.xml']);
    }

    public function test_the_sources_page_offers_the_categories_and_each_source_category(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/sources')
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories', 12)
                ->where('categories.0', ['key' => 'world', 'label' => 'World'])
                ->where('sources.0.category', 'world'));
    }

    public function test_admins_can_change_the_category_of_a_source(): void
    {
        $source = NewsSource::sole();

        $this->actingAs($this->admin)->patch("/admin/sources/{$source->id}", ['category' => 'culture'])->assertRedirect();

        $this->assertSame('culture', $source->fresh()->category);
        $this->assertTrue($source->fresh()->is_active);
    }

    public function test_a_changed_category_must_be_on_the_list(): void
    {
        $source = NewsSource::sole();

        $this->actingAs($this->admin)->patch("/admin/sources/{$source->id}", ['category' => 'gardening'])->assertSessionHasErrors('category');
        $this->actingAs($this->admin)->patch("/admin/sources/{$source->id}", ['category' => ''])->assertSessionHasErrors('category');

        $this->assertSame('world', $source->fresh()->category);
    }

    public function test_adding_a_feed_that_is_down_asks_once_and_answers_at_once(): void
    {
        // The admin is waiting on the form and can try again: retrying inside the request only makes it slow.
        Http::fake(['down.example.test/*' => Http::response('', 503)]);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/sources', ['name' => 'Down', 'feed_url' => 'https://down.example.test/rss.xml', 'category' => 'world'])
            ->assertSessionHasErrors(['feed_url' => 'No RSS feed could be read at this URL.']);

        Http::assertSentCount(1);
    }

    public function test_feeds_that_cannot_be_read_are_rejected(): void
    {
        Http::fake([
            'down.example.test/*' => Http::response('', 500),
            'html.example.test/*' => Http::response('<html><body>Not a feed</body></html>'),
            'empty.example.test/*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><title>Empty</title></channel></rss>'),
        ]);

        $cases = [
            'not-a-url' => 'The feed url field must be a valid URL.',
            'ftp://example.test/rss.xml' => 'The feed url field must be a valid URL.',
            'https://feeds.bbci.co.uk/news/world/rss.xml' => 'The feed url has already been taken.',
            'https://down.example.test/rss.xml' => 'No RSS feed could be read at this URL.',
            'https://html.example.test/rss.xml' => 'No RSS feed could be read at this URL.',
            'https://empty.example.test/rss.xml' => 'The feed does not contain any articles.',
        ];

        foreach ($cases as $url => $message) {
            $this->actingAs($this->admin)
                ->post('/admin/sources', ['name' => 'Test', 'feed_url' => $url])
                ->assertSessionHasErrors(['feed_url' => $message]);
        }

        $this->assertSame(1, NewsSource::count());
    }

    public function test_feeds_on_private_or_reserved_addresses_are_refused_before_any_request(): void
    {
        $this->app->instance(HostResolver::class, new FakeHostResolver(['internal.example.test' => ['10.0.0.5']]));

        $cases = [
            'https://internal.example.test/rss.xml',
            'https://127.0.0.1/rss.xml',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::1]/rss.xml',
        ];

        // Same message as an unreachable host (see the test above): the wording must not reveal the internal network.
        foreach ($cases as $url) {
            $this->actingAs($this->admin)
                ->post('/admin/sources', ['name' => 'Internal', 'feed_url' => $url])
                ->assertSessionHasErrors(['feed_url' => 'No RSS feed could be read at this URL.']);
        }

        Http::assertNothingSent();
        $this->assertSame(1, NewsSource::count());
    }

    public function test_admins_can_pause_fetch_and_delete_a_source(): void
    {
        Queue::fake();
        $source = NewsSource::sole();
        $article = NewsArticle::factory()->for($source)->create();

        $this->actingAs($this->admin)->patch("/admin/sources/{$source->id}", ['is_active' => false])->assertRedirect();
        $this->assertFalse($source->fresh()->is_active);

        $this->actingAs($this->admin)->post("/admin/sources/{$source->id}/fetch")->assertSessionHas('status');
        Queue::assertPushedOn('news', FetchNewsSource::class, fn (FetchNewsSource $job) => $job->sourceId === $source->id);

        $this->actingAs($this->admin)->delete("/admin/sources/{$source->id}")->assertRedirect();
        $this->assertModelMissing($source);
        // Articles stay in the feed.
        $this->assertNull($article->fresh()->news_source_id);
    }

    public function test_fetch_now_says_when_a_fetch_is_already_queued_or_running(): void
    {
        // The job is unique per source: a second dispatch is dropped, and the admin must be told that.
        Queue::fake();
        $source = NewsSource::sole();

        $this->actingAs($this->admin)->post("/admin/sources/{$source->id}/fetch")
            ->assertSessionHas('status', fn (string $status) => str_starts_with($status, 'Fetching BBC News – World… '));
        $this->actingAs($this->admin)->post("/admin/sources/{$source->id}/fetch")
            ->assertSessionHas('status', 'BBC News – World is already being fetched. Its new articles will appear in the feed as they are summarised.');

        Queue::assertPushedTimes(FetchNewsSource::class, 1);
        // The lock travels with the job, so the worker releases it when the fetch ends.
        Queue::assertPushedOn('news', FetchNewsSource::class, fn (FetchNewsSource $job) => is_string($job->uniqueLockOwner) && $job->uniqueLockOwner !== '');
    }

    public function test_regular_users_cannot_manage_sources(): void
    {
        $user = User::factory()->create();
        $source = NewsSource::sole();

        $this->actingAs($user)->get('/admin/sources')->assertForbidden();
        $this->actingAs($user)->post('/admin/sources', ['name' => 'X', 'feed_url' => 'https://x.test/rss'])->assertForbidden();
        $this->actingAs($user)->patch("/admin/sources/{$source->id}", ['is_active' => false])->assertForbidden();
        $this->actingAs($user)->post("/admin/sources/{$source->id}/fetch")->assertForbidden();
        $this->actingAs($user)->delete("/admin/sources/{$source->id}")->assertForbidden();

        $this->assertTrue($source->fresh()->is_active);
    }

    public function test_a_source_whose_robots_txt_blocks_an_ai_crawler_is_saved_with_a_warning_for_admins(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'www.bbc.co.uk/robots.txt' => Http::response("User-agent: GPTBot\nDisallow: /news/articles\n"),
            'feeds.example.test/rss.xml' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml'))),
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/sources', ['name' => 'Example – World', 'feed_url' => 'https://feeds.example.test/rss.xml', 'category' => 'world'])
            ->assertRedirect()
            ->assertSessionHas('warning', 'Example – World added. Heads-up: The BBC licenses its content for personal, non-commercial use only.');

        // A warning never stops the source: it is saved and active, and the robots.txt finding is kept for admins.
        $this->assertDatabaseHas('news_sources', ['feed_url' => 'https://feeds.example.test/rss.xml', 'is_active' => true, 'policy_status' => 'warning']);
        $this->assertSame(
            'robots.txt on www.bbc.co.uk blocks GPTBot from the pages checked. The BBC licenses its content for personal, non-commercial use only.',
            NewsSource::where('feed_url', 'https://feeds.example.test/rss.xml')->value('policy_note'),
        );
        $this->assertNotNull(NewsSource::where('feed_url', 'https://feeds.example.test/rss.xml')->value('policy_checked_at'));
    }

    public function test_an_unreadable_robots_file_never_blocks_the_add(): void
    {
        Http::fake([
            'feeds.example.test/robots.txt' => Http::response('', 503),
            'www.bbc.co.uk/robots.txt' => Http::response('', 503),
            'feeds.example.test/rss.xml' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml'))),
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/sources', ['name' => 'Example – World', 'feed_url' => 'https://feeds.example.test/rss.xml', 'category' => 'world'])
            ->assertRedirect()
            ->assertSessionHas('warning', 'Example – World added. Heads-up: The BBC licenses its content for personal, non-commercial use only.');

        $this->assertDatabaseHas('news_sources', ['feed_url' => 'https://feeds.example.test/rss.xml', 'is_active' => true, 'policy_status' => 'warning']);
        $this->assertStringContainsString(
            'robots.txt on feeds.example.test could not be read (HTTP 503), so it was not checked.',
            NewsSource::where('feed_url', 'https://feeds.example.test/rss.xml')->value('policy_note'),
        );
    }

    public function test_admins_can_check_a_sources_usage_terms_again(): void
    {
        Http::fake([
            'feeds.bbci.co.uk/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'www.bbc.co.uk/robots.txt' => Http::response("User-agent: ClaudeBot\nDisallow: /\n"),
            'feeds.bbci.co.uk/news/world/rss.xml' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml'))),
        ]);
        $source = NewsSource::sole();

        $this->actingAs($this->admin)
            ->patch("/admin/sources/{$source->id}", ['check_policy' => true])
            ->assertRedirect()
            ->assertSessionHas('status', 'BBC News – World is being checked again. Its result appears in its row when the check ends.');

        $this->assertSame('warning', $source->fresh()->policy_status);
        $this->assertStringContainsString('ClaudeBot', $source->fresh()->policy_note);
        $this->assertTrue($source->fresh()->is_active);
        $this->assertSame('world', $source->fresh()->category);
    }

    public function test_the_sources_page_shows_each_sources_usage_result_to_admins(): void
    {
        $source = NewsSource::sole();
        $source->forceFill(['policy_status' => 'warning', 'policy_note' => 'Blocks GPTBot.', 'policy_checked_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get('/admin/sources')
            ->assertInertia(fn (Assert $page) => $page
                ->where('sources.0.policy_status', 'warning')
                ->where('sources.0.policy_note', 'Blocks GPTBot.')
                ->has('sources.0.policy_checked_at'));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin/sources')->assertRedirect('/login');
    }
}

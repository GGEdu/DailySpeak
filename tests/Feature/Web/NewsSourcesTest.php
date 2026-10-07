<?php

namespace Tests\Feature\Web;

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
        Http::fake(['feeds.example.test/*' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml')))]);

        $this->actingAs($this->admin)
            ->from('/admin/sources')
            ->post('/admin/sources', ['name' => 'Example – World', 'feed_url' => 'https://feeds.example.test/rss.xml'])
            ->assertRedirect('/admin/sources')
            ->assertSessionHas('status', 'Example – World added. It will be read in the next daily run.');

        $this->assertDatabaseHas('news_sources', ['feed_url' => 'https://feeds.example.test/rss.xml', 'is_active' => true]);
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

        foreach ($cases as $url) {
            $this->actingAs($this->admin)
                ->post('/admin/sources', ['name' => 'Internal', 'feed_url' => $url])
                ->assertSessionHasErrors(['feed_url' => 'This URL points to a private or reserved network address.']);
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

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin/sources')->assertRedirect('/login');
    }
}

<?php

namespace Tests\Feature\Database;

use App\Models\NewsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignNewsSourceCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_08_100001_assign_categories_to_news_sources.php';

    /**
     * The sources in production and the category each one belongs to.
     */
    private const CATEGORIES_BY_FEED = [
        'https://feeds.bbci.co.uk/news/world/rss.xml' => 'world',
        'https://www.aljazeera.com/xml/rss/all.xml' => 'world',
        'https://feeds.bbci.co.uk/news/business/rss.xml' => 'business',
        'https://feeds.bbci.co.uk/news/technology/rss.xml' => 'technology',
        'https://feeds.bbci.co.uk/news/science_and_environment/rss.xml' => 'science',
        'https://www.nasa.gov/news-release/feed/' => 'science',
        'https://feeds.bbci.co.uk/news/health/rss.xml' => 'health',
        'https://feeds.bbci.co.uk/news/education/rss.xml' => 'education',
        'https://www.theguardian.com/environment/rss' => 'environment',
        'https://www.theguardian.com/society/rss' => 'society',
        'https://www.theguardian.com/culture/rss' => 'culture',
        'https://www.smithsonianmag.com/rss/latest_articles/' => 'culture',
        'https://www.theguardian.com/sport/rss' => 'sport',
        'https://www.theguardian.com/lifeandstyle/rss' => 'lifestyle',
        'https://www.theguardian.com/travel/rss' => 'lifestyle',
        'https://aeon.co/feed.rss' => 'ideas',
    ];

    public function test_the_bbc_world_feed_seeded_by_the_first_migration_is_categorised(): void
    {
        $this->assertSame('world', NewsSource::sole()->category);
    }

    public function test_every_known_feed_gets_the_category_of_its_url(): void
    {
        NewsSource::query()->delete();
        foreach (array_keys(self::CATEGORIES_BY_FEED) as $url) {
            NewsSource::factory()->create(['feed_url' => $url, 'category' => null]);
        }

        $this->runMigration();

        foreach (self::CATEGORIES_BY_FEED as $url => $category) {
            $this->assertSame($category, NewsSource::where('feed_url', $url)->value('category'), $url);
        }
    }

    public function test_a_category_already_chosen_by_an_admin_is_not_overwritten(): void
    {
        NewsSource::query()->delete();
        $chosen = NewsSource::factory()->create([
            'feed_url' => 'https://www.theguardian.com/sport/rss',
            'category' => 'lifestyle',
        ]);

        $this->runMigration();

        $this->assertSame('lifestyle', $chosen->fresh()->category);
    }

    public function test_feeds_outside_the_list_are_left_uncategorised(): void
    {
        NewsSource::query()->delete();
        $unknown = NewsSource::factory()->create(['feed_url' => 'https://example.test/rss.xml', 'category' => null]);

        $this->runMigration();

        $this->assertNull($unknown->fresh()->category);
    }

    public function test_a_known_feed_that_does_not_exist_is_skipped_without_creating_it(): void
    {
        NewsSource::query()->delete();

        $this->runMigration();

        $this->assertSame(0, NewsSource::count());
    }

    private function runMigration(): void
    {
        (require database_path(self::MIGRATION))->up();
    }
}

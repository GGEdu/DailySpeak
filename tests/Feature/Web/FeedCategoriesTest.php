<?php

namespace Tests\Feature\Web;

use App\Models\NewsArticle;
use App\Models\NewsSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeedCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feed_only_shows_articles_of_the_chosen_category(): void
    {
        $science = NewsArticle::factory()->for(NewsSource::factory()->create(['category' => 'science']))->create();
        NewsArticle::factory()->for(NewsSource::factory()->create(['category' => 'sport']))->create();

        $this->actingAs(User::factory()->create())
            ->get('/feed?category=science')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('category', 'science')
                ->has('articles', 1)
                ->where('articles.0.id', $science->id));
    }

    public function test_a_category_shows_its_own_twenty_latest_articles(): void
    {
        // Newer stories in other categories must not push the category's own stories out of the feed.
        NewsArticle::factory(25)->for(NewsSource::factory()->create(['category' => 'sport']))->create(['published_at' => now()]);
        NewsArticle::factory(22)->for(NewsSource::factory()->create(['category' => 'science']))->create(['published_at' => now()->subDays(3)]);

        $this->actingAs(User::factory()->create())
            ->get('/feed?category=science')
            ->assertInertia(fn (Assert $page) => $page
                ->has('articles', 20)
                ->where('articles.0.category', 'science')
                ->where('articles.19.category', 'science'));
    }

    public function test_an_unknown_category_is_ignored(): void
    {
        NewsArticle::factory(2)->for(NewsSource::factory()->create(['category' => 'science']))->create();

        $this->actingAs(User::factory()->create())
            ->get('/feed?category=gardening')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('category', null)
                ->has('articles', 2));
    }

    public function test_a_known_category_without_articles_is_ignored(): void
    {
        NewsArticle::factory(2)->for(NewsSource::factory()->create(['category' => 'science']))->create();

        $this->actingAs(User::factory()->create())
            ->get('/feed?category=health')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('category', null)
                ->has('articles', 2));
    }

    public function test_only_categories_with_articles_are_offered(): void
    {
        NewsArticle::factory()->for(NewsSource::factory()->create(['category' => 'sport']))->create();
        NewsArticle::factory()->for(NewsSource::factory()->create(['category' => 'science']))->create();
        NewsSource::factory()->create(['category' => 'health']); // no articles yet
        NewsSource::factory()->create(['category' => null]); // uncategorised
        NewsArticle::factory()->create(); // harvested ad hoc, without a source

        $this->actingAs(User::factory()->create())
            ->get('/feed')
            ->assertInertia(fn (Assert $page) => $page->where('categories', [
                ['key' => 'science', 'label' => 'Science'],
                ['key' => 'sport', 'label' => 'Sport'],
            ]));
    }

    public function test_a_search_ignores_the_category_and_covers_every_category(): void
    {
        NewsArticle::factory()->for(NewsSource::factory()->create(['category' => 'science']))->create(['title' => 'Madrid air quality']);
        NewsArticle::factory()->for(NewsSource::factory()->create(['category' => 'sport']))->create(['title' => 'Madrid derby']);

        $this->actingAs(User::factory()->create())
            ->get('/feed?q=madrid&category=science')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('search', 'madrid')
                ->where('category', null)
                ->has('articles', 2));
    }

    public function test_each_article_carries_the_category_of_its_source(): void
    {
        $article = NewsArticle::factory()
            ->for(NewsSource::factory()->create(['category' => 'technology']))
            ->create(['published_at' => now()]);
        $adHoc = NewsArticle::factory()->create(['published_at' => now()->subDay()]);

        $this->actingAs(User::factory()->create())
            ->get('/feed')
            ->assertInertia(fn (Assert $page) => $page
                ->where('articles.0.id', $article->id)
                ->where('articles.0.category', 'technology')
                ->where('articles.0.category_label', 'Technology')
                ->where('articles.1.id', $adHoc->id)
                ->where('articles.1.category', null)
                ->where('articles.1.category_label', null));
    }
}

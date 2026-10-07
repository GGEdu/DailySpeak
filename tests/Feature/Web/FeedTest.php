<?php

namespace Tests\Feature\Web;

use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_latest_articles_first(): void
    {
        $older = NewsArticle::factory()->create(['published_at' => now()->subDay()]);
        $newer = NewsArticle::factory()->create([
            'published_at' => now(),
            'source_url' => 'https://www.bbc.co.uk/news/articles/abc',
            'key_vocabulary' => ['overhaul', 'scrutiny'],
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/feed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Feed')
                ->where('search', '')
                ->has('articles', 2)
                ->has('articles.0', fn (Assert $article) => $article
                    ->where('id', $newer->id)
                    ->where('title', $newer->title)
                    ->where('summary', $newer->summary)
                    ->where('key_vocabulary', ['overhaul', 'scrutiny'])
                    ->where('source', 'bbc.co.uk')
                    ->etc())
                ->where('articles.1.id', $older->id));
    }

    public function test_it_shows_at_most_twenty_articles(): void
    {
        NewsArticle::factory(25)->create();

        $this->actingAs(User::factory()->create())
            ->get('/feed')
            ->assertInertia(fn (Assert $page) => $page->has('articles', 20));
    }

    public function test_it_points_to_the_users_active_and_finished_debates(): void
    {
        $user = User::factory()->create();
        [$debated, $finished, $other] = NewsArticle::factory(3)->create();
        $active = Debate::factory()->for($user)->for($debated)->create();
        Debate::factory()->completed()->for($user)->for($finished)->create();
        $finishedDebate = Debate::factory()->completed()->for($user)->for($finished)->create(); // the latest one wins
        Debate::factory()->for($other)->create(); // another user's

        $this->actingAs($user)
            ->get('/feed')
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeDebates', [$debated->id => $active->id])
                ->where('finishedDebates', [$finished->id => $finishedDebate->id]));
    }

    public function test_stories_can_be_searched_beyond_the_latest_ones(): void
    {
        NewsArticle::factory(20)->create(['title' => 'Unrelated story', 'summary' => 'Nothing to see.', 'published_at' => now()]);
        $older = NewsArticle::factory()->create(['title' => 'Madrid bans cars from its centre', 'published_at' => now()->subDays(30)]);
        $newer = NewsArticle::factory()->create(['title' => 'Bus fares scrapped in Madrid', 'published_at' => now()->subDays(2)]);

        $this->actingAs(User::factory()->create())
            ->get('/feed?q=madrid')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('search', 'madrid')
                ->has('articles', 2)
                ->where('articles.0.id', $newer->id)
                ->where('articles.1.id', $older->id));
    }

    public function test_the_search_covers_summaries_and_key_vocabulary(): void
    {
        $bySummary = NewsArticle::factory()->create(['title' => 'Council vote', 'summary' => 'Councillors approved a congestion charge.']);
        $byWord = NewsArticle::factory()->create(['title' => 'Budget talks', 'summary' => 'Talks went on.', 'key_vocabulary' => ['austerity', 'deficit']]);
        NewsArticle::factory()->create(['title' => 'Something else', 'summary' => 'Unrelated.', 'key_vocabulary' => ['other']]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/feed?q=congestion')
            ->assertInertia(fn (Assert $page) => $page->has('articles', 1)->where('articles.0.id', $bySummary->id));
        $this->actingAs($user)->get('/feed?q=austerity')
            ->assertInertia(fn (Assert $page) => $page->has('articles', 1)->where('articles.0.id', $byWord->id));
        $this->actingAs($user)->get('/feed?q=nonexistent')
            ->assertInertia(fn (Assert $page) => $page->where('search', 'nonexistent')->has('articles', 0));
    }

    public function test_a_blank_search_shows_the_latest_news(): void
    {
        NewsArticle::factory(3)->create();

        $this->actingAs(User::factory()->create())
            ->get('/feed?q=%20%20')
            ->assertInertia(fn (Assert $page) => $page->where('search', '')->has('articles', 3));
    }

    public function test_the_search_falls_back_to_the_database_when_the_engine_is_down(): void
    {
        $match = NewsArticle::factory()->create(['title' => 'Heatwave hits Europe']);
        NewsArticle::factory()->create(['title' => 'Election results', 'summary' => 'Votes were counted.']);
        Exceptions::fake();
        config(['scout.driver' => 'meilisearch', 'scout.meilisearch.host' => 'http://127.0.0.1:1']);

        $this->actingAs(User::factory()->create())
            ->get('/feed?q=HEATWAVE')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('articles', 1)->where('articles.0.id', $match->id));

        Exceptions::assertReportedCount(1);
    }
}

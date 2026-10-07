<?php

namespace Tests\Feature\Web;

use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_it_points_to_the_users_active_debates(): void
    {
        $user = User::factory()->create();
        [$debated, $finished, $other] = NewsArticle::factory(3)->create();
        $active = Debate::factory()->for($user)->for($debated)->create();
        Debate::factory()->completed()->for($user)->for($finished)->create();
        Debate::factory()->for($other)->create(); // another user's

        $this->actingAs($user)
            ->get('/feed')
            ->assertInertia(fn (Assert $page) => $page->where('activeDebates', [$debated->id => $active->id]));
    }
}

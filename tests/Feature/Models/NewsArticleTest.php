<?php

namespace Tests\Feature\Models;

use App\Models\Debate;
use App\Models\NewsArticle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Jobs\MakeSearchable;
use Tests\TestCase;

class NewsArticleTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_vocabulary_round_trips_as_array(): void
    {
        $article = NewsArticle::factory()->create([
            'key_vocabulary' => ['unprecedented', 'scrutiny', 'bolster', 'contentious', 'ramification'],
            'published_at' => '2026-10-07 03:00:00',
        ]);

        $fresh = $article->fresh();

        $this->assertSame(['unprecedented', 'scrutiny', 'bolster', 'contentious', 'ramification'], $fresh->key_vocabulary);
        $this->assertInstanceOf(Carbon::class, $fresh->published_at);
        $this->assertSame('2026-10-07 03:00:00', $fresh->published_at->toDateTimeString());
    }

    public function test_article_has_many_debates(): void
    {
        $article = NewsArticle::factory()->create();
        Debate::factory(2)->for($article)->create();

        $this->assertCount(2, $article->debates);
    }

    public function test_source_url_is_unique(): void
    {
        NewsArticle::factory()->create(['source_url' => 'https://www.bbc.co.uk/news/articles/abc']);

        $this->expectException(UniqueConstraintViolationException::class);

        NewsArticle::factory()->create(['source_url' => 'https://www.bbc.co.uk/news/articles/abc']);
    }

    public function test_json_columns_are_jsonb_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('jsonb is a PostgreSQL column type.');
        }

        $this->assertSame('jsonb', Schema::getColumnType('news_articles', 'key_vocabulary'));
        $this->assertSame('jsonb', Schema::getColumnType('debates', 'ai_feedback'));
    }

    public function test_articles_are_indexed_for_search_in_the_background(): void
    {
        config(['scout.queue' => true]); // as outside tests (config/scout.php)
        Queue::fake();

        $article = NewsArticle::factory()->create([
            'title' => 'Bus fares scrapped',
            'summary' => 'The council voted.',
            'key_vocabulary' => ['scrap', 'fare'],
            'published_at' => '2026-10-07 03:00:00',
        ]);

        Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job) => $job->models->first()->is($article));
        $this->assertSame([
            'id' => $article->id,
            'title' => 'Bus fares scrapped',
            'summary' => 'The council voted.',
            'key_vocabulary' => ['scrap', 'fare'],
            'published_at' => strtotime('2026-10-07 03:00:00 UTC'),
        ], $article->toSearchableArray());
    }
}

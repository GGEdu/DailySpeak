<?php

namespace Tests\Feature\Models;

use App\Models\Debate;
use App\Models\NewsArticle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
}

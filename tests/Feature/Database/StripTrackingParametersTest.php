<?php

namespace Tests\Feature\Database;

use App\Models\NewsArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StripTrackingParametersTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_21_160000_strip_tracking_parameters_from_article_urls.php';

    public function test_saved_links_lose_their_analytics_parameters_and_fragment(): void
    {
        $articles = collect([
            'https://www.aljazeera.com/news/2026/10/7/story?traffic_source=rss',
            'https://www.bbc.co.uk/news/articles/abc?at_medium=RSS&at_campaign=rss',
            'https://example.com/a?id=7&utm_source=x&fbclid=y#comments',
            'https://example.com/plain',
        ])->map(fn (string $url) => NewsArticle::factory()->create(['source_url' => $url]));

        $this->migrate();

        $this->assertSame(
            [
                'https://www.aljazeera.com/news/2026/10/7/story',
                'https://www.bbc.co.uk/news/articles/abc',
                'https://example.com/a?id=7',
                'https://example.com/plain',
            ],
            $articles->map(fn (NewsArticle $article) => $article->fresh()->source_url)->all(),
        );
    }

    public function test_a_link_whose_clean_form_is_taken_stays_as_it_is(): void
    {
        NewsArticle::factory()->create(['source_url' => 'https://example.com/story']);
        $tracked = NewsArticle::factory()->create(['source_url' => 'https://example.com/story?utm_source=rss']);

        $this->migrate();

        $this->assertSame('https://example.com/story?utm_source=rss', $tracked->fresh()->source_url);
    }

    public function test_every_matching_row_is_cleaned_even_past_the_first_page(): void
    {
        // More rows than a default chunk (1,000): paging by offset while cleaning would skip the last ones.
        $template = NewsArticle::factory()->make()->getAttributes();
        DB::table('news_articles')->insert(array_map(
            fn (int $i) => [...$template, 'source_url' => "https://example.com/{$i}?traffic_source=rss", 'created_at' => now(), 'updated_at' => now()],
            range(1, 1100),
        ));

        $this->migrate();

        $this->assertSame(0, NewsArticle::where('source_url', 'like', '%traffic_source%')->count());
    }

    private function migrate(): void
    {
        (require database_path(self::MIGRATION))->up();
    }
}

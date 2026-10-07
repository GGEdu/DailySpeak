<?php

namespace Tests\Feature\News;

use App\Services\News\ArticleTextExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleTextExtractorTest extends TestCase
{
    public function test_it_extracts_the_article_body(): void
    {
        $text = (new ArticleTextExtractor)->fromHtml(file_get_contents(base_path('tests/Fixtures/news/article.html')));

        $paragraphs = explode("\n\n", $text);

        $this->assertCount(5, $paragraphs);
        $this->assertStringStartsWith('The city council has approved a sweeping overhaul', $paragraphs[0]);
        $this->assertStringContainsString('Mayor José Núñez said the reform', $paragraphs[1]);
        $this->assertStringStartsWith('The first new routes are expected', $paragraphs[4]);

        foreach (['Commuters queue', 'Related:', 'Most read', 'Copyright', 'Skip to content'] as $noise) {
            $this->assertStringNotContainsString($noise, $text);
        }
    }

    public function test_it_falls_back_to_the_main_element_and_truncates_long_text(): void
    {
        config(['news.max_article_characters' => 20]);

        $text = (new ArticleTextExtractor)->fromHtml('<html><body><main><p>'.str_repeat('á', 50).'</p></main></body></html>');

        $this->assertSame(str_repeat('á', 20), $text);
    }

    public function test_it_returns_null_when_there_is_no_text(): void
    {
        $this->assertNull((new ArticleTextExtractor)->fromHtml('<html><body><video src="clip.mp4"></video></body></html>'));
    }

    public function test_it_downloads_the_article_page(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'news.example.test/ok' => Http::response(file_get_contents(base_path('tests/Fixtures/news/article.html'))),
            'news.example.test/missing' => Http::response('Not found', 404),
            'news.example.test/down' => Http::failedConnection(),
        ]);

        $extractor = new ArticleTextExtractor;

        $this->assertStringStartsWith('The city council', $extractor->extract('https://news.example.test/ok'));
        $this->assertNull($extractor->extract('https://news.example.test/missing'));
        $this->assertNull($extractor->extract('https://news.example.test/down'));
    }
}

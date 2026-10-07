<?php

namespace Tests\Feature\News;

use App\Services\News\ArticleTextExtractor;
use App\Services\News\HostResolver;
use App\Services\News\UnsafeUrlException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

class ArticleTextExtractorTest extends TestCase
{
    public function test_it_extracts_the_article_body(): void
    {
        $text = app(ArticleTextExtractor::class)->fromHtml(file_get_contents(base_path('tests/Fixtures/news/article.html')));

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

        $text = app(ArticleTextExtractor::class)->fromHtml('<html><body><main><p>'.str_repeat('á', 50).'</p></main></body></html>');

        $this->assertSame(str_repeat('á', 20), $text);
    }

    public function test_it_returns_null_when_there_is_no_text(): void
    {
        $this->assertNull(app(ArticleTextExtractor::class)->fromHtml('<html><body><video src="clip.mp4"></video></body></html>'));
    }

    public function test_it_downloads_the_article_page(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'news.example.test/ok' => Http::response(file_get_contents(base_path('tests/Fixtures/news/article.html'))),
            'news.example.test/missing' => Http::response('Not found', 404),
            'news.example.test/down' => Http::failedConnection(),
        ]);

        $extractor = app(ArticleTextExtractor::class);

        $this->assertStringStartsWith('The city council', $extractor->extract('https://news.example.test/ok'));
        $this->assertNull($extractor->extract('https://news.example.test/missing'));
        $this->assertNull($extractor->extract('https://news.example.test/down'));
    }

    public function test_download_reports_failures_instead_of_hiding_them(): void
    {
        Http::preventStrayRequests();
        Http::fake(['news.example.test/missing' => Http::response('Not found', 404)]);

        $this->expectException(RequestException::class);

        app(ArticleTextExtractor::class)->download('https://news.example.test/missing');
    }

    public function test_private_article_urls_are_never_downloaded(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new FakeHostResolver(['news.example.test' => ['10.0.0.9']]));

        $this->assertThrows(
            fn () => app(ArticleTextExtractor::class)->download('https://news.example.test/story'),
            UnsafeUrlException::class,
        );
        Http::assertNothingSent();
    }
}

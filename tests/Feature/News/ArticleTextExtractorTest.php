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

        $text = app(ArticleTextExtractor::class)->fromHtml(
            '<html><body><main><p>'.str_repeat('á', 50).'</p><p>Second.</p><p>Third.</p></main></body></html>'
        );

        $this->assertSame(str_repeat('á', 20), $text);
    }

    public function test_it_returns_null_when_there_is_no_text(): void
    {
        $this->assertNull(app(ArticleTextExtractor::class)->fromHtml('<html><body><video src="clip.mp4"></video></body></html>'));
    }

    public function test_a_page_with_fewer_than_three_paragraphs_is_not_an_article(): void
    {
        $html = '<html><body><main><p>'.str_repeat('Long sentence about the news. ', 30).'</p><p>Watch the clip.</p></main></body></html>';

        $this->assertNull(app(ArticleTextExtractor::class)->fromHtml($html));
    }

    public function test_it_downloads_the_article_page(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'news.example.test/ok' => Http::response(file_get_contents(base_path('tests/Fixtures/news/article.html'))),
        ]);

        $extractor = app(ArticleTextExtractor::class);

        $this->assertStringStartsWith('The city council', $extractor->fromHtml($extractor->download('https://news.example.test/ok')));
    }

    public function test_bullet_points_of_the_body_are_kept_but_related_teasers_are_not(): void
    {
        $html = '<html><body><article>'
            .'<p>First paragraph of the story.</p><p>Second paragraph of the story.</p>'
            .'<ul><li><p>Bullet with a paragraph.</p></li><li>Bullet with plain text.</li><li><a href="/x">Related headline</a></li></ul>'
            .'<p>Last paragraph.</p></article></body></html>';

        $text = app(ArticleTextExtractor::class)->fromHtml($html);

        $this->assertStringContainsString('Bullet with a paragraph.', $text);
        $this->assertStringContainsString('Bullet with plain text.', $text);
        $this->assertStringNotContainsString('Related headline', $text);
    }

    public function test_the_article_with_the_most_text_is_used_not_the_first_one(): void
    {
        // A live page has one <article> per post; the first one is a short intro.
        $html = '<html><body>'
            .'<article><p>Live post one, a short intro.</p></article>'
            .'<article><p>'.str_repeat('The real report continues here. ', 10).'</p><p>More report text.</p><p>Even more report text.</p></article>'
            .'</body></html>';

        $this->assertStringStartsWith('The real report continues here.', app(ArticleTextExtractor::class)->fromHtml($html));
    }

    public function test_the_json_ld_article_body_is_used_when_it_has_more_text_than_the_markup(): void
    {
        $body = "The full text of the story is only in the structured data, which has enough words.\n\nSecond paragraph.\n\nThird paragraph.";
        $html = '<html><head><script type="application/ld+json">'
            .json_encode(['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'articleBody' => $body], JSON_THROW_ON_ERROR)
            .'</script></head><body><article><p>Short teaser.</p></article></body></html>';

        $this->assertSame($body, app(ArticleTextExtractor::class)->fromHtml($html));
    }

    public function test_a_cut_text_never_ends_in_half_a_word(): void
    {
        config(['news.max_article_characters' => 22]);

        $text = app(ArticleTextExtractor::class)->fromHtml(
            '<html><body><main><p>Alpha beta gamma delta epsilon</p><p>Second.</p><p>Third.</p></main></body></html>'
        );

        $this->assertSame('Alpha beta gamma', $text);
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

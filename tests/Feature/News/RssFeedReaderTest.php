<?php

namespace Tests\Feature\News;

use App\Services\News\FeedItem;
use App\Services\News\RssFeedReader;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use UnexpectedValueException;

class RssFeedReaderTest extends TestCase
{
    public function test_it_parses_rss_items(): void
    {
        $this->freezeSecond();

        $items = app(RssFeedReader::class)->parse(file_get_contents(base_path('tests/Fixtures/news/feed.xml')));

        $this->assertContainsOnlyInstancesOf(FeedItem::class, $items);
        $this->assertSame([
            'https://www.bbc.co.uk/news/articles/transport1',
            'https://www.bbc.co.uk/news/articles/genes2?page=2',
            'https://www.bbc.co.uk/news/videos/volcano3',
        ], $items->pluck('url')->all());

        $first = $items->first();
        $this->assertSame('City council approves sweeping overhaul of public transport', $first->title);
        $this->assertSame('2026-10-07 12:16:58', $first->publishedAt->toDateTimeString());

        // Dates are converted to the application timezone (UTC) before being stored.
        $this->assertSame('2026-10-07 10:12:36', $items->get(1)->publishedAt->toDateTimeString());
        $this->assertSame('UTC', $items->get(1)->publishedAt->tzName);

        // Unparseable dates fall back to the time of harvesting.
        $this->assertTrue($items->last()->publishedAt->equalTo(now()));
    }

    public function test_it_rejects_documents_that_are_not_rss(): void
    {
        $this->expectException(UnexpectedValueException::class);

        app(RssFeedReader::class)->parse('<html><body>Not a feed</body></html>');
    }

    public function test_it_downloads_the_feed(): void
    {
        Http::preventStrayRequests();
        Http::fake(['feeds.example.test/*' => Http::response(file_get_contents(base_path('tests/Fixtures/news/feed.xml')))]);

        $items = app(RssFeedReader::class)->read('https://feeds.example.test/world.xml');

        $this->assertCount(3, $items);
        Http::assertSent(fn ($request) => $request->url() === 'https://feeds.example.test/world.xml'
            && str_starts_with($request->header('User-Agent')[0], config('app.name')));
    }

    public function test_it_throws_when_the_feed_cannot_be_downloaded(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['feeds.example.test/*' => Http::response('', 503)]);

        $this->expectException(RequestException::class);

        app(RssFeedReader::class)->read('https://feeds.example.test/world.xml');
    }
}

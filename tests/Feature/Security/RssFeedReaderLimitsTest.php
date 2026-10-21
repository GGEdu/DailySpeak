<?php

namespace Tests\Feature\Security;

use App\Services\News\RssFeedReader;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Feeds come from the internet and from admins: the reader must not read local files through entities,
 * must not expand entity bombs, must refuse oversized downloads and must give up after a few failures.
 */
class RssFeedReaderLimitsTest extends TestCase
{
    public function test_external_entities_are_not_read_from_the_disk(): void
    {
        $path = base_path('tests/Fixtures/news/article.html');
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE rss [<!ENTITY secret SYSTEM "file://{$path}">]>
        <rss version="2.0"><channel>
            <item><title>Plain title</title><link>https://example.test/plain</link></item>
            <item><title>&secret;</title><link>https://example.test/leak</link></item>
        </channel></rss>
        XML;

        $items = app(RssFeedReader::class)->parse($xml);

        // The entity is left empty, so its item is dropped instead of carrying the file's contents.
        $this->assertSame(['Plain title'], $items->pluck('title')->all());
    }

    public function test_entity_expansion_bombs_are_not_expanded(): void
    {
        // Each level repeats the one below ten times: "&lol9;" would be a billion copies of "lol" if expanded.
        $entities = ['<!ENTITY lol0 "lol">'];

        for ($level = 1; $level <= 9; $level++) {
            $entities[] = "<!ENTITY lol{$level} \"".str_repeat("&lol".($level - 1).";", 10).'">';
        }

        $dtd = implode("\n", $entities);
        $xml = "<?xml version=\"1.0\"?>\n<!DOCTYPE rss [\n{$dtd}\n]>\n<rss version=\"2.0\"><channel>"
            .'<item><title>Plain title</title><link>https://example.test/plain</link></item>'
            .'<item><title>&lol9;</title><link>https://example.test/bomb</link></item>'
            .'</channel></rss>';

        try {
            $titles = app(RssFeedReader::class)->parse($xml)->pluck('title')->all();
        } catch (UnexpectedValueException $exception) {
            // Refusing the document is the safe outcome: nothing was expanded.
            $this->assertStringContainsString('not a valid', $exception->getMessage());

            return;
        }

        $this->assertSame(['Plain title'], $titles);
    }

    public function test_a_feed_larger_than_the_limit_is_refused_while_downloading(): void
    {
        $feed = file_get_contents(base_path('tests/Fixtures/news/feed.xml'));
        $limit = strlen($feed) - 1;
        config(['news.max_feed_bytes' => $limit]);
        Http::preventStrayRequests();
        Http::fake(['feeds.example.test/*' => Http::response($feed)]);

        // A valid feed, so only the size limit can refuse it.
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("larger than {$limit} bytes");

        app(RssFeedReader::class)->read('https://feeds.example.test/world.xml');
    }

    public function test_a_feed_that_fails_is_retried_before_it_is_reported(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['feeds.example.test/*' => Http::response('', 503)]);

        try {
            app(RssFeedReader::class)->read('https://feeds.example.test/world.xml');

            $this->fail('A feed that keeps answering 503 must be reported to the caller.');
        } catch (RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
        }

        // Laravel's retry() counts every attempt, so the reader asks more than once before giving up.
        $this->assertGreaterThan(1, Http::recorded()->count());
    }
}

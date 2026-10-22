<?php

namespace Tests\Feature\News;

use App\Services\News\HostResolver;
use App\Services\News\SafeHttpFetcher;
use App\Services\News\UnsafeUrlException;
use Closure;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;
use UnexpectedValueException;

class SafeHttpFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
    }

    public function test_it_returns_the_body_of_a_public_url(): void
    {
        Http::fake(['cdn.example.test/*' => Http::response('<rss/>')]);

        $this->assertSame('<rss/>', $this->fetch('https://cdn.example.test/feed.xml'));
    }

    public function test_only_http_and_https_are_allowed(): void
    {
        foreach (['file:///etc/passwd', 'ftp://cdn.example.test/feed.xml', 'gopher://cdn.example.test/'] as $url) {
            $this->assertThrows(fn () => $this->fetch($url), UnsafeUrlException::class);
        }
        Http::assertNothingSent();
    }

    public function test_addresses_that_are_not_public_are_refused_whether_literal_or_resolved(): void
    {
        $addresses = [
            '127.0.0.1', '10.1.2.3', '172.16.5.4', '192.168.0.10', '169.254.169.254', '100.64.0.1',
            '0.0.0.0', '192.0.0.8', '224.0.0.1', '240.0.0.1', '198.18.0.1',
            '::', '::1', '::ffff:127.0.0.1', '::ffff:0:7f00:1', '::ffff:0:a00:1', '64:ff9b::7f00:1', 'fc00::1', 'fe80::1', 'fec0::1', 'ff02::1', '2001:db8::1',
        ];

        foreach ($addresses as $address) {
            $host = str_contains($address, ':') ? "[{$address}]" : $address;

            $this->assertThrows(fn () => $this->fetch("http://{$host}/feed.xml"), UnsafeUrlException::class);

            $this->app->instance(HostResolver::class, new FakeHostResolver(['internal.example.test' => [$address]]));
            $this->assertThrows(fn () => $this->fetch('https://internal.example.test/feed.xml'), UnsafeUrlException::class);
        }
        Http::assertNothingSent();
    }

    public function test_public_ipv6_addresses_are_allowed(): void
    {
        $this->resolveTo(['ipv6.example.test' => ['2606:4700:4700::1111']]);
        Http::fake(['ipv6.example.test/*' => Http::response('ok')]);

        $this->assertSame('ok', $this->fetch('https://ipv6.example.test/feed.xml'));
    }

    public function test_a_host_is_refused_when_any_of_its_addresses_is_not_public(): void
    {
        $this->resolveTo(['mixed.example.test' => ['93.184.216.34', '10.0.0.5']]);

        $this->assertThrows(fn () => $this->fetch('https://mixed.example.test/feed.xml'), UnsafeUrlException::class);
        Http::assertNothingSent();
    }

    public function test_hosts_that_do_not_resolve_are_a_connection_error(): void
    {
        $this->resolveTo(['gone.example.test' => []]);

        $this->assertThrows(fn () => $this->fetch('https://gone.example.test/feed.xml'), ConnectionException::class);
    }

    public function test_redirects_are_followed_to_public_addresses(): void
    {
        Http::fake([
            'start.example.test/feed' => Http::response('', 301, ['Location' => 'https://cdn.example.test/feed.xml']),
            'cdn.example.test/feed.xml' => Http::response('final body'),
        ]);

        $this->assertSame('final body', $this->fetch('https://start.example.test/feed'));
    }

    public function test_a_redirect_is_followed_when_retries_are_on(): void
    {
        // Laravel hands the retry callback no exception for a 3xx (it is not a failure): the redirect is followed,
        // not retried, and nothing blows up. Feeds and robots.txt are read with retries.
        Http::fake([
            'start.example.test/feed' => Http::response('', 301, ['Location' => 'https://cdn.example.test/feed.xml']),
            'cdn.example.test/feed.xml' => Http::response('final body'),
        ]);

        $this->assertSame('final body', $this->fetch('https://start.example.test/feed', retries: 2));
        Http::assertSentCount(2);
    }

    public function test_relative_redirects_are_resolved_against_the_current_url(): void
    {
        Http::fake([
            'start.example.test/feed' => Http::response('', 302, ['Location' => '/articles/1']),
            'start.example.test/articles/1' => Http::response('relative body'),
        ]);

        $this->assertSame('relative body', $this->fetch('https://start.example.test/feed'));
    }

    public function test_every_redirect_hop_is_checked_before_it_is_followed(): void
    {
        $this->resolveTo([
            'cdn.example.test' => ['93.184.216.34'],
            'internal.example.test' => ['10.0.0.5'],
        ]);
        Http::fake([
            'cdn.example.test/*' => Http::response('', 302, ['Location' => 'http://internal.example.test/secret']),
            'internal.example.test/*' => Http::response('secret'),
        ]);

        $this->assertThrows(fn () => $this->fetch('https://cdn.example.test/feed'), UnsafeUrlException::class);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'internal.example.test'));
    }

    public function test_redirect_loops_are_stopped(): void
    {
        Http::fake(['loop.example.test/*' => Http::response('', 302, ['Location' => 'https://loop.example.test/again'])]);

        $this->assertThrows(fn () => $this->fetch('https://loop.example.test/feed'), UnexpectedValueException::class);
    }

    public function test_bodies_larger_than_the_limit_are_refused(): void
    {
        Http::fake(['big.example.test/*' => Http::response(str_repeat('a', 2048))]);

        $this->assertThrows(fn () => $this->fetch('https://big.example.test/feed', maxBytes: 1024), UnexpectedValueException::class);
    }

    public function test_a_declared_length_over_the_limit_is_refused_before_reading(): void
    {
        Http::fake(['big.example.test/*' => Http::response('small', 200, ['Content-Length' => '99999999'])]);

        $this->assertThrows(fn () => $this->fetch('https://big.example.test/feed', maxBytes: 1024), UnexpectedValueException::class);
    }

    public function test_http_error_statuses_raise_a_request_exception_without_the_body(): void
    {
        Http::fake(['down.example.test/*' => Http::response(str_repeat('x', 5000), 404)]);

        try {
            $this->fetch('https://down.example.test/feed');
            $this->fail('A 404 must not be returned as content.');
        } catch (RequestException $e) {
            $this->assertStringContainsString('404', $e->getMessage());
            $this->assertStringNotContainsString('xxxx', $e->getMessage());
        }
    }

    public function test_private_addresses_can_be_allowed_for_local_development(): void
    {
        $this->assertFalse(config('news.allow_private_hosts'));

        config(['news.allow_private_hosts' => true]);
        Http::fake(['127.0.0.1/*' => Http::response('local')]);

        $this->assertSame('local', $this->fetch('http://127.0.0.1/feed.xml'));
    }

    public function test_the_connection_is_pinned_to_the_checked_address_when_dns_answers_differently_later(): void
    {
        $resolver = new class implements HostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                // The first answer is public; a second lookup would point at the internal network.
                return ++$this->calls === 1 ? ['93.184.216.34'] : ['10.0.0.5'];
            }
        };
        $this->app->instance(HostResolver::class, $resolver);
        $curl = [];
        $this->fakeRecordingCurl(['rebind.example.test/*' => Http::response('ok')], $curl);

        $this->assertSame('ok', $this->fetch('https://rebind.example.test/feed.xml'));

        $this->assertSame(1, $resolver->calls);
        $this->assertSame(['rebind.example.test:443:93.184.216.34'], $curl[0][CURLOPT_RESOLVE]);
    }

    public function test_every_attempt_uses_the_address_that_was_checked(): void
    {
        $resolver = new class implements HostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                return ++$this->calls === 1 ? ['93.184.216.34'] : ['10.0.0.5'];
            }
        };
        $this->app->instance(HostResolver::class, $resolver);
        $curl = [];
        $attempts = 0;
        $this->fakeRecordingCurl([
            'flaky.example.test/*' => function () use (&$attempts) {
                return ++$attempts === 1 ? Http::response('', 503) : Http::response('ok');
            },
        ], $curl);

        $this->assertSame('ok', $this->fetch('https://flaky.example.test/feed.xml', retries: 1));

        $this->assertSame(2, $attempts);
        $this->assertSame(1, $resolver->calls);
        $this->assertSame(['flaky.example.test:443:93.184.216.34'], $curl[0][CURLOPT_RESOLVE]);
        $this->assertSame(['flaky.example.test:443:93.184.216.34'], $curl[1][CURLOPT_RESOLVE]);
    }

    public function test_every_redirect_hop_is_pinned_to_its_own_checked_address(): void
    {
        $this->resolveTo([
            'start.example.test' => ['93.184.216.34'],
            'cdn.example.test' => ['93.184.216.35'],
        ]);
        $curl = [];
        $this->fakeRecordingCurl([
            'start.example.test/*' => Http::response('', 302, ['Location' => 'https://cdn.example.test/feed.xml']),
            'cdn.example.test/*' => Http::response('final body'),
        ], $curl);

        $this->assertSame('final body', $this->fetch('https://start.example.test/feed'));

        $this->assertSame(['start.example.test:443:93.184.216.34'], $curl[0][CURLOPT_RESOLVE]);
        $this->assertSame(['cdn.example.test:443:93.184.216.35'], $curl[1][CURLOPT_RESOLVE]);
    }

    public function test_ipv6_addresses_are_pinned_in_brackets_and_literal_addresses_are_not_pinned(): void
    {
        $this->resolveTo(['v6.example.test' => ['2606:4700:4700::1111']]);
        $curl = [];
        $this->fakeRecordingCurl([
            'v6.example.test/*' => Http::response('six'),
            '93.184.216.34/*' => Http::response('four'),
        ], $curl);

        $this->assertSame('six', $this->fetch('https://v6.example.test/feed.xml'));
        $this->assertSame(['v6.example.test:443:[2606:4700:4700::1111]'], $curl[0][CURLOPT_RESOLVE]);

        $this->assertSame('four', $this->fetch('https://93.184.216.34/feed.xml'));
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $curl[1]);
    }

    public function test_the_size_limit_is_handed_to_curl_so_the_download_stops_at_it(): void
    {
        $curl = [];
        $this->fakeRecordingCurl(['big.example.test/*' => Http::response('small')], $curl);

        $this->fetch('https://big.example.test/feed', maxBytes: 1024);

        $this->assertSame(1024, $curl[0][CURLOPT_MAXFILESIZE_LARGE]);
    }

    public function test_the_body_is_asked_uncompressed_and_curl_is_told_not_to_decode_it(): void
    {
        $options = [];
        $acceptEncoding = null;
        Http::fake(['plain.example.test/*' => function ($request, array $requestOptions) use (&$options, &$acceptEncoding) {
            $options = $requestOptions;
            $acceptEncoding = $request->header('Accept-Encoding');

            return Http::response('plain');
        }]);

        $this->assertSame('plain', $this->fetch('https://plain.example.test/feed'));

        $this->assertFalse($options['decode_content'] ?? true);
        $this->assertSame(['identity'], $acceptEncoding);
    }

    public function test_a_compressed_response_is_refused_even_when_its_compressed_bytes_are_small(): void
    {
        // About 1 KB on the wire, a megabyte once decoded: the limit must not be judged on the compressed size.
        $compressed = gzencode(str_repeat('a', 1_000_000));
        Http::fake(['zip.example.test/*' => Http::response($compressed, 200, ['Content-Encoding' => 'gzip'])]);

        $this->assertThrows(fn () => $this->fetch('https://zip.example.test/feed', maxBytes: 4096), UnexpectedValueException::class);
    }

    public function test_an_identity_content_encoding_is_accepted(): void
    {
        Http::fake(['plain.example.test/*' => Http::response('plain', 200, ['Content-Encoding' => 'identity'])]);

        $this->assertSame('plain', $this->fetch('https://plain.example.test/feed'));
    }

    public function test_a_download_cut_off_by_the_size_limit_is_too_large_and_is_not_retried(): void
    {
        $attempts = 0;
        // The shape Laravel's client gives when the headers arrived before curl stopped at the size limit.
        $cutOff = new GuzzleRequestException('cURL error 63: Maximum file size exceeded', new PsrRequest('GET', 'https://big.example.test/feed'), null, null, ['errno' => CURLE_FILESIZE_EXCEEDED]);
        Http::fake(['big.example.test/*' => function () use (&$attempts, $cutOff) {
            $attempts++;

            throw new ConnectionException($cutOff->getMessage(), 0, $cutOff);
        }]);

        $this->assertThrows(fn () => $this->fetch('https://big.example.test/feed', maxBytes: 1024, retries: 2), UnexpectedValueException::class);
        $this->assertSame(1, $attempts);
    }

    public function test_connection_errors_are_retried_with_a_growing_pause(): void
    {
        $attempts = 0;
        Http::fake(['down.example.test/*' => function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new ConnectionException('Connection reset by peer');
            }

            return Http::response('back');
        }]);

        $this->assertSame('back', $this->fetch('https://down.example.test/feed', retries: 2));

        $this->assertSame(3, $attempts);
        Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1000)->milliseconds()]);
    }

    public function test_server_errors_are_retried_and_then_reported(): void
    {
        Http::fake(['broken.example.test/*' => Http::response('Oops', 503)]);

        $this->assertThrows(fn () => $this->fetch('https://broken.example.test/feed', retries: 2), RequestException::class);

        Http::assertSentCount(3);
    }

    public function test_client_errors_are_not_retried(): void
    {
        $attempts = 0;
        $status = 403;
        Http::fake(['limited.example.test/*' => function () use (&$attempts, &$status) {
            $attempts++;

            return Http::response('No', $status);
        }]);

        $this->assertThrows(fn () => $this->fetch('https://limited.example.test/feed', retries: 2), RequestException::class);
        $status = 429;
        $this->assertThrows(fn () => $this->fetch('https://limited.example.test/feed', retries: 2), RequestException::class);

        $this->assertSame(2, $attempts);
        Sleep::assertNeverSlept();
    }

    public function test_private_hosts_cannot_be_allowed_in_production(): void
    {
        config(['news.allow_private_hosts' => true]);
        $this->app['env'] = 'production';

        $this->assertThrows(fn () => $this->fetch('http://127.0.0.1/feed.xml'), RuntimeException::class);
        Http::assertNothingSent();
    }

    private function fetch(string $url, int $maxBytes = 1024, int $retries = 0): string
    {
        return app(SafeHttpFetcher::class)->get($url, $maxBytes, $retries);
    }

    /**
     * @param  array<string, list<string>>  $addresses
     */
    private function resolveTo(array $addresses): void
    {
        $this->app->instance(HostResolver::class, new FakeHostResolver($addresses));
    }

    /**
     * Fake responses and keep the curl options each request was sent with, one entry per request.
     *
     * @param  array<string, mixed>  $responses  URL pattern => response
     * @param  list<array<int, mixed>>  $curl
     */
    private function fakeRecordingCurl(array $responses, array &$curl): void
    {
        $stubs = [];

        foreach ($responses as $pattern => $response) {
            $stubs[$pattern] = function ($request, array $options) use ($response, &$curl) {
                $curl[] = $options['curl'] ?? [];

                return $response instanceof Closure ? $response($request, $options) : $response;
            };
        }

        Http::fake($stubs);
    }
}

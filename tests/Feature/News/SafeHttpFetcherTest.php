<?php

namespace Tests\Feature\News;

use App\Services\News\HostResolver;
use App\Services\News\SafeHttpFetcher;
use App\Services\News\UnsafeUrlException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
            '::', '::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', 'fc00::1', 'fe80::1', 'fec0::1', 'ff02::1', '2001:db8::1',
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

    private function fetch(string $url, int $maxBytes = 1024): string
    {
        return app(SafeHttpFetcher::class)->get($url, $maxBytes);
    }

    /**
     * @param  array<string, list<string>>  $addresses
     */
    private function resolveTo(array $addresses): void
    {
        $this->app->instance(HostResolver::class, new FakeHostResolver($addresses));
    }
}

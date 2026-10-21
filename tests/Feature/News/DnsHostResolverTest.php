<?php

namespace Tests\Feature\News;

use App\Services\News\DnsHostResolver;
use App\Services\News\HostResolver;
use App\Services\News\SafeHttpFetcher;
use App\Services\News\UnsafeUrlException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The real resolver, which the tests replace everywhere else. These hosts must be refused without any
 * DNS lookup, so the tests need no network.
 */
class DnsHostResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->app->forgetInstance(HostResolver::class);
    }

    public function test_the_container_binds_the_system_resolver(): void
    {
        $this->assertInstanceOf(DnsHostResolver::class, app(HostResolver::class));
    }

    public function test_numeric_host_names_resolve_to_the_address_they_spell(): void
    {
        $resolver = new DnsHostResolver;

        foreach (['2130706433', '0x7f.1', '127.1', '0177.0.0.1', '0x7f000001', '127.0.0.0x1'] as $host) {
            $this->assertSame(['127.0.0.1'], $resolver->resolve($host), $host);
        }

        $this->assertSame(['10.0.0.1'], $resolver->resolve('0xa.0.0.1'));
    }

    public function test_localhost_names_are_loopback(): void
    {
        $resolver = new DnsHostResolver;

        $this->assertSame(['127.0.0.1', '::1'], $resolver->resolve('localhost.'));
        $this->assertSame(['127.0.0.1', '::1'], $resolver->resolve('LOCALHOST'));
        $this->assertSame(['127.0.0.1', '::1'], $resolver->resolve('printer.localhost'));
    }

    public function test_numeric_and_localhost_hosts_are_refused_before_any_request(): void
    {
        $urls = [
            'http://2130706433/feed.xml',
            'http://0x7f.1/feed.xml',
            'http://127.1/feed.xml',
            'http://0177.0.0.1/feed.xml',
            'https://localhost./feed.xml',
            'http://[::ffff:7f00:1]/feed.xml',
            'http://user@127.0.0.1/feed.xml',
            'http://user:pass@2130706433:8080/feed.xml',
        ];

        foreach ($urls as $url) {
            try {
                app(SafeHttpFetcher::class)->get($url, 1024);
                $this->fail("{$url} was not refused.");
            } catch (UnsafeUrlException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }
}

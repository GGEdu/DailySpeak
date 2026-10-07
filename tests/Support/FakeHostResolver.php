<?php

namespace Tests\Support;

use App\Services\News\HostResolver;

/**
 * Resolves hosts from a fixed map. Hosts not in the map resolve to a public address,
 * so tests that only fake HTTP responses keep working without network access.
 */
final class FakeHostResolver implements HostResolver
{
    /** A globally routable address (example.com), outside every non-public range. */
    public const PUBLIC_ADDRESS = '93.184.216.34';

    /**
     * @param  array<string, list<string>>  $addresses  host => addresses
     */
    public function __construct(private readonly array $addresses = []) {}

    public function resolve(string $host): array
    {
        return $this->addresses[$host] ?? [self::PUBLIC_ADDRESS];
    }
}

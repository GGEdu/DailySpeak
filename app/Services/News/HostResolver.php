<?php

namespace App\Services\News;

/**
 * Looks up the IP addresses a host name points to. Injected so tests never touch real DNS.
 */
interface HostResolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when the host does not resolve.
     */
    public function resolve(string $host): array;
}

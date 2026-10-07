<?php

namespace App\Services\News;

class DnsHostResolver implements HostResolver
{
    /**
     * Resolve IPv4 (A) and IPv6 (AAAA) addresses through the system resolver.
     */
    public function resolve(string $host): array
    {
        $ipv4 = rescue(fn () => gethostbynamel($host) ?: [], [], report: false);
        $ipv6 = rescue(fn () => array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'), [], report: false);

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}

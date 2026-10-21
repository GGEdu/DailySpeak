<?php

namespace App\Services\News;

class DnsHostResolver implements HostResolver
{
    /**
     * Resolve IPv4 (A) and IPv6 (AAAA) addresses through the system resolver.
     *
     * Numeric host names and names under localhost are answered without asking DNS: the address is in the
     * name itself, or localhost is loopback by definition (RFC 6761).
     */
    public function resolve(string $host): array
    {
        if (($address = $this->numericIpv4($host)) !== null) {
            return [$address];
        }

        if ($this->isLocalhost($host)) {
            return ['127.0.0.1', '::1'];
        }

        $ipv4 = rescue(fn () => gethostbynamel($host) ?: [], [], report: false);
        $ipv6 = rescue(fn () => array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'), [], report: false);

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }

    /**
     * The dotted address for the forms inet_aton (and so curl) accepts: one to four parts, each decimal,
     * octal (a leading 0) or hexadecimal (0x); the last part fills the remaining bytes. 2130706433, 0x7f.1,
     * 127.1 and 0177.0.0.1 all mean 127.0.0.1. Null when the host is not in one of these forms.
     */
    private function numericIpv4(string $host): ?string
    {
        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $numbers = [];

        foreach ($parts as $part) {
            $number = match (true) {
                (bool) preg_match('/^0x([0-9a-f]*)$/i', $part, $hex) => $hex[1] === '' ? 0 : intval($hex[1], 16),
                (bool) preg_match('/^0[0-7]*$/', $part) => intval($part, 8),
                (bool) preg_match('/^[1-9][0-9]*$/', $part) => intval($part, 10),
                default => null,
            };

            if ($number === null) {
                return null;
            }

            $numbers[] = $number;
        }

        $last = array_pop($numbers);
        $address = 0;

        foreach ($numbers as $index => $number) {
            if ($number > 255) {
                return null;
            }

            $address += $number << (8 * (3 - $index));
        }

        if ($last > (1 << (8 * (4 - count($numbers)))) - 1) {
            return null;
        }

        return long2ip($address + $last) ?: null;
    }

    private function isLocalhost(string $host): bool
    {
        $name = strtolower(rtrim($host, '.'));

        return $name === 'localhost' || str_ends_with($name, '.localhost');
    }
}

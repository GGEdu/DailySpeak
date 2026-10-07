<?php

namespace App\Services\News;

use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;
use UnexpectedValueException;

/**
 * Downloads the feeds and article pages the harvester reads without letting them reach internal
 * networks: only http(s), only public addresses (checked again at every redirect), and bodies have
 * a size cap enforced while streaming.
 *
 * Known residual risk: the host is checked, then requested. Guzzle's streaming handler cannot pin
 * the connection to the checked address (curl's CURLOPT_RESOLVE needs the curl handler, which buffers
 * the whole body before the size cap could apply), so a host that changes its DNS answer between the
 * two steps is not caught.
 */
class SafeHttpFetcher
{
    /**
     * Ranges that are not public, on top of Symfony's list (loopback, RFC 1918, link-local,
     * shared address space, documentation and other reserved ranges).
     */
    private const EXTRA_NON_PUBLIC_RANGES = [
        '192.0.0.0/24',  // IETF protocol assignments
        '224.0.0.0/4',   // IPv4 multicast
        'fec0::/10',     // Deprecated IPv6 site-local
        'ff00::/8',      // IPv6 multicast
    ];

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private const MAX_REDIRECTS = 5;

    private const TIMEOUT_SECONDS = 15;

    private const READ_CHUNK_BYTES = 8192;

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Download a URL and return its body.
     *
     * @throws UnsafeUrlException when the URL, or a redirect, is not a public http(s) address
     * @throws ConnectionException when the host cannot be resolved or reached
     * @throws RequestException when the server answers with an error status
     * @throws UnexpectedValueException when the body exceeds $maxBytes or there are too many redirects
     */
    public function get(string $url, int $maxBytes, int $retries = 0): string
    {
        $current = $url;

        for ($redirects = 0; ; $redirects++) {
            $this->assertPublic($current);
            $response = $this->send($current, $retries);

            if (! $this->isRedirect($response)) {
                break;
            }

            if ($redirects === self::MAX_REDIRECTS) {
                throw new UnexpectedValueException('The URL redirects too many times.');
            }

            $current = (string) UriResolver::resolve(Utils::uriFor($current), Utils::uriFor($response->header('Location')));
        }

        if (! $response->successful()) {
            throw $this->statusException($response);
        }

        return $this->readBody($response, $maxBytes);
    }

    /**
     * Check that the URL is http(s) and that every address its host resolves to is public.
     *
     * @throws UnsafeUrlException
     * @throws ConnectionException
     */
    private function assertPublic(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])) {
            throw UnsafeUrlException::unsupportedScheme();
        }

        if (config('news.allow_private_hosts')) {
            return;
        }

        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new ConnectionException("The host {$host} cannot be resolved.");
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw UnsafeUrlException::nonPublicAddress();
            }
        }
    }

    private function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ! IpUtils::checkIp($address, [...IpUtils::PRIVATE_SUBNETS, ...self::EXTRA_NON_PUBLIC_RANGES]);
    }

    private function send(string $url, int $retries): Response
    {
        $request = Http::timeout(self::TIMEOUT_SECONDS)
            ->withUserAgent(config('app.name').'/1.0 (+'.config('app.url').')')
            ->withOptions([
                // Redirects are followed here, one checked hop at a time.
                'allow_redirects' => false,
                // Streamed, so the size cap applies while downloading rather than after it.
                'stream' => true,
            ]);

        if ($retries > 0) {
            // Failed responses are returned, not thrown, so statusException() decides how they look.
            $request = $request->retry($retries, 500, null, false);
        }

        return $request->get($url);
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), self::REDIRECT_STATUSES, true) && $response->header('Location') !== '';
    }

    /**
     * An error without the body: an error page of any size is never read.
     */
    private function statusException(Response $response): RequestException
    {
        return new RequestException(new Response($response->toPsrResponse()->withBody(Utils::streamFor(''))));
    }

    private function readBody(Response $response, int $maxBytes): string
    {
        if ((int) $response->header('Content-Length') > $maxBytes) {
            throw $this->tooLarge($maxBytes);
        }

        $stream = $response->toPsrResponse()->getBody();

        // A stream that was already read (a replayed response) starts again from the beginning.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(self::READ_CHUNK_BYTES);

            if (strlen($body) > $maxBytes) {
                // Stop the download instead of letting the server keep sending.
                $stream->close();

                throw $this->tooLarge($maxBytes);
            }
        }

        return $body;
    }

    private function tooLarge(int $maxBytes): UnexpectedValueException
    {
        return new UnexpectedValueException("The response is larger than {$maxBytes} bytes.");
    }
}

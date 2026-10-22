<?php

namespace App\Services\News;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;
use UnexpectedValueException;

/**
 * Downloads the feeds and article pages the harvester reads without letting them reach internal
 * networks: only http(s), only public addresses (checked again at every redirect), and bodies have
 * a size cap enforced by curl while downloading.
 *
 * The connection is pinned to the addresses that were checked: curl is told to use them for the host
 * (CURLOPT_RESOLVE), so a second DNS answer cannot send the request somewhere else. Each attempt and
 * each redirect hop is checked and pinned on its own.
 */
class SafeHttpFetcher
{
    /**
     * Ranges that are not public, on top of Symfony's list (loopback, RFC 1918, link-local,
     * shared address space, documentation and other reserved ranges).
     */
    private const EXTRA_NON_PUBLIC_RANGES = [
        '192.0.0.0/24',       // IETF protocol assignments
        '224.0.0.0/4',        // IPv4 multicast
        'fec0::/10',          // Deprecated IPv6 site-local
        'ff00::/8',           // IPv6 multicast
        '::ffff:0:0:0/96',    // IPv4 translated to IPv6 (SIIT), e.g. ::ffff:0:7f00:1
    ];

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private const MAX_REDIRECTS = 5;

    /** Total time for one attempt: connecting, the headers and the whole body. */
    private const TIMEOUT_SECONDS = 20;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    /** Wait before the first retry; it doubles for each one after. */
    private const RETRY_BACKOFF_MILLISECONDS = 500;

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Download a URL and return its body.
     *
     * @param  int  $retries  extra attempts after a connection error or a 5xx response
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
            $pinned = $this->assertPublic($current);
            $response = $this->send($current, $pinned, $retries, $maxBytes);

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
     * @return list<string>|null the checked addresses the connection is pinned to; null when the host is an IP address
     *
     * @throws UnsafeUrlException
     * @throws ConnectionException
     */
    private function assertPublic(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])) {
            throw UnsafeUrlException::unsupportedScheme();
        }

        if (config('news.allow_private_hosts')) {
            if (app()->isProduction()) {
                throw new RuntimeException('NEWS_ALLOW_PRIVATE_HOSTS must be false in production.');
            }

            return null;
        }

        $host = trim($parts['host'], '[]');
        $isAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $isAddress ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new ConnectionException("The host {$host} cannot be resolved.");
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw UnsafeUrlException::nonPublicAddress();
            }
        }

        return $isAddress ? null : $addresses;
    }

    private function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ! IpUtils::checkIp($address, [...IpUtils::PRIVATE_SUBNETS, ...self::EXTRA_NON_PUBLIC_RANGES]);
    }

    /**
     * @param  list<string>|null  $pinned  the addresses checked for this host, or null to connect as the URL says
     */
    private function send(string $url, ?array $pinned, int $retries, int $maxBytes): Response
    {
        $curl = [
            // curl stops the transfer once the body is larger than the limit, instead of downloading it all.
            CURLOPT_MAXFILESIZE_LARGE => $maxBytes,
        ];

        if ($pinned !== null) {
            if (! extension_loaded('curl')) {
                throw new RuntimeException('The curl extension is required to pin the connection to the checked addresses.');
            }

            $curl[CURLOPT_RESOLVE] = [$this->pinning($url, $pinned)];
        }

        $request = Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withUserAgent(config('app.name').'/1.0 (+'.config('app.url').')')
            // Only an uncompressed body is asked for: the size limit counts the bytes on the wire, which is then the body.
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->withOptions([
                // Redirects are followed here, one checked hop at a time.
                'allow_redirects' => false,
                // Guzzle would have curl decode gzip, br and zstd bodies, and the size limit does not count decoded bytes.
                'decode_content' => false,
                'curl' => $curl,
            ]);

        if ($retries > 0) {
            // Failed responses are returned, not thrown, so statusException() decides how they look.
            $request = $request->retry(
                $retries + 1,
                fn (int $attempt) => self::RETRY_BACKOFF_MILLISECONDS * 2 ** ($attempt - 1),
                // Laravel passes no exception for a 3xx, which is not a failure: the redirect is followed below.
                fn (?Throwable $e) => $e !== null && $this->shouldRetry($e),
                false,
            );
        }

        try {
            return $request->get($url);
        } catch (ConnectionException $e) {
            if ($this->exceededSizeLimit($e)) {
                throw $this->tooLarge($maxBytes);
            }

            throw $e;
        }
    }

    /**
     * Retry only what may succeed on a second try: a connection error (or timeout) and a 5xx response.
     */
    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return ! $this->exceededSizeLimit($e);
        }

        return $e instanceof RequestException && $e->response->serverError();
    }

    /**
     * The CURLOPT_RESOLVE entry: the host and port of the URL, and the checked addresses for them.
     *
     * @param  list<string>  $addresses
     */
    private function pinning(string $url, array $addresses): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $targets = array_map(fn (string $address) => str_contains($address, ':') ? "[{$address}]" : $address, $addresses);

        return "{$parts['host']}:{$port}:".implode(',', $targets);
    }

    /**
     * Guzzle reports the curl error as a ConnectException, or as a RequestException when the headers had
     * already arrived; Laravel wraps either one.
     */
    private function exceededSizeLimit(ConnectionException $e): bool
    {
        $previous = $e->getPrevious();

        return ($previous instanceof ConnectException || $previous instanceof GuzzleRequestException)
            && ($previous->getHandlerContext()['errno'] ?? null) === CURLE_FILESIZE_EXCEEDED;
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

    /**
     * The body has already been downloaded under the size limit; this checks it again before it is used.
     */
    private function readBody(Response $response, int $maxBytes): string
    {
        if ($this->isCompressed($response)) {
            throw new UnexpectedValueException('The response is compressed; only an uncompressed body is accepted.');
        }

        if ((int) $response->header('Content-Length') > $maxBytes) {
            throw $this->tooLarge($maxBytes);
        }

        $stream = $response->toPsrResponse()->getBody();

        // A stream that was already read (a replayed response) starts again from the beginning.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = (string) $stream;

        if (strlen($body) > $maxBytes) {
            throw $this->tooLarge($maxBytes);
        }

        return $body;
    }

    /**
     * A compressed body is refused rather than decoded: decoding it here would have to count the decoded bytes
     * against the limit, and a server that ignores "identity" would otherwise be stored as compressed bytes.
     */
    private function isCompressed(Response $response): bool
    {
        $encoding = strtolower(trim($response->header('Content-Encoding')));

        return $encoding !== '' && $encoding !== 'identity';
    }

    private function tooLarge(int $maxBytes): UnexpectedValueException
    {
        return new UnexpectedValueException("The response is larger than {$maxBytes} bytes.");
    }
}

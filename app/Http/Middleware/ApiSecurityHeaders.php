<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hardening headers that mean something for JSON: no framing, no MIME sniffing, no referrer leaks. The page
 * policy of SecurityHeaders (the nonce-based Content Security Policy) is for HTML, so the API does not get it.
 *
 * A header the response already sets is left as it is.
 */
class ApiSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardening headers for every page: a Content Security Policy that only runs scripts carrying the Vite nonce,
 * no framing (the microphone button must not be clickjacked), no MIME sniffing, no referrer leaks, and the
 * microphone allowed for this site only.
 *
 * A header the response already sets (such as the sandbox policy on signed files) is left as it is.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // The Vite dev server injects its own scripts and styles, which a strict policy would block.
        $hot = Vite::isRunningHot();

        if (! $hot) {
            // Must be set before the view renders, so every @vite tag gets the nonce.
            $nonce = Vite::useCspNonce();
        }

        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'microphone=(self), camera=(), geolocation=()',
        ];

        if (! $hot) {
            $headers['Content-Security-Policy'] = $this->contentSecurityPolicy($nonce);
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        // Inertia's progress bar injects a <style> element at runtime, which needs 'unsafe-inline' for styles.
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            'connect-src '.implode(' ', ["'self'", ...$this->reverbSources()]),
            "img-src 'self' data:",
            "media-src 'self' blob:",
            "font-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * The WebSocket host the browser connects to for live debate updates.
     *
     * @return list<string>
     */
    private function reverbSources(): array
    {
        $options = config('reverb.apps.apps.0.options', []);
        $host = $options['host'] ?? null;

        if (blank($host)) {
            return [];
        }

        $authority = $host.(isset($options['port']) ? ':'.$options['port'] : '');

        return ["ws://{$authority}", "wss://{$authority}"];
    }
}

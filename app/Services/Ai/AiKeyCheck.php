<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Proves a user's key before it is kept: one GET to the provider's list of models (OpenRouter's key information, see
 * ProviderCatalog), which spends no tokens. Only the outcome leaves here: never the key, and never the provider's
 * error body, which could echo it.
 */
class AiKeyCheck
{
    /**
     * @return array{ok: bool, message: string}
     */
    public function run(string $provider, string $key): array
    {
        $check = ProviderCatalog::check($provider);

        $request = Http::acceptJson()
            ->timeout(10)
            ->withHeaders($this->headers($check['auth'], $key));

        if ($check['auth'] === 'bearer') {
            $request = $request->withToken($key);
        }

        try {
            $response = $request->get(ProviderCatalog::url($provider).$check['check']);
        } catch (ConnectionException) {
            return $this->result(false, 'The provider could not be reached.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            return $this->result(false, 'The provider refused this key.');
        }

        if ($response->failed()) {
            return $this->result(false, "The provider answered with status {$response->status()}.");
        }

        return $this->result(true, 'The key works.');
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $auth, string $key): array
    {
        return match ($auth) {
            'x-api-key' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            'x-goog-api-key' => ['x-goog-api-key' => $key],
            default => [],
        };
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function result(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => $message];
    }
}

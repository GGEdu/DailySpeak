<?php

namespace App\Services;

use App\Services\Ai\PlatformAi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Checks the AI connection for the admin's "Test" button: GET {address}/models with the key. Listing the models spends
 * no tokens. Only the outcome leaves here, never the key, and never the provider's error body, which could echo it.
 */
class AiConnectionCheck
{
    /**
     * Most model names returned to the panel.
     */
    private const MAX_MODELS = 100;

    /**
     * @return array{ok: bool, message: string, models: list<string>}
     */
    public function run(): array
    {
        ['url' => $url, 'key' => $key] = app(PlatformAi::class)->textAddress();

        if (blank($url)) {
            return $this->result(false, 'No address is set for the AI service.');
        }

        try {
            $response = Http::acceptJson()
                ->when(filled($key), fn ($http) => $http->withToken((string) $key))
                ->timeout(10)
                ->get(rtrim((string) $url, '/').'/models');
        } catch (ConnectionException) {
            return $this->result(false, 'The AI service could not be reached at that address.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            return $this->result(false, 'The AI service refused the key.');
        }

        if ($response->failed()) {
            return $this->result(false, "The AI service answered with status {$response->status()}.");
        }

        $data = $response->json('data');

        $models = collect(is_array($data) ? $data : [])
            ->pluck('id')
            ->filter(fn (mixed $id) => is_string($id) && $id !== '')
            ->take(self::MAX_MODELS)
            ->values()
            ->all();

        return $this->result(true, 'Connected. '.count($models).' models are available.', $models);
    }

    /**
     * @param  list<string>  $models
     * @return array{ok: bool, message: string, models: list<string>}
     */
    private function result(bool $ok, string $message, array $models = []): array
    {
        return ['ok' => $ok, 'message' => $message, 'models' => $models];
    }
}

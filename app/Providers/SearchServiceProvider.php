<?php

namespace App\Providers;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Scout;
use Meilisearch\Client as MeilisearchClient;

/**
 * The Meilisearch client Scout uses. Scout builds its own without timeouts, so a hung engine holds a
 * PHP-FPM worker for every pause while typing, and rescue() never gets the chance to fall back to SQL.
 */
class SearchServiceProvider extends ServiceProvider
{
    /**
     * Seconds a request to the search engine may take before the feed falls back to SQL.
     */
    private const TIMEOUT = 3.0;

    /**
     * Seconds to open the connection.
     */
    private const CONNECT_TIMEOUT = 1.0;

    /**
     * Index settings and imports run from the console and can take longer.
     */
    private const CONSOLE_TIMEOUT = 30.0;

    /**
     * Register the search client.
     */
    public function register(): void
    {
        // App providers register after the package ones, so this binding replaces Scout's.
        $this->app->singleton(MeilisearchClient::class, fn (Application $app) => new MeilisearchClient(
            config('scout.meilisearch.host'),
            config('scout.meilisearch.key'),
            self::httpClient($app->runningInConsole()),
            clientAgents: [sprintf('Meilisearch Laravel Scout (v%s)', Scout::VERSION)],
        ));
    }

    /**
     * The HTTP client behind the Meilisearch client.
     */
    public static function httpClient(bool $console = false): HttpClient
    {
        return new HttpClient([
            'timeout' => $console ? self::CONSOLE_TIMEOUT : self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
        ]);
    }
}

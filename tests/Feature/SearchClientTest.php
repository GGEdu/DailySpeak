<?php

namespace Tests\Feature;

use App\Providers\SearchServiceProvider;
use GuzzleHttp\Client as HttpClient;
use Laravel\Scout\EngineManager;
use Meilisearch\Client as MeilisearchClient;
use ReflectionProperty;
use Tests\TestCase;

class SearchClientTest extends TestCase
{
    public function test_web_requests_give_up_on_a_hung_search_engine_after_three_seconds(): void
    {
        $http = SearchServiceProvider::httpClient(console: false);

        $this->assertSame(3.0, $http->getConfig('timeout'));
        $this->assertSame(1.0, $http->getConfig('connect_timeout'));
    }

    public function test_console_commands_get_more_time_for_index_settings_and_imports(): void
    {
        $http = SearchServiceProvider::httpClient(console: true);

        $this->assertSame(30.0, $http->getConfig('timeout'));
        $this->assertSame(1.0, $http->getConfig('connect_timeout'));
    }

    public function test_the_container_client_carries_the_timeouts(): void
    {
        $client = $this->app->make(MeilisearchClient::class);

        // Meilisearch\Client -> its HTTP adapter -> the PSR-18 client (Guzzle) that performs the requests.
        $adapter = (new ReflectionProperty($client, 'http'))->getValue($client);
        $guzzle = (new ReflectionProperty($adapter, 'http'))->getValue($adapter);

        $this->assertInstanceOf(HttpClient::class, $guzzle);
        $this->assertSame(30.0, $guzzle->getConfig('timeout'), 'The testsuite runs from the CLI, so the console timeout applies.');
    }

    public function test_scout_searches_through_the_container_client(): void
    {
        $engine = $this->app->make(EngineManager::class)->engine('meilisearch');

        $this->assertSame(
            $this->app->make(MeilisearchClient::class),
            (new ReflectionProperty($engine, 'meilisearch'))->getValue($engine),
        );
    }
}

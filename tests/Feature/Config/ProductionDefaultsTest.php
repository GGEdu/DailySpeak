<?php

namespace Tests\Feature\Config;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_cookies_are_secure_by_default_in_production(): void
    {
        $this->assertFalse($this->configFileIn('testing', 'session')['secure']);
        $this->assertTrue($this->configFileIn('production', 'session')['secure']);
    }

    public function test_local_development_hosts_are_stateful_only_outside_production(): void
    {
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        $this->assertContains('localhost:3000', $this->configFileIn('testing', 'sanctum')['stateful']);
        $this->assertSame([$appHost], $this->configFileIn('production', 'sanctum')['stateful']);
    }

    public function test_the_demo_seeder_does_nothing_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // Run directly: db:seed asks for confirmation in production.
        (new DatabaseSeeder)->run();

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Config files are read when the app boots, so the environment variable is switched for the one read.
     *
     * @return array<string, mixed>
     */
    private function configFileIn(string $environment, string $file): array
    {
        $previous = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $environment;

        try {
            return require config_path("{$file}.php");
        } finally {
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $previous;
        }
    }
}

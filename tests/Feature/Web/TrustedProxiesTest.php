<?php

namespace Tests\Feature\Web;

use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    private const FORWARDED = [
        'REMOTE_ADDR' => '10.0.0.9',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'dailyspeak.example',
        'HTTP_X_FORWARDED_PORT' => '443',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
    ];

    protected function tearDown(): void
    {
        $this->setTrustedProxies(null);
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        $this->withServerVariables(self::FORWARDED)
            ->get('/feed')
            ->assertRedirect('http://localhost/login');
    }

    public function test_a_trusted_proxy_sets_the_scheme_and_host(): void
    {
        $this->setTrustedProxies('10.0.0.9');

        $this->withServerVariables(self::FORWARDED)
            ->get('/feed')
            ->assertRedirect('https://dailyspeak.example/login');
    }

    public function test_an_untrusted_peer_cannot_spoof_the_scheme(): void
    {
        $this->setTrustedProxies('10.0.0.9');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.50'] + self::FORWARDED)
            ->get('/feed')
            ->assertRedirect('http://localhost/login');
    }

    /**
     * TRUSTED_PROXIES is read once at boot, so the application is rebuilt with the new value.
     */
    private function setTrustedProxies(?string $proxies): void
    {
        if ($proxies === null) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

            return;
        }

        putenv("TRUSTED_PROXIES={$proxies}");
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $proxies;

        TrustProxies::flushState();
        $this->refreshApplication();
    }
}

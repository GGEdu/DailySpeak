<?php

namespace Tests\Feature\Web;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite as ViteInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_sends_the_hardening_headers(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'microphone=(self), camera=(), geolocation=()');
    }

    public function test_api_responses_send_the_headers_that_mean_something_for_json(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/user')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            // The page policy is for HTML: JSON gets no nonce-based script policy.
            ->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_api_errors_send_the_api_headers_too(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_scripts_need_the_vite_nonce_and_the_page_may_only_load_from_this_origin(): void
    {
        $response = $this->get('/login')->assertOk();
        $policy = $this->policy($response);

        $this->assertSame(["'self'"], $policy['default-src']);
        $this->assertSame(["'self'", "'nonce-".Vite::cspNonce()."'"], $policy['script-src']);
        $this->assertNotEmpty(Vite::cspNonce());
        // The ALTCHA widget's proof-of-work workers start from blob: URLs, and nothing else may run as a worker.
        $this->assertSame(["'self'", 'blob:'], $policy['worker-src']);
        $this->assertSame(["'self'", "'unsafe-inline'"], $policy['style-src']);
        $this->assertSame(["'self'", 'data:'], $policy['img-src']);
        $this->assertSame(["'self'", 'blob:'], $policy['media-src']);
        $this->assertSame(["'self'"], $policy['font-src']);
        $this->assertSame(["'none'"], $policy['object-src']);
        $this->assertSame(["'self'"], $policy['base-uri']);
        $this->assertSame(["'self'"], $policy['form-action']);
        $this->assertSame(["'none'"], $policy['frame-ancestors']);
    }

    public function test_the_socket_host_of_reverb_is_the_only_connection_besides_this_origin(): void
    {
        config([
            'reverb.client' => ['host' => null, 'port' => null, 'scheme' => null],
            'reverb.apps.apps.0.options.host' => 'ws.example.test',
            'reverb.apps.apps.0.options.port' => 443,
            'reverb.apps.apps.0.options.scheme' => null,
        ]);

        $policy = $this->policy($this->get('/login'));

        $this->assertSame(["'self'", 'ws://ws.example.test:443', 'wss://ws.example.test:443'], $policy['connect-src']);
    }

    public function test_behind_a_proxy_the_browser_address_is_allowed_not_the_internal_one(): void
    {
        // Production: the server publishes to Reverb on 127.0.0.1:8080, the browser connects to the public name.
        config([
            'reverb.apps.apps.0.options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http'],
            'reverb.client' => ['host' => 'app.example.test', 'port' => 443, 'scheme' => 'https'],
        ]);

        $policy = $this->policy($this->get('/login'));

        $this->assertSame(["'self'", 'wss://app.example.test:443'], $policy['connect-src']);
    }

    public function test_a_response_that_already_has_a_policy_keeps_it(): void
    {
        Route::middleware('web')->get('/probe/sandboxed', fn () => response('ok')->header('Content-Security-Policy', 'sandbox'));

        $this->get('/probe/sandboxed')
            ->assertHeader('Content-Security-Policy', 'sandbox')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_no_policy_is_sent_while_the_vite_dev_server_runs(): void
    {
        // The test stub of Vite ignores useHotFile(), so a Vite that reports the dev server is swapped in.
        $this->app->instance(ViteInstance::class, new class extends ViteInstance
        {
            public function isRunningHot()
            {
                return true;
            }
        });
        Vite::clearResolvedInstance();

        $response = (new SecurityHeaders)->handle(Request::create('/login'), fn () => response('ok'));

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    /**
     * @return array<string, list<string>>
     */
    private function policy(TestResponse $response): array
    {
        $policy = [];

        foreach (array_filter(array_map('trim', explode(';', (string) $response->headers->get('Content-Security-Policy')))) as $directive) {
            $tokens = preg_split('/\s+/', $directive);
            $name = array_shift($tokens);
            $policy[$name] = $tokens;
        }

        return $policy;
    }
}

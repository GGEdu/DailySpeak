<?php

namespace Tests\Feature\Security;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every route must demand a signed-in, verified user unless it is listed here with its reason. A new route
 * that forgets the middleware fails this test instead of going live open.
 */
class RouteAccessTest extends TestCase
{
    /** Open to guests: the landing page, sign-in and sign-up, and password reset. */
    private const GUEST_URIS = ['', 'login', 'register', 'forgot-password', 'reset-password', 'reset-password/{token}'];

    /**
     * Signed in, but deliberately not verified: an unverified user must still be able to log out, to verify
     * the address, and to get a copy of their data or erase the account (GDPR), since an address typed wrong at
     * sign-up must not trap the account. These must keep "auth".
     */
    private const UNVERIFIED_URIS = [
        'logout',
        'email/verify',
        'email/verify/{id}/{hash}',
        'email/verification-notification',
        'settings/export',
        'settings/account',
    ];

    /**
     * Framework routes that check access themselves: the health check, Sanctum's CSRF cookie, the local
     * file server (it refuses requests without a valid signature) and broadcasting auth (each channel's
     * policy decides who gets in).
     */
    private const FRAMEWORK_URIS = ['up', 'sanctum/csrf-cookie', 'storage/{path}', 'broadcasting/auth'];

    public function test_every_route_requires_a_signed_in_verified_user_unless_it_is_listed(): void
    {
        $unguarded = [];

        foreach (Router::getRoutes() as $route) {
            $uri = trim($route->uri(), '/');

            // Horizon guards its own routes; HorizonAccessTest covers them.
            if (Str::startsWith($uri, 'horizon') || $this->isListed($uri)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (! $this->hasAuth($middleware) || ! in_array('verified', $middleware, true)) {
                $unguarded[] = implode('|', $route->methods()).' /'.$uri;
            }
        }

        $this->assertSame([], $unguarded);
    }

    public function test_the_listed_routes_exist_and_the_unverified_ones_still_need_a_user(): void
    {
        foreach (array_merge(self::GUEST_URIS, self::UNVERIFIED_URIS, self::FRAMEWORK_URIS) as $uri) {
            $routes = $this->routesFor($uri);

            $this->assertNotEmpty($routes, "The list names /{$uri}, but no route has that URI.");
        }

        foreach (self::UNVERIFIED_URIS as $uri) {
            foreach ($this->routesFor($uri) as $route) {
                $this->assertTrue($this->hasAuth($route->gatherMiddleware()), "/{$uri} must still require a signed-in user.");
            }
        }
    }

    private function isListed(string $uri): bool
    {
        return in_array($uri, [...self::GUEST_URIS, ...self::UNVERIFIED_URIS, ...self::FRAMEWORK_URIS], true);
    }

    /**
     * @return list<Route>
     */
    private function routesFor(string $uri): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route) => trim($route->uri(), '/') === $uri,
        ));
    }

    /**
     * @param  list<string>  $middleware
     */
    private function hasAuth(array $middleware): bool
    {
        return collect($middleware)->contains(fn (string $name) => $name === 'auth' || Str::startsWith($name, 'auth:'));
    }
}

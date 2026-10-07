<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Voice turns a regular user may upload per minute (each one costs STT + LLM + TTS).
     */
    public const AUDIO_UPLOADS_PER_MINUTE = 20;

    /**
     * Word lookups and saves a user may make per minute (a lookup may cost an LLM call).
     */
    public const LOOKUPS_PER_MINUTE = 40;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->trustProxies();

        Gate::define('admin', fn (User $user) => $user->is_admin);

        RateLimiter::for('lookups', fn (Request $request) => Limit::perMinute(self::LOOKUPS_PER_MINUTE)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('debate-audio', function (Request $request) {
            return $request->user()?->is_admin
                ? Limit::none()
                : Limit::perMinute(self::AUDIO_UPLOADS_PER_MINUTE)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Behind a reverse proxy that terminates TLS, trust its X-Forwarded-* headers so URLs use
     * https and the host the browser asked for, and rate limits see each client's own IP.
     */
    private function trustProxies(): void
    {
        $proxies = trim((string) config('app.trusted_proxies'));

        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}

<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
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
        Gate::define('admin', fn (User $user) => $user->is_admin);

        RateLimiter::for('debate-audio', function (Request $request) {
            return $request->user()?->is_admin
                ? Limit::none()
                : Limit::perMinute(self::AUDIO_UPLOADS_PER_MINUTE)->by($request->user()?->id ?: $request->ip());
        });
    }
}

<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
     * Daily cap on the same, so one account cannot run up the AI bill.
     */
    public const LOOKUPS_PER_DAY = 500;

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
        $this->warnAboutInvalidDebateOptions();

        Gate::define('admin', fn (User $user) => $user->is_admin);

        RateLimiter::for('lookups', fn (Request $request) => [
            Limit::perMinute(self::LOOKUPS_PER_MINUTE)->by('minute:'.($request->user()?->id ?: $request->ip())),
            Limit::perDay(self::LOOKUPS_PER_DAY)->by('day:'.($request->user()?->id ?: $request->ip())),
        ]);

        RateLimiter::for('debate-audio', function (Request $request) {
            if ($request->user()?->is_admin) {
                return Limit::none();
            }

            $who = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(self::AUDIO_UPLOADS_PER_MINUTE)
                    ->by('minute:'.$who)
                    ->response(fn (Request $request, array $headers) => response()->json([
                        'message' => 'You are sending voice turns too fast. Wait a moment and try again.',
                    ], 429, $headers)),
                Limit::perDay((int) config('debate.audio.daily_turns'))
                    ->by('day:'.$who)
                    ->response(fn (Request $request, array $headers) => response()->json([
                        'message' => "You have reached today's limit of voice turns. Try again later.",
                    ], 429, $headers)),
            ];
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

    /**
     * An invalid DEBATE_LLM_OPTIONS is ignored (the tutor runs with the provider defaults); say so once per boot.
     */
    private function warnAboutInvalidDebateOptions(): void
    {
        if (config('debate.llm.options_valid') === false) {
            Log::warning('DEBATE_LLM_OPTIONS is not a valid JSON object, so it is ignored and the tutor uses the provider defaults.');
        }
    }
}

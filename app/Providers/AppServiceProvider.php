<?php

namespace App\Providers;

use App\Models\User;
use App\Services\News\DnsHostResolver;
use App\Services\News\HostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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
     * Sign-in attempts for one email from one address per minute, on top of the per-address limit.
     */
    public const LOGINS_PER_EMAIL_AND_ADDRESS_PER_MINUTE = 6;

    /**
     * Sign-in attempts for one email per hour from any address, so a list of addresses cannot be
     * tried against one account.
     */
    public const LOGINS_PER_EMAIL_PER_HOUR = 30;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->trustProxies();
        $this->warnAboutInvalidDebateOptions();

        Gate::define('admin', fn (User $user) => $user->is_admin);

        // At least 12 characters. The breach list is checked in production only: it calls an outside service.
        Password::defaults(fn () => app()->isProduction() ? Password::min(12)->uncompromised() : Password::min(12));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(self::LOGINS_PER_EMAIL_AND_ADDRESS_PER_MINUTE)
                ->by('login:'.$this->loginEmail($request).'|'.$request->ip()),
            Limit::perHour(self::LOGINS_PER_EMAIL_PER_HOUR)
                ->by('login-email:'.$this->loginEmail($request)),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perDay(max(1, (int) config('limits.registrations_per_ip_per_day')))
            ->by('register:'.$request->ip()));

        // One budget for the AI calls of all users together (routes add it as 'ai-budget'). Admins are exempt.
        RateLimiter::for('ai-budget', fn (Request $request) => $request->user()?->is_admin
            ? Limit::none()
            : Limit::perDay(max(1, (int) config('limits.ai_daily_global')))
                ->by('all')
                ->response(function (Request $request, array $headers) {
                    if (Cache::add('ai-budget-exhausted:'.now()->toDateString(), true, now()->endOfDay())) {
                        Log::critical('The daily AI call budget is used up. AI routes answer 429 until it resets.');
                    }

                    return response()->json([
                        'message' => 'The daily AI budget is used up. Try again tomorrow.',
                    ], 429, $headers);
                }));

        RateLimiter::for('lookups', function (Request $request) {
            $who = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(self::LOOKUPS_PER_MINUTE)->by('minute:'.$who),
                Limit::perDay(self::LOOKUPS_PER_DAY)->by('day:'.$who),
            ];
        });

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
     * The email typed on the sign-in form, normalised so that case and spaces cannot dodge the limit.
     */
    private function loginEmail(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? Str::lower(trim($email)) : '';
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
     * An invalid DEBATE_LLM_OPTIONS is ignored (the tutor runs with the provider defaults); say so once an hour.
     * The flag is in the cache, so the warning is not repeated on every request.
     */
    private function warnAboutInvalidDebateOptions(): void
    {
        if (config('debate.llm.options_valid') === false && Cache::add('debate-llm-options-warning', true, now()->addHour())) {
            Log::warning('DEBATE_LLM_OPTIONS is not a valid JSON object, so it is ignored and the tutor uses the provider defaults.');
        }
    }
}

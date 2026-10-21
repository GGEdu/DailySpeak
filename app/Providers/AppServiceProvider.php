<?php

namespace App\Providers;

use App\Jobs\ProcessVoiceDebate;
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
use ReflectionProperty;

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
     * Debate reports a user may ask for per minute: each one is a paid evaluation.
     */
    public const DEBATE_FINISHES_PER_MINUTE = 5;

    /**
     * Sign-in attempts for one email from one address per minute, on top of the per-address limit.
     */
    public const LOGINS_PER_EMAIL_AND_ADDRESS_PER_MINUTE = 6;

    /**
     * Failed sign-ins for one email from one address per hour. Only failures count, and the address is part
     * of the key: counting every email from every address would let anyone who knows the email lock its owner out.
     */
    public const FAILED_LOGINS_PER_EMAIL_AND_ADDRESS_PER_HOUR = 30;

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
        $this->warnAboutVoiceTurnBudget();

        Gate::define('admin', fn (User $user) => $user->is_admin);

        // At least 12 characters. The breach list is checked in production only: it calls an outside service.
        Password::defaults(fn () => app()->isProduction() ? Password::min(12)->uncompromised() : Password::min(12));

        // Every attempt counts here; the failures are counted by the login controller, per email and address.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(self::LOGINS_PER_EMAIL_AND_ADDRESS_PER_MINUTE)
            ->by('login:'.$this->loginEmail($request).'|'.$request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perDay(max(1, (int) config('limits.registrations_per_ip_per_day')))
            ->by('register:'.$request->ip()));

        RateLimiter::for('lookups', function (Request $request) {
            $who = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(self::LOOKUPS_PER_MINUTE)->by('minute:'.$who),
                Limit::perDay(self::LOOKUPS_PER_DAY)->by('day:'.$who),
            ];
        });

        RateLimiter::for('debate-finish', fn (Request $request) => Limit::perMinute(self::DEBATE_FINISHES_PER_MINUTE)
            ->by('finish:'.($request->user()?->id ?: $request->ip())));

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

    /**
     * A voice turn runs STT, the tutor, TTS, the backup voice, two broadcasts and the job's overhead inside
     * ProcessVoiceDebate. When the request timeouts add up to the job's timeout, the job is killed mid-turn, so say so
     * once an hour. The same sum is documented in config/debate.php.
     */
    private function warnAboutVoiceTurnBudget(): void
    {
        $budget = (float) config('debate.stt.timeout')
            + (float) config('debate.llm.timeout')
            + (float) config('debate.tts.timeout')
            + (float) config('debate.tts.fallback.timeout')
            + 2 * (float) config('broadcasting.connections.reverb.client_options.timeout')
            + ProcessVoiceDebate::OVERHEAD_SECONDS;

        // The timeout is an instance property with a default, so it is read without building a job.
        $jobTimeout = (new ReflectionProperty(ProcessVoiceDebate::class, 'timeout'))->getDefaultValue();

        if ($budget >= $jobTimeout && Cache::add('voice-turn-budget-warning', true, now()->addHour())) {
            Log::warning("A voice turn may need {$budget} seconds, but ProcessVoiceDebate stops at {$jobTimeout}. Lower the DEBATE_*_TIMEOUT values or the job's timeout is exceeded mid-turn.");
        }
    }
}

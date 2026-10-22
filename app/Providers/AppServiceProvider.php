<?php

namespace App\Providers;

use App\Jobs\ProcessVoiceDebate;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\Limits;
use App\Services\News\DnsHostResolver;
use App\Services\News\HostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use ReflectionProperty;

class AppServiceProvider extends ServiceProvider
{
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

        // One per application: it remembers the config values the panel overrides (see AiSettings::apply()).
        $this->app->singleton(AiSettings::class);
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

        // The AI settings an admin saved are read before each job and each console command too. A worker such as Horizon
        // runs for days, so a change on the panel reaches it at its next job, without a restart. Requests: see ApplyAiSettings.
        Queue::before(fn () => $this->app->make(AiSettings::class)->apply());
        Event::listen(CommandStarting::class, fn () => $this->app->make(AiSettings::class)->apply());

        // At least 12 characters. The breach list is checked in production only: it calls an outside service.
        Password::defaults(fn () => app()->isProduction() ? Password::min(12)->uncompromised() : Password::min(12));

        // Every attempt counts here; the failures are counted by the login controller, per email and address.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(self::LOGINS_PER_EMAIL_AND_ADDRESS_PER_MINUTE)
            ->by('login:'.$this->loginEmail($request).'|'.$request->ip()));

        // The limits an admin can change (App\Services\Limits) are read per request, so a change applies at once.
        $limits = $this->app->make(Limits::class);

        RateLimiter::for('register', fn (Request $request) => Limit::perDay($limits->value('registrations_per_ip_per_day'))
            ->by('register:'.$request->ip()));

        RateLimiter::for('lookups', function (Request $request) use ($limits) {
            $who = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute($limits->value('lookups_per_minute'))->by('minute:'.$who),
                Limit::perDay($limits->value('lookups_per_day'))->by('day:'.$who),
            ];
        });

        RateLimiter::for('debate-finish', fn (Request $request) => Limit::perMinute($limits->value('debate_finishes_per_minute'))
            ->by('finish:'.($request->user()?->id ?: $request->ip())));

        // Retries of a guided debate's brief: each one is a paid call.
        RateLimiter::for('debate-brief', fn (Request $request) => Limit::perMinute(5)
            ->by('brief:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('debate-audio', function (Request $request) use ($limits) {
            if ($request->user()?->is_admin) {
                return Limit::none();
            }

            $who = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute($limits->value('voice_turns_per_minute'))
                    ->by('minute:'.$who)
                    ->response(fn (Request $request, array $headers) => response()->json([
                        'message' => 'You are sending voice turns too fast. Wait a moment and try again.',
                    ], 429, $headers)),
                Limit::perDay($limits->value('voice_turns_per_day'))
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

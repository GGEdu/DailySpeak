<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The per-user and shared limits an admin can change on /admin/limits. A value an admin has saved (app_settings)
 * wins; otherwise the default in config/ applies, and that default is what the environment variables set. Saved
 * values are cached until the next change. Sign-in and email-verification limits are security limits: they stay
 * in code and are not managed here.
 */
class Limits
{
    private const CACHE_KEY = 'app-settings:limits';

    /**
     * Every managed limit: its label, the config key of its default, and the largest value an admin may set.
     */
    private const LIMITS = [
        'ai_daily_global' => [
            'label' => 'AI calls per day, all users together',
            'default' => 'limits.ai_daily_global',
            'max' => 100000,
        ],
        'registrations_per_ip_per_day' => [
            'label' => 'Accounts one address may open per day',
            'default' => 'limits.registrations_per_ip_per_day',
            'max' => 1000,
        ],
        'voice_turns_per_minute' => [
            'label' => 'Voice turns per user, per minute',
            'default' => 'limits.voice_turns_per_minute',
            'max' => 1000,
        ],
        'voice_turns_per_day' => [
            'label' => 'Voice turns per user, per day',
            'default' => 'debate.audio.daily_turns',
            'max' => 100000,
        ],
        'lookups_per_minute' => [
            'label' => 'Translations and saved words per user, per minute',
            'default' => 'limits.lookups_per_minute',
            'max' => 1000,
        ],
        'lookups_per_day' => [
            'label' => 'Translations and saved words per user, per day',
            'default' => 'limits.lookups_per_day',
            'max' => 100000,
        ],
        'feed_searches_per_minute' => [
            'label' => 'Feed searches per user, per minute',
            'default' => 'limits.feed_searches_per_minute',
            'max' => 1000,
        ],
        'debate_finishes_per_minute' => [
            'label' => 'Debate reports per user, per minute',
            'default' => 'limits.debate_finishes_per_minute',
            'max' => 1000,
        ],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::LIMITS);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::LIMITS);
    }

    public static function max(string $key): int
    {
        return self::definition($key)['max'];
    }

    /**
     * The value in force now: the saved one, or the default.
     */
    public function value(string $key): int
    {
        $saved = $this->saved();

        return array_key_exists($key, $saved) ? $saved[$key] : $this->default($key);
    }

    /**
     * The value in config/, which applies until an admin saves another one.
     */
    public function default(string $key): int
    {
        return max(1, (int) config(self::definition($key)['default']));
    }

    /**
     * Every limit with its value, its default and whether an admin has changed it, for the admin page.
     *
     * @return list<array{key: string, label: string, value: int, default: int, customised: bool, max: int}>
     */
    public function overview(): array
    {
        $saved = $this->saved();

        return collect(self::LIMITS)
            ->map(fn (array $definition, string $key) => [
                'key' => $key,
                'label' => $definition['label'],
                'value' => $this->value($key),
                'default' => $this->default($key),
                'customised' => array_key_exists($key, $saved),
                'max' => $definition['max'],
            ])
            ->values()
            ->all();
    }

    /**
     * Set a limit. It applies from the next request: the cache is forgotten here, not on a timer.
     */
    public function save(string $key, int $value, User $admin): void
    {
        $max = self::max($key);

        if ($value < 1 || $value > $max) {
            throw new InvalidArgumentException("The limit [{$key}] must be between 1 and {$max}.");
        }

        $this->change($key, $admin, fn () => AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $admin->id],
        ));
    }

    /**
     * Go back to the default in config/ by removing the saved value.
     */
    public function resetToDefault(string $key, User $admin): void
    {
        $this->change($key, $admin, fn () => AppSetting::query()->whereKey($key)->delete());
    }

    /**
     * Write the change, forget the cached values and log the before and after values. The log names the admin by
     * id and carries nothing else about them.
     */
    private function change(string $key, User $admin, Closure $write): void
    {
        $before = $this->value($key);

        $write();
        Cache::forget(self::CACHE_KEY);

        Log::info('Limit changed by an admin', [
            'admin_id' => $admin->id,
            'key' => $key,
            'before' => $before,
            'after' => $this->value($key),
        ]);
    }

    /**
     * The saved values, by key. Read from the database once and then from the cache.
     *
     * @return array<string, int>
     */
    private function saved(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => AppSetting::query()
                ->pluck('value', 'key')
                ->map(fn (mixed $value) => (int) $value)
                ->all());
        } catch (QueryException) {
            // A deploy serves the new code a moment before it migrates, and the database can be briefly away: the
            // configured defaults apply meanwhile, and nothing is cached, so the saved values are read once it is back.
            return [];
        }
    }

    /**
     * @return array{label: string, default: string, max: int}
     */
    private static function definition(string $key): array
    {
        return self::LIMITS[$key] ?? throw new InvalidArgumentException("Unknown limit [{$key}].");
    }
}

<?php

namespace App\Services;

use App\Models\AppOption;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The text settings an admin sets on the panel, by key (app_options): the registration switch, the AI connection and
 * models, and the privacy notice. A key without a value uses its default in config/ or .env, so a blank field means
 * "as configured". Saved values are cached until the next change.
 */
class AppOptions
{
    private const CACHE_KEY = 'app-options';

    /**
     * The saved value, or null when there is none or it was cleared. Secrets come back encrypted (see AiSettings).
     */
    public function get(string $key): ?string
    {
        return $this->saved()[$key] ?? null;
    }

    /**
     * Save a value, or clear it with null or an empty string. It applies from the next request, job or command: the
     * cache is forgotten here, not on a timer. The log names the key and the admin, never the value.
     */
    public function put(string $key, ?string $value, ?User $admin): void
    {
        $value = $value === '' ? null : $value;

        AppOption::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $admin?->id],
        );
        Cache::forget(self::CACHE_KEY);

        Log::info('Panel setting changed', [
            'admin_id' => $admin?->id,
            'key' => $key,
            'cleared' => $value === null,
        ]);
    }

    /**
     * The saved values, by key. Read from the database once and then from the cache.
     *
     * @return array<string, ?string>
     */
    private function saved(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => AppOption::query()
                ->pluck('value', 'key')
                ->all());
        } catch (QueryException) {
            // As in App\Services\Limits: a deploy serves the new code before it migrates, and the database can be
            // briefly away. The defaults apply meanwhile, and nothing is cached, so the saved values are read once back.
            return [];
        }
    }
}

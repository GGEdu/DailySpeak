<?php

namespace App\Services\Guided;

use App\Enums\EnglishLevel;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Which English levels get the guided debate flow. An admin sets each level on /admin/limits (app_settings
 * guided_debate.B1, .B2, .C1: 1 or 0). Until one is saved, the level's default applies: config debate.guided.levels.
 * A database without the table (a deploy that serves the new code before it migrates) uses the defaults too.
 */
class GuidedSettings
{
    private const KEY_PREFIX = 'guided_debate.';

    /**
     * Whether debates started now at this level run the guided flow.
     */
    public function enabled(EnglishLevel $level): bool
    {
        $saved = $this->saved();

        return array_key_exists(self::key($level), $saved)
            ? $saved[self::key($level)] === 1
            : $this->default($level);
    }

    /**
     * The level's switch as the admin page shows it: its state, its default and whether an admin changed it.
     *
     * @return list<array{level: string, enabled: bool, default: bool, customised: bool}>
     */
    public function overview(): array
    {
        $saved = $this->saved();

        return array_map(fn (EnglishLevel $level) => [
            'level' => $level->value,
            'enabled' => array_key_exists(self::key($level), $saved)
                ? $saved[self::key($level)] === 1
                : $this->default($level),
            'default' => $this->default($level),
            'customised' => array_key_exists(self::key($level), $saved),
        ], EnglishLevel::cases());
    }

    /**
     * Turn the level's guided flow on or off. Debates already started keep their flow.
     */
    public function save(EnglishLevel $level, bool $enabled, User $admin): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => self::key($level)],
            ['value' => $enabled ? 1 : 0, 'updated_by' => $admin->id],
        );

        Log::info('Guided debate changed by an admin', [
            'admin_id' => $admin->id,
            'level' => $level->value,
            'enabled' => $enabled,
        ]);
    }

    /**
     * The level's default, from config/debate.php.
     */
    public function default(EnglishLevel $level): bool
    {
        return in_array($level->value, config('debate.guided.levels'), true);
    }

    /**
     * The saved switches, by key, as 1 or 0. Empty when the table is missing.
     *
     * @return array<string, int>
     */
    private function saved(): array
    {
        if (! Schema::hasTable('app_settings')) {
            return [];
        }

        return AppSetting::query()
            ->whereIn('key', array_map(fn (EnglishLevel $level) => self::key($level), EnglishLevel::cases()))
            ->pluck('value', 'key')
            ->map(fn (mixed $value) => (int) $value)
            ->all();
    }

    private static function key(EnglishLevel $level): string
    {
        return self::KEY_PREFIX.$level->value;
    }
}

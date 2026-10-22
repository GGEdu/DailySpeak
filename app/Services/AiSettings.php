<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Ai\AiManager;

/**
 * The AI connection and the model per task, as an admin sets them on /admin/ai. A saved value replaces the one in
 * config/ and .env; a blank one leaves the configured value in force. apply() puts the saved values into config/
 * before every request, every queued job and every console command, so Horizon, which runs for days, sees a change at
 * its next job without a restart.
 *
 * The address and the key go to the two providers the app uses: openai-compatible for text and openai for voice. The
 * key is stored encrypted and is never shown again, only its last four characters.
 */
class AiSettings
{
    public const TEXT_PROVIDER = 'openai-compatible';

    public const VOICE_PROVIDER = 'openai';

    public const BASE_URL = 'ai.base_url';

    public const API_KEY = 'ai.api_key';

    /**
     * The model fields of the panel, and the config keys each one sets. "conversation" is the tutor, the word
     * translations and the review of a learner's turn; "writing" is the news summaries, the debate brief and the report.
     */
    public const MODELS = [
        'conversation' => ['debate.llm.model', 'lookup.ai.model', 'debate.guided.review.model'],
        'writing' => ['news.ai.model', 'debate.guided.brief.model', 'debate.evaluator.model'],
        'stt' => ['debate.stt.model'],
        'tts' => ['debate.tts.model'],
        'tts_voice' => ['debate.tts.voice'],
        'tts_fallback_model' => ['debate.tts.fallback.model'],
        'tts_fallback_voice' => ['debate.tts.fallback.voice'],
    ];

    /**
     * The config values before this process changed any of them: what a blank field goes back to.
     *
     * @var array<string, mixed>
     */
    private array $defaults = [];

    private bool $remembered = false;

    /**
     * The config keys this process has overridden, so a field left blank can put its default back.
     *
     * @var array<string, true>
     */
    private array $overridden = [];

    public function __construct(
        private AppOptions $options,
        private AiManager $ai,
    ) {}

    /**
     * Put the saved values into config/. Keys without a saved value are left alone, unless an earlier call overrode
     * them: then they go back to their default. A provider built with the old address or key is built again.
     */
    public function apply(): void
    {
        $this->remember();
        $before = $this->connection();

        foreach ($this->desired() as $path => $value) {
            if ($value !== null) {
                config([$path => $value]);
                $this->overridden[$path] = true;
            } elseif (isset($this->overridden[$path])) {
                config([$path => $this->defaults[$path]]);
                unset($this->overridden[$path]);
            }
        }

        if ($this->connection() !== $before) {
            $this->ai->forgetInstance([self::TEXT_PROVIDER, self::VOICE_PROVIDER]);
        }
    }

    /**
     * The panel's view of the settings: each field with the value saved on the panel and the default from config/ or
     * .env. The key itself is never returned: only where it comes from and, for a key saved on the panel, its last four
     * characters.
     *
     * @return array{base_url: array{value: ?string, default: ?string}, api_key: array{set: bool, last4: ?string, source: ?string}, models: array<string, array{value: ?string, default: ?string}>}
     */
    public function settings(): array
    {
        $this->remember();

        $panelKey = $this->apiKey();
        $envKey = filled($this->default('ai.providers.'.self::TEXT_PROVIDER.'.key'))
            || filled($this->default('ai.providers.'.self::VOICE_PROVIDER.'.key'));

        return [
            'base_url' => [
                'value' => $this->options->get(self::BASE_URL),
                'default' => $this->default('ai.providers.'.self::TEXT_PROVIDER.'.url'),
            ],
            'api_key' => [
                'set' => $panelKey !== null || $envKey,
                'last4' => $panelKey === null ? null : substr($panelKey, -4),
                'source' => match (true) {
                    $panelKey !== null => 'admin',
                    $envKey => 'env',
                    default => null,
                },
            ],
            'models' => collect(self::MODELS)
                ->map(fn (array $paths, string $field) => [
                    'value' => $this->options->get($this->modelKey($field)),
                    'default' => $this->default($paths[0]),
                ])
                ->all(),
        ];
    }

    /**
     * Whether the AI service has an address and a key in use, from the panel or from the configuration.
     */
    public function configured(): bool
    {
        return filled(config('ai.providers.'.self::TEXT_PROVIDER.'.url'))
            && filled(config('ai.providers.'.self::TEXT_PROVIDER.'.key'));
    }

    /**
     * The host of the address in use, without its path or any credentials; null when there is none.
     */
    public function host(): ?string
    {
        $url = config('ai.providers.'.self::TEXT_PROVIDER.'.url');

        return filled($url) ? (parse_url((string) $url, PHP_URL_HOST) ?: null) : null;
    }

    public function setBaseUrl(?string $url, ?User $admin): void
    {
        $this->options->put(self::BASE_URL, $url, $admin);
    }

    public function setModel(string $field, ?string $model, ?User $admin): void
    {
        if (! array_key_exists($field, self::MODELS)) {
            throw new InvalidArgumentException("Unknown model field [{$field}].");
        }

        $this->options->put($this->modelKey($field), $model, $admin);
    }

    /**
     * Save the key encrypted. The plain key is not kept anywhere else.
     */
    public function setApiKey(string $key, ?User $admin): void
    {
        $this->options->put(self::API_KEY, Crypt::encryptString($key), $admin);
    }

    public function forgetApiKey(?User $admin): void
    {
        $this->options->put(self::API_KEY, null, $admin);
    }

    /**
     * The config values the saved settings set, by config key. A null means nothing is saved for it.
     *
     * @return array<string, ?string>
     */
    private function desired(): array
    {
        $url = $this->options->get(self::BASE_URL);
        $key = $this->apiKey();

        $values = [];

        foreach ([self::TEXT_PROVIDER, self::VOICE_PROVIDER] as $provider) {
            $values["ai.providers.{$provider}.url"] = $url;
            $values["ai.providers.{$provider}.key"] = $key;
        }

        foreach (self::MODELS as $field => $paths) {
            $model = $this->options->get($this->modelKey($field));

            foreach ($paths as $path) {
                $values[$path] = $model;
            }
        }

        return $values;
    }

    /**
     * The key saved on the panel, decrypted. Null when none is saved or it cannot be read, for instance after APP_KEY
     * changed: the configured key applies then, and saving the key again fixes it.
     */
    private function apiKey(): ?string
    {
        $stored = $this->options->get(self::API_KEY);

        if ($stored === null) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            if (Cache::add('ai-key-unreadable-warning', true, now()->addHour())) {
                Log::warning('The AI key saved on the admin panel cannot be decrypted, so the configured key applies. Save it again.');
            }

            return null;
        }
    }

    /**
     * Records the config values before the first override in this process. Later calls leave them as they are.
     */
    private function remember(): void
    {
        if ($this->remembered) {
            return;
        }

        foreach (self::paths() as $path) {
            $this->defaults[$path] = config($path);
        }

        $this->remembered = true;
    }

    private function default(string $path): mixed
    {
        $this->remember();

        return $this->defaults[$path] ?? null;
    }

    /**
     * The address and key of both providers, as the providers see them now.
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function connection(): array
    {
        return array_map(
            fn (string $provider) => [config("ai.providers.{$provider}.url"), config("ai.providers.{$provider}.key")],
            [self::TEXT_PROVIDER, self::VOICE_PROVIDER],
        );
    }

    /**
     * Every config key the settings can set.
     *
     * @return list<string>
     */
    private static function paths(): array
    {
        $paths = [];

        foreach ([self::TEXT_PROVIDER, self::VOICE_PROVIDER] as $provider) {
            $paths[] = "ai.providers.{$provider}.url";
            $paths[] = "ai.providers.{$provider}.key";
        }

        return [...$paths, ...array_merge(...array_values(self::MODELS))];
    }

    private function modelKey(string $field): string
    {
        return "ai.models.{$field}";
    }
}

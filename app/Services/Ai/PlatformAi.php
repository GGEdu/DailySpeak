<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\AppOptions;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The platform's own AI connection, as an admin sets it on /admin/ai (app_options). It replaces the old AiSettings,
 * which wrote these values into config/ for the whole process: here they are read per call. connection() returns the
 * connection of one task, with the saved address, key and models if there are any, and the configured ones otherwise.
 *
 * The address and the key go to the two providers the platform uses: openai-compatible for text and openai for voice.
 * The key is stored encrypted and is never shown again, only its last four characters.
 */
class PlatformAi
{
    public const TEXT_PROVIDER = 'openai-compatible';

    public const VOICE_PROVIDER = 'openai';

    public const BASE_URL = 'ai.base_url';

    public const API_KEY = 'ai.api_key';

    /**
     * The fields the panel sets, and the config key each one falls back to. Models are keyed by the task they serve
     * ("writing" is the news summaries, the debate brief and the report's writing); "tts_voice" is the voice.
     */
    public const FIELDS = [
        'conversation' => 'debate.llm.model',
        'writing' => 'news.ai.model',
        'stt' => 'debate.stt.model',
        'tts' => 'debate.tts.model',
        'tts_voice' => 'debate.tts.voice',
    ];

    /**
     * The config key naming the platform's provider for each task.
     */
    public const PROVIDER_FOR = [
        'conversation' => 'debate.llm.provider',
        'writing' => 'news.ai.provider',
        'stt' => 'debate.stt.provider',
        'tts' => 'debate.tts.provider',
    ];

    public function __construct(private AppOptions $options) {}

    /**
     * The panel's view of the settings: each field with the value saved on the panel and the default from config/ or
     * .env. The key itself is never returned: only where it comes from and, for a key saved on the panel, its last four
     * characters.
     *
     * @return array{base_url: array{value: ?string, default: ?string}, api_key: array{set: bool, last4: ?string, source: ?string}, models: array<string, array{value: ?string, default: ?string}>}
     */
    public function settings(): array
    {
        $panelKey = $this->apiKey();
        $envKey = filled(config('ai.providers.'.self::TEXT_PROVIDER.'.key'))
            || filled(config('ai.providers.'.self::VOICE_PROVIDER.'.key'));

        return [
            'base_url' => [
                'value' => $this->options->get(self::BASE_URL),
                'default' => config('ai.providers.'.self::TEXT_PROVIDER.'.url'),
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
            'models' => collect(self::FIELDS)
                ->map(fn (string $path, string $field) => [
                    'value' => $this->options->get($this->optionKey($field)),
                    'default' => config($path),
                ])
                ->all(),
        ];
    }

    /**
     * Whether the platform's text connection has an address and a key, from the panel or from the configuration.
     */
    public function configured(): bool
    {
        ['url' => $url, 'key' => $key] = $this->textAddress();

        return filled($url) && filled($key);
    }

    /**
     * The host of the text address in use, without its path or any credentials; null when there is none.
     */
    public function host(): ?string
    {
        $url = $this->textAddress()['url'];

        return filled($url) ? (parse_url((string) $url, PHP_URL_HOST) ?: null) : null;
    }

    public function setBaseUrl(?string $url, ?User $admin): void
    {
        $this->options->put(self::BASE_URL, $url, $admin);
    }

    public function setModel(string $field, ?string $model, ?User $admin): void
    {
        if (! array_key_exists($field, self::FIELDS)) {
            throw new InvalidArgumentException("Unknown model field [{$field}].");
        }

        $this->options->put($this->optionKey($field), $model, $admin);
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
     * The platform's connection for one task. The provider is the configured one for the task unless $provider names
     * another (the brief's own). The panel's address and key apply when the provider is one the panel sets; the model
     * and the voice are the panel's, else the configured ones. A model passed in wins over both.
     *
     * Nothing is written to config/: the connection is built where the call runs (AiConnection::providerName()).
     */
    public function connection(string $task, ?string $provider = null, ?string $model = null): AiConnection
    {
        if (! isset(self::PROVIDER_FOR[$task])) {
            throw new InvalidArgumentException("Unknown AI task [{$task}].");
        }

        // A provider of its own never inherits the platform's model for the task: its model is the one passed, if any.
        $explicit = $provider !== null;
        $name = $provider ?? config(self::PROVIDER_FOR[$task]);
        $base = (array) config('ai.providers.'.$name, []);
        $panel = in_array($name, [self::TEXT_PROVIDER, self::VOICE_PROVIDER], true);

        $address = $panel ? $this->textAddress($name) : ['url' => null, 'key' => null];

        $config = ['driver' => $base['driver'] ?? $name, ...$base];
        $config['key'] = $address['key'] ?? ($base['key'] ?? null);

        if (($address['url'] ?? null) !== null) {
            $config['url'] = $address['url'];
        }

        $voice = $task === 'tts' ? ($this->options->get($this->optionKey('tts_voice')) ?? config(self::FIELDS['tts_voice'])) : null;

        return new AiConnection(
            task: $task,
            paidBy: 'platform',
            provider: $name,
            model: $model ?? ($explicit ? null : $this->options->get($this->optionKey($task)) ?? config(self::FIELDS[$task])),
            voice: $voice,
            config: $config,
        );
    }

    /**
     * The address and key of the platform's text connection, as the panel or the configuration sets them.
     *
     * @return array{url: ?string, key: ?string}
     */
    public function textAddress(string $provider = self::TEXT_PROVIDER): array
    {
        $panel = in_array($provider, [self::TEXT_PROVIDER, self::VOICE_PROVIDER], true);

        return [
            'url' => ($panel ? $this->options->get(self::BASE_URL) : null) ?? config('ai.providers.'.$provider.'.url'),
            'key' => ($panel ? $this->apiKey() : null) ?? config('ai.providers.'.$provider.'.key'),
        ];
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

    private function optionKey(string $field): string
    {
        return "ai.models.{$field}";
    }
}

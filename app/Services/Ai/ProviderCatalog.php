<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/**
 * The providers a user can connect with their own key: a closed list. Each one has the driver of Laravel AI SDK that
 * serves it, the one address the list fixes (HTTPS; a user never types an address), how the key is sent for the test,
 * and the capabilities its driver implements, checked by tests against the driver classes (ProviderCatalogTest).
 *
 * Suggestions are starting points for the model of each task: they are not guaranteed to exist for every account, so
 * the user can always type another one.
 */
final class ProviderCatalog
{
    public const TEXT = 'text';

    public const STT = 'stt';

    public const TTS = 'tts';

    /**
     * Which capability each task needs from its provider.
     */
    public const TASK_CAPABILITY = [
        'conversation' => self::TEXT,
        'writing' => self::TEXT,
        'stt' => self::STT,
        'tts' => self::TTS,
    ];

    /**
     * How the key travels in the test request: "bearer", "x-api-key" (Anthropic) or "x-goog-api-key" (Google).
     */
    private const PROVIDERS = [
        'openai' => [
            'label' => 'OpenAI',
            'driver' => 'openai',
            'url' => 'https://api.openai.com/v1',
            'auth' => 'bearer',
            'check' => '/models',
            'capabilities' => [self::TEXT, self::STT, self::TTS],
            'suggestions' => [
                'conversation' => 'gpt-4o-mini', 'writing' => 'gpt-4o', 'stt' => 'whisper-1',
                'tts' => 'gpt-4o-mini-tts', 'voice' => 'alloy',
            ],
        ],
        'groq' => [
            'label' => 'Groq',
            'driver' => 'groq',
            'url' => 'https://api.groq.com/openai/v1',
            'auth' => 'bearer',
            'check' => '/models',
            'capabilities' => [self::TEXT, self::STT],
            'suggestions' => [
                'conversation' => 'llama-3.3-70b-versatile', 'writing' => 'llama-3.3-70b-versatile',
                'stt' => 'whisper-large-v3-turbo', 'tts' => null, 'voice' => null,
            ],
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'driver' => 'openrouter',
            'url' => 'https://openrouter.ai/api/v1',
            'auth' => 'bearer',
            // The model list of OpenRouter is public, so the test asks for the key's own information instead.
            'check' => '/auth/key',
            'capabilities' => [self::TEXT, self::STT, self::TTS],
            'suggestions' => [
                'conversation' => 'openai/gpt-4o-mini', 'writing' => 'openai/gpt-4o', 'stt' => null,
                'tts' => null, 'voice' => null,
            ],
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'driver' => 'gemini',
            'url' => 'https://generativelanguage.googleapis.com/v1beta',
            'auth' => 'x-goog-api-key',
            'check' => '/models',
            'capabilities' => [self::TEXT, self::STT, self::TTS],
            'suggestions' => [
                'conversation' => 'gemini-2.5-flash', 'writing' => 'gemini-2.5-pro', 'stt' => 'gemini-2.5-flash',
                'tts' => 'gemini-2.5-flash-preview-tts', 'voice' => 'Kore',
            ],
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'driver' => 'anthropic',
            'url' => 'https://api.anthropic.com/v1',
            'auth' => 'x-api-key',
            'check' => '/models',
            'capabilities' => [self::TEXT],
            'suggestions' => [
                'conversation' => 'claude-haiku-4-5', 'writing' => 'claude-sonnet-4-5', 'stt' => null,
                'tts' => null, 'voice' => null,
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'driver' => 'deepseek',
            'url' => 'https://api.deepseek.com/v1',
            'auth' => 'bearer',
            'check' => '/models',
            'capabilities' => [self::TEXT],
            'suggestions' => [
                'conversation' => 'deepseek-chat', 'writing' => 'deepseek-chat', 'stt' => null,
                'tts' => null, 'voice' => null,
            ],
        ],
        'mistral' => [
            'label' => 'Mistral',
            'driver' => 'mistral',
            'url' => 'https://api.mistral.ai/v1',
            'auth' => 'bearer',
            'check' => '/models',
            'capabilities' => [self::TEXT, self::STT, self::TTS],
            'suggestions' => [
                'conversation' => 'mistral-small-latest', 'writing' => 'mistral-large-latest',
                'stt' => 'voxtral-mini-latest', 'tts' => null, 'voice' => null,
            ],
        ],
    ];

    /**
     * The ids of the providers, in the order the page lists them.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function has(string $id): bool
    {
        return array_key_exists($id, self::PROVIDERS);
    }

    /**
     * @throws InvalidArgumentException for an id that is not on the list
     */
    public static function get(string $id): array
    {
        if (! self::has($id)) {
            throw new InvalidArgumentException("Unknown AI provider [{$id}].");
        }

        return self::PROVIDERS[$id];
    }

    public static function label(string $id): string
    {
        return self::get($id)['label'];
    }

    public static function driver(string $id): string
    {
        return self::get($id)['driver'];
    }

    /**
     * The one address of the provider. Users cannot change it.
     */
    public static function url(string $id): string
    {
        return self::get($id)['url'];
    }

    /**
     * @return list<string>
     */
    public static function capabilities(string $id): array
    {
        return self::get($id)['capabilities'];
    }

    public static function supports(string $id, string $capability): bool
    {
        return self::has($id) && in_array($capability, self::get($id)['capabilities'], true);
    }

    /**
     * Whether the provider can serve a task (conversation, writing, stt or tts).
     */
    public static function supportsTask(string $id, string $task): bool
    {
        return isset(self::TASK_CAPABILITY[$task]) && self::supports($id, self::TASK_CAPABILITY[$task]);
    }

    /**
     * The suggested model (or voice) per task, or null when there is none to suggest.
     *
     * @return array{conversation: ?string, writing: ?string, stt: ?string, tts: ?string, voice: ?string}
     */
    public static function suggestions(string $id): array
    {
        return self::get($id)['suggestions'];
    }

    /**
     * How the key is sent in the test request, and the path of that request under the provider's address.
     *
     * @return array{auth: string, check: string}
     */
    public static function check(string $id): array
    {
        $provider = self::get($id);

        return ['auth' => $provider['auth'], 'check' => $provider['check']];
    }
}

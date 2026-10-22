<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\ProviderCatalog;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\DeepSeekProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\GroqProvider;
use Laravel\Ai\Providers\MistralProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\OpenRouterProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The closed list is checked against the drivers of Laravel AI SDK: a capability the list declares must be one the
 * driver implements, and every one it implements must be declared. No network: only the classes are read.
 */
class ProviderCatalogTest extends TestCase
{
    /**
     * @return array<string, array{string, class-string}>
     */
    public static function drivers(): array
    {
        return [
            'openai' => ['openai', OpenAiProvider::class],
            'groq' => ['groq', GroqProvider::class],
            'openrouter' => ['openrouter', OpenRouterProvider::class],
            'gemini' => ['gemini', GeminiProvider::class],
            'anthropic' => ['anthropic', AnthropicProvider::class],
            'deepseek' => ['deepseek', DeepSeekProvider::class],
            'mistral' => ['mistral', MistralProvider::class],
        ];
    }

    public function test_the_list_is_exactly_the_providers_checked_here(): void
    {
        $this->assertSame(array_keys(self::drivers()), ProviderCatalog::ids());
    }

    #[DataProvider('drivers')]
    public function test_the_declared_capabilities_are_the_ones_the_driver_implements(string $id, string $class): void
    {
        $implemented = [];

        if (is_subclass_of($class, TextProvider::class)) {
            $implemented[] = ProviderCatalog::TEXT;
        }

        if (is_subclass_of($class, TranscriptionProvider::class)) {
            $implemented[] = ProviderCatalog::STT;
        }

        if (is_subclass_of($class, AudioProvider::class)) {
            $implemented[] = ProviderCatalog::TTS;
        }

        $declared = ProviderCatalog::capabilities($id);

        sort($implemented);
        sort($declared);

        $this->assertSame($implemented, $declared, "The capabilities of [{$id}] do not match its driver.");
    }

    #[DataProvider('drivers')]
    public function test_the_driver_is_the_sdk_driver_of_the_same_name(string $id): void
    {
        $this->assertSame($id, ProviderCatalog::driver($id));
    }

    #[DataProvider('drivers')]
    public function test_the_address_is_fixed_and_https(string $id): void
    {
        $this->assertStringStartsWith('https://', ProviderCatalog::url($id));
    }

    public function test_a_task_needs_the_capability_it_is_served_with(): void
    {
        $this->assertTrue(ProviderCatalog::supportsTask('openai', 'stt'));
        $this->assertTrue(ProviderCatalog::supportsTask('openai', 'tts'));
        $this->assertFalse(ProviderCatalog::supportsTask('anthropic', 'stt'));
        $this->assertFalse(ProviderCatalog::supportsTask('anthropic', 'tts'));
        $this->assertFalse(ProviderCatalog::supportsTask('groq', 'tts'));
        $this->assertFalse(ProviderCatalog::supportsTask('openai', 'unknown'));
    }

    public function test_an_id_off_the_list_is_unknown(): void
    {
        $this->assertFalse(ProviderCatalog::has('openai-compatible'));
        $this->assertFalse(ProviderCatalog::has('https://evil.example'));
        $this->assertFalse(ProviderCatalog::supports('nvidia', ProviderCatalog::TEXT));
    }
}

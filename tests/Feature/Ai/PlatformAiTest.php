<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\PlatformAi;
use App\Services\AppOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiManager;
use Tests\TestCase;

class PlatformAiTest extends TestCase
{
    use RefreshDatabase;

    private function platform(): PlatformAi
    {
        return app(PlatformAi::class);
    }

    public function test_the_connection_takes_the_saved_address_and_key_per_call_and_never_writes_config(): void
    {
        $configBefore = [config('ai.providers.openai-compatible.url'), config('ai.providers.openai-compatible.key')];

        $this->platform()->setBaseUrl('https://gateway.example.org/v1', null);
        $this->platform()->setApiKey('sk-panel-9999', null);

        $connection = $this->platform()->connection('writing', provider: PlatformAi::TEXT_PROVIDER);
        $config = app(AiManager::class)->getInstanceConfig($connection->providerName());

        $this->assertSame('https://gateway.example.org/v1', $config['url']);
        $this->assertSame('sk-panel-9999', $config['key']);
        $this->assertSame('platform', $connection->paidBy);
        $this->assertSame([
            config('ai.providers.openai-compatible.url'),
            config('ai.providers.openai-compatible.key'),
        ], $configBefore, 'The saved values must not reach config/.');
    }

    public function test_a_later_change_on_the_panel_applies_to_the_next_connection(): void
    {
        $this->platform()->setModel('writing', 'first-model', null);
        $this->assertSame('first-model', $this->platform()->connection('writing')->model);

        $this->platform()->setModel('writing', 'second-model', null);
        $this->assertSame('second-model', $this->platform()->connection('writing')->model);
    }

    public function test_the_model_falls_back_to_the_configured_one_when_the_panel_has_none(): void
    {
        config(['news.ai.model' => 'configured-model']);

        $this->assertSame('configured-model', $this->platform()->connection('writing')->model);
    }

    public function test_the_settings_never_return_the_key(): void
    {
        $this->platform()->setApiKey('sk-panel-9999', null);

        $settings = $this->platform()->settings();

        $this->assertSame(['set' => true, 'last4' => '9999', 'source' => 'admin'], $settings['api_key']);
        $this->assertStringNotContainsString('sk-panel', json_encode($settings));
    }

    public function test_a_model_field_off_the_panel_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->platform()->setModel('tts_fallback_model', 'x', null);
    }

    public function test_the_app_options_keep_their_keys_from_the_panel_before(): void
    {
        $this->platform()->setModel('stt', 'whisper-x', null);

        $this->assertSame('whisper-x', app(AppOptions::class)->get('ai.models.stt'));
    }
}

<?php

namespace Tests\Feature\Config;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class DebateLlmOptionsTest extends TestCase
{
    private const KEY = 'DEBATE_LLM_OPTIONS';

    /** @var array{0: mixed, 1: mixed} The values phpunit.xml set, restored after each test. */
    private array $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = [$_ENV[self::KEY] ?? null, $_SERVER[self::KEY] ?? null];
    }

    protected function tearDown(): void
    {
        [$_ENV[self::KEY], $_SERVER[self::KEY]] = $this->original;

        parent::tearDown();
    }

    public function test_valid_json_options_are_used(): void
    {
        $this->setOptions('{"reasoning_effort":"low"}');

        $llm = $this->loadDebateConfig()['llm'];

        $this->assertSame(['reasoning_effort' => 'low'], $llm['options']);
        $this->assertTrue($llm['options_valid']);
    }

    public function test_no_options_is_valid_and_means_no_options(): void
    {
        $this->setOptions('');

        $llm = $this->loadDebateConfig()['llm'];

        $this->assertSame([], $llm['options']);
        $this->assertTrue($llm['options_valid']);
    }

    public function test_invalid_json_is_ignored_and_flagged(): void
    {
        $this->setOptions('{"reasoning_effort": low}');

        $llm = $this->loadDebateConfig()['llm'];

        $this->assertSame([], $llm['options']);
        $this->assertFalse($llm['options_valid']);
    }

    public function test_json_that_is_not_an_object_is_flagged(): void
    {
        $this->setOptions('"low"');

        $llm = $this->loadDebateConfig()['llm'];

        $this->assertSame([], $llm['options']);
        $this->assertFalse($llm['options_valid']);
    }

    public function test_a_boot_with_invalid_options_logs_a_warning(): void
    {
        Log::spy();
        config(['debate.llm.options_valid' => false]);

        $this->app->getProvider(AppServiceProvider::class)->boot();

        Log::shouldHaveReceived('warning')->once()->with(Mockery::pattern('/DEBATE_LLM_OPTIONS/'));
    }

    public function test_a_boot_with_valid_options_logs_nothing(): void
    {
        Log::spy();
        config(['debate.llm.options_valid' => true]);

        $this->app->getProvider(AppServiceProvider::class)->boot();

        Log::shouldNotHaveReceived('warning');
    }

    private function setOptions(string $json): void
    {
        // Laravel's env() reads $_ENV and $_SERVER before putenv(), and phpunit.xml sets the variable in both.
        $_ENV[self::KEY] = $json;
        $_SERVER[self::KEY] = $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDebateConfig(): array
    {
        return require config_path('debate.php');
    }
}

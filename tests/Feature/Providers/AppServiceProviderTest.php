<?php

namespace Tests\Feature\Providers;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    public function test_passwords_must_be_at_least_twelve_characters(): void
    {
        $rule = Password::defaults();

        $this->assertFalse(Validator::make(['password' => 'eleven-char'], ['password' => [$rule]])->passes());
        $this->assertTrue(Validator::make(['password' => 'twelve-chars'], ['password' => [$rule]])->passes());
    }

    public function test_breached_passwords_are_refused_only_in_production(): void
    {
        // Checking against breach lists calls an external service, so it is not done outside production.
        $this->assertFalse($this->checksBreaches(Password::defaults()));

        $this->app->detectEnvironment(fn () => 'production');

        $this->assertTrue($this->checksBreaches(Password::defaults()));
    }

    public function test_an_invalid_debate_options_warning_is_logged_once_an_hour(): void
    {
        Log::spy();
        config(['debate.llm.options_valid' => false]);
        $provider = $this->app->getProvider(AppServiceProvider::class);

        $provider->boot();
        $provider->boot();

        Log::shouldHaveReceived('warning')->once()->with(Mockery::pattern('/DEBATE_LLM_OPTIONS/'));
    }

    private function checksBreaches(Password $rule): bool
    {
        return (new ReflectionProperty(Password::class, 'uncompromised'))->getValue($rule);
    }
}

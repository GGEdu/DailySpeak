<?php

namespace Tests\Feature\Config;

use Tests\TestCase;

class ReverbConfigTest extends TestCase
{
    private const ORIGINS_KEY = 'REVERB_ALLOWED_ORIGINS';

    /** @var array{0: mixed, 1: mixed} The values phpunit.xml set, restored after each test. */
    private array $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = [$_ENV[self::ORIGINS_KEY] ?? null, $_SERVER[self::ORIGINS_KEY] ?? null];
    }

    protected function tearDown(): void
    {
        [$_ENV[self::ORIGINS_KEY], $_SERVER[self::ORIGINS_KEY]] = $this->original;

        parent::tearDown();
    }

    public function test_reverb_accepts_any_origin_when_none_is_configured(): void
    {
        $this->assertSame(['*'], $this->reverbOrigins());
    }

    public function test_reverb_origins_are_read_from_a_comma_separated_list(): void
    {
        $this->setOrigins(' app.example.com, *.example.com ,');

        $this->assertSame(['app.example.com', '*.example.com'], $this->reverbOrigins());
    }

    public function test_a_blank_origin_list_means_any_origin(): void
    {
        $this->setOrigins('  ');

        $this->assertSame(['*'], $this->reverbOrigins());
    }

    private function setOrigins(string $value): void
    {
        // Laravel's env() reads $_ENV and $_SERVER before putenv(), and phpunit.xml sets the variable in both.
        $_ENV[self::ORIGINS_KEY] = $value;
        $_SERVER[self::ORIGINS_KEY] = $value;
    }

    /**
     * @return list<string>
     */
    private function reverbOrigins(): array
    {
        // Reads the file itself, so the environment set by the test is what gets parsed.
        $config = require config_path('reverb.php');

        return $config['apps']['apps'][0]['allowed_origins'];
    }
}

<?php

namespace Tests\Feature\Config;

use Tests\TestCase;

class PrivacyConfigTest extends TestCase
{
    /**
     * Load a config file as it is with the given environment variables unset or set, then restore the environment.
     *
     * @param  array<string, ?string>  $environment  A null value unsets the variable.
     * @return array<string, mixed>
     */
    private function loadConfig(string $file, array $environment): array
    {
        $saved = [];

        // phpunit.xml also pins variables in $_ENV and $_SERVER, which Laravel reads before getenv().
        foreach ($environment as $name => $value) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $value === null ? putenv($name) : putenv("{$name}={$value}");

            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }

        try {
            return require base_path("config/{$file}");
        } finally {
            foreach ($saved as $name => [$getenv, $env, $server]) {
                $getenv === false ? putenv($name) : putenv("{$name}={$getenv}");

                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }

                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    public function test_a_blank_replies_retention_means_the_same_as_the_recordings(): void
    {
        // phpunit.xml pins PRIVACY_REPLIES_RETENTION_DAYS blank. (int) would read that as 0, and the replies would go after a day.
        $privacy = $this->loadConfig('privacy.php', ['PRIVACY_REPLIES_RETENTION_DAYS' => '']);

        $this->assertNull($privacy['replies_retention_days']);
    }

    public function test_a_zero_negative_or_invalid_replies_retention_means_the_same_as_the_recordings(): void
    {
        foreach (['0', '-3', 'soon', '3.5'] as $value) {
            $privacy = $this->loadConfig('privacy.php', ['PRIVACY_REPLIES_RETENTION_DAYS' => $value]);

            $this->assertNull($privacy['replies_retention_days'], "PRIVACY_REPLIES_RETENTION_DAYS={$value}");
        }
    }

    public function test_a_valid_replies_retention_in_the_environment_is_used(): void
    {
        $privacy = $this->loadConfig('privacy.php', ['PRIVACY_REPLIES_RETENTION_DAYS' => '3']);

        $this->assertSame(3, $privacy['replies_retention_days']);
    }

    public function test_a_blank_transcript_retention_keeps_the_transcripts(): void
    {
        // 0 is the documented default: no expiry. A blank value must not start deleting debates.
        $privacy = $this->loadConfig('privacy.php', ['PRIVACY_TRANSCRIPTS_RETENTION_DAYS' => '']);

        $this->assertSame(0, $privacy['transcripts_retention_days']);
    }
}

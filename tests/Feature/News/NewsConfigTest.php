<?php

namespace Tests\Feature\News;

use Tests\TestCase;

class NewsConfigTest extends TestCase
{
    public function test_private_hosts_flag_reads_the_usual_boolean_spellings(): void
    {
        foreach (['no' => false, 'off' => false, 'false' => false, '0' => false, 'maybe' => false, 'yes' => true, 'on' => true, 'true' => true, '1' => true] as $value => $expected) {
            $this->withEnv('NEWS_ALLOW_PRIVATE_HOSTS', $value, function () use ($value, $expected) {
                $this->assertSame($expected, $this->newsConfig()['allow_private_hosts'], "NEWS_ALLOW_PRIVATE_HOSTS={$value}");
            });
        }
    }

    public function test_integer_settings_never_become_zero_by_accident(): void
    {
        $this->withEnv('NEWS_MAX_ARTICLES_PER_FEED', '', function () {
            $this->assertSame(1, $this->newsConfig()['max_articles_per_feed']);
        });
        $this->withEnv('NEWS_AI_TIMEOUT', 'abc', function () {
            $this->assertSame(1, $this->newsConfig()['ai']['timeout']);
        });
        $this->withEnv('NEWS_AI_TIMEOUT', '90', function () {
            $this->assertSame(90, $this->newsConfig()['ai']['timeout']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function newsConfig(): array
    {
        return require base_path('config/news.php');
    }

    /**
     * Set an environment variable for the duration of the callback.
     */
    private function withEnv(string $name, string $value, callable $callback): void
    {
        // phpunit.xml also pins the variable in $_ENV and $_SERVER, which Laravel reads before getenv().
        $saved = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;

        try {
            $callback();
        } finally {
            $saved[0] === false ? putenv($name) : putenv("{$name}={$saved[0]}");
            foreach (['_ENV' => $saved[1], '_SERVER' => $saved[2]] as $global => $previous) {
                if ($previous === null) {
                    unset($GLOBALS[$global][$name]);
                } else {
                    $GLOBALS[$global][$name] = $previous;
                }
            }
        }
    }
}

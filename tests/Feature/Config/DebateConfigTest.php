<?php

namespace Tests\Feature\Config;

use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use Tests\TestCase;

class DebateConfigTest extends TestCase
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

    public function test_a_queued_job_is_not_redelivered_while_it_runs(): void
    {
        $timeout = (new ProcessVoiceDebate(new Debate, 'turn.webm'))->timeout;

        // Unset in the environment, so these are the shipped defaults: retry_after must exceed the job timeout.
        $queue = $this->loadConfig('queue.php', ['REDIS_QUEUE_RETRY_AFTER' => null, 'DB_QUEUE_RETRY_AFTER' => null]);

        $this->assertSame(180, $queue['connections']['redis']['retry_after']);
        $this->assertSame(180, $queue['connections']['database']['retry_after']);
        $this->assertGreaterThan($timeout, $queue['connections']['redis']['retry_after']);
        $this->assertGreaterThan($timeout, $queue['connections']['database']['retry_after']);
    }

    public function test_a_broadcast_gives_up_fast_so_it_cannot_hold_a_voice_turn(): void
    {
        $this->assertSame(['timeout' => 3, 'connect_timeout' => 2], config('broadcasting.connections.reverb.client_options'));
    }

    public function test_a_blank_or_invalid_limit_in_the_environment_falls_back_to_its_default(): void
    {
        // (int) would read a blank as 0: no timeout at all, one turn a day, or recordings deleted at once.
        $debate = $this->loadConfig('debate.php', [
            'DEBATE_STT_TIMEOUT' => '',
            'DEBATE_LLM_TIMEOUT' => 'soon',
            'DEBATE_TTS_TIMEOUT' => '0',
            'DEBATE_DAILY_TURNS' => '0',
            'DEBATE_AUDIO_RETENTION_DAYS' => '-3',
        ]);

        $this->assertSame(30, $debate['stt']['timeout']);
        $this->assertSame(30, $debate['llm']['timeout']);
        $this->assertSame(30, $debate['tts']['timeout']);
        $this->assertSame(300, $debate['audio']['daily_turns']);
        $this->assertSame(7, $debate['audio']['retention_days']);
    }

    public function test_a_valid_limit_in_the_environment_is_used(): void
    {
        $debate = $this->loadConfig('debate.php', ['DEBATE_STT_TIMEOUT' => '45', 'DEBATE_DAILY_TURNS' => '12']);

        $this->assertSame(45, $debate['stt']['timeout']);
        $this->assertSame(12, $debate['audio']['daily_turns']);
    }

    public function test_the_evaluator_reads_a_bounded_part_of_a_debate(): void
    {
        $debate = config('debate.evaluator');

        $this->assertSame(30, $debate['max_turns']);
        $this->assertSame(10000, $debate['max_characters']);
    }
}

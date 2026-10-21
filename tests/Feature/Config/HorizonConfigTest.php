<?php

namespace Tests\Feature\Config;

use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class HorizonConfigTest extends TestCase
{
    public function test_debates_have_their_own_supervisor_apart_from_default_work(): void
    {
        $debates = config('horizon.defaults.supervisor-debates');

        $this->assertSame(['debates'], $debates['queue']);
        $this->assertGreaterThanOrEqual(3, $debates['maxProcesses']);
        $this->assertLessThanOrEqual(4, $debates['maxProcesses']);
        $this->assertSame(['default'], config('horizon.defaults.supervisor-1')['queue']);
    }

    public function test_the_debates_supervisor_outlives_the_longest_turn_and_is_redelivered_later(): void
    {
        $supervisor = config('horizon.defaults.supervisor-debates');
        $job = new ProcessVoiceDebate(new Debate, 'turn.webm');

        // Each job sets its own timeout, which wins; the supervisor's is the fallback and must cover them.
        $this->assertGreaterThan($job->timeout, $supervisor['timeout']);
        $this->assertLessThan(config('queue.connections.redis.retry_after'), $supervisor['timeout']);
    }

    public function test_the_debates_supervisor_keeps_its_pool_in_production(): void
    {
        $supervisor = array_merge(
            config('horizon.defaults.supervisor-debates'),
            config('horizon.environments.production.supervisor-debates'),
        );

        $this->assertSame(['debates'], $supervisor['queue']);
        $this->assertSame(4, $supervisor['maxProcesses']);
    }

    public function test_horizon_metrics_are_snapshotted_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'horizon:snapshot'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}

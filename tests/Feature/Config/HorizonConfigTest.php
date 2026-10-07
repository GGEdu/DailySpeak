<?php

namespace Tests\Feature\Config;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class HorizonConfigTest extends TestCase
{
    public function test_the_debates_queue_is_served_before_default(): void
    {
        $supervisor = config('horizon.defaults.supervisor-1');

        // Horizon serves the queues in the listed order only when balancing is off; 'auto' balances them.
        $this->assertSame(['debates', 'default'], $supervisor['queue']);
        $this->assertFalse($supervisor['balance']);
    }

    public function test_the_debates_priority_also_holds_in_production(): void
    {
        $supervisor = array_merge(
            config('horizon.defaults.supervisor-1'),
            config('horizon.environments.production.supervisor-1'),
        );

        $this->assertSame(['debates', 'default'], $supervisor['queue']);
        $this->assertFalse($supervisor['balance']);
    }

    public function test_horizon_metrics_are_snapshotted_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'horizon:snapshot'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}

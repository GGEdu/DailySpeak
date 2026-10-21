<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    private const TASKS = ['news:fetch', 'debates:prune-recordings', 'horizon:snapshot', 'queue:prune-failed', 'news:check-policies'];

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function scheduledCommands(): array
    {
        return [
            'news:fetch' => ['news:fetch', '0 3 * * *', 180, 'schedule-news-fetch.log'],
            'debates:prune-recordings' => ['debates:prune-recordings', '0 4 * * *', 60, 'schedule-debates-prune-recordings.log'],
            'horizon:snapshot' => ['horizon:snapshot', '*/5 * * * *', 5, 'schedule-horizon-snapshot.log'],
            'queue:prune-failed' => ['queue:prune-failed --hours=168', '30 4 * * *', 30, 'schedule-queue-prune-failed.log'],
            'news:check-policies' => ['news:check-policies', '0 5 * * 1', 60, 'schedule-news-check-policies.log'],
        ];
    }

    #[DataProvider('scheduledCommands')]
    public function test_each_task_has_its_expression_timezone_and_overlap_window(string $command, string $expression, int $minutes, string $log): void
    {
        $event = $this->task($command);

        $this->assertNotNull($event);
        $this->assertSame($expression, $event->expression);
        $this->assertSame('Europe/Madrid', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame($minutes, $event->expiresAt);
        $this->assertSame(storage_path("logs/{$log}"), $event->output);
    }

    public function test_the_heartbeat_runs_every_minute(): void
    {
        $heartbeat = $this->heartbeat();

        $this->assertNotNull($heartbeat);
        $this->assertSame('* * * * *', $heartbeat->expression);
    }

    public function test_without_urls_no_ping_is_sent(): void
    {
        $this->clearChecks();
        Http::fake();

        foreach (self::TASKS as $name) {
            $event = $this->task($name);
            $event->callBeforeCallbacks(app());
            $event->finish(app(), 0);
            $event->finish(app(), 1);
        }
        $this->heartbeat()->run(app());

        Http::assertNothingSent();
    }

    public function test_with_urls_each_task_pings_its_check_on_start_success_and_failure(): void
    {
        $this->clearChecks();
        Http::fake();

        foreach (self::TASKS as $name) {
            config(["monitoring.healthchecks.tasks.{$name}" => "https://hc-ping.com/{$this->slug($name)}"]);

            $event = $this->task($name);
            $event->callBeforeCallbacks(app());
            $event->finish(app(), 0);
            $event->finish(app(), 1);
        }

        $this->assertSame(
            [
                'https://hc-ping.com/news-fetch/start', 'https://hc-ping.com/news-fetch', 'https://hc-ping.com/news-fetch/fail',
                'https://hc-ping.com/debates-prune-recordings/start', 'https://hc-ping.com/debates-prune-recordings', 'https://hc-ping.com/debates-prune-recordings/fail',
                'https://hc-ping.com/horizon-snapshot/start', 'https://hc-ping.com/horizon-snapshot', 'https://hc-ping.com/horizon-snapshot/fail',
                'https://hc-ping.com/queue-prune-failed/start', 'https://hc-ping.com/queue-prune-failed', 'https://hc-ping.com/queue-prune-failed/fail',
                'https://hc-ping.com/news-check-policies/start', 'https://hc-ping.com/news-check-policies', 'https://hc-ping.com/news-check-policies/fail',
            ],
            collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url())->all(),
        );
    }

    public function test_a_trailing_slash_in_the_url_is_not_doubled(): void
    {
        $this->clearChecks();
        config(['monitoring.healthchecks.tasks.news:fetch' => 'https://hc-ping.com/news/']);
        Http::fake();

        $this->task('news:fetch')->callBeforeCallbacks(app());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc-ping.com/news/start');
    }

    public function test_the_heartbeat_pings_its_own_check_on_every_run(): void
    {
        $this->clearChecks();
        config(['monitoring.healthchecks.scheduler' => 'https://hc-ping.com/scheduler']);
        Http::fake();

        $this->heartbeat()->run(app());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc-ping.com/scheduler');
    }

    public function test_a_failed_run_is_logged_as_critical_with_the_task_name(): void
    {
        Log::spy();

        $this->task('news:fetch')->finish(app(), 1);

        Log::shouldHaveReceived('critical')
            ->withArgs(fn (string $message) => str_contains($message, 'news:fetch'))
            ->once();
    }

    public function test_a_successful_run_is_not_logged_as_critical(): void
    {
        Log::spy();

        $this->task('news:fetch')->finish(app(), 0);

        Log::shouldNotHaveReceived('critical');
    }

    private function task(string $command): Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => ! $event instanceof CallbackEvent && str_contains((string) $event->command, $command));
    }

    private function heartbeat(): CallbackEvent
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => $event instanceof CallbackEvent);
    }

    private function clearChecks(): void
    {
        config(['monitoring.healthchecks.scheduler' => null]);

        foreach (self::TASKS as $name) {
            config(["monitoring.healthchecks.tasks.{$name}" => null]);
        }
    }

    private function slug(string $name): string
    {
        return str_replace(':', '-', $name);
    }
}

<?php

use App\Console\Commands\CheckNewsSourcePolicies;
use App\Console\Commands\FetchDailyNews;
use App\Console\Commands\PruneVoiceRecordings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Times are in app.schedule_timezone (Europe/Madrid by default).

// Healthchecks ping. An empty URL sends nothing. A ping that fails is logged, never thrown: a monitor
// that is down must not stop the task it watches.
$ping = function (?string $url, string $suffix = ''): void {
    if (blank($url)) {
        return;
    }

    try {
        $response = Http::timeout(10)->get(rtrim($url, '/').$suffix);
    } catch (ConnectionException $e) {
        Log::warning('Healthchecks ping not sent: no connection.', ['exception' => $e::class]);

        return;
    }

    if ($response->failed()) {
        Log::warning('Healthchecks ping rejected.', ['status' => $response->status()]);
    }
};

// Every scheduled task gets the same safety net:
// - the overlap lock expires after $overlapMinutes, so a run killed with SIGKILL cannot block the next
//   one (the 24 h default blocked the next day's run);
// - a failed run is logged as critical with the task name;
// - its output is kept in storage/logs instead of /dev/null;
// - Healthchecks gets /start, then either the plain URL (success) or /fail, when its URL is configured.
$monitored = function (Event $event, string $task, int $overlapMinutes) use ($ping): Event {
    $output = storage_path('logs/schedule-'.str_replace(':', '-', $task).'.log');
    $check = fn () => config("monitoring.healthchecks.tasks.{$task}");

    return $event
        ->withoutOverlapping($overlapMinutes)
        ->appendOutputTo($output)
        ->before(fn () => $ping($check(), '/start'))
        ->onSuccess(fn () => $ping($check()))
        ->onFailure(function () use ($event, $task, $output, $check, $ping) {
            Log::critical("Scheduled task {$task} failed.", [
                'task' => $task,
                'exit_code' => $event->exitCode,
                'output' => $output,
            ]);

            $ping($check(), '/fail');
        });
};

// 03:00 Madrid. A full run reads every source in turn, so its lock covers three hours.
$monitored(
    Schedule::command(FetchDailyNews::class)->dailyAt(config('news.schedule.time'))->onOneServer(),
    'news:fetch',
    overlapMinutes: 180,
);

// 04:00 Madrid. Old recordings are deleted one by one.
$monitored(
    Schedule::command(PruneVoiceRecordings::class)->dailyAt('04:00')->onOneServer(),
    'debates:prune-recordings',
    overlapMinutes: 60,
);

// Horizon's throughput and wait-time graphs are built from these snapshots.
$monitored(
    Schedule::command('horizon:snapshot')->everyFiveMinutes(),
    'horizon:snapshot',
    overlapMinutes: 5,
);

// Failed jobs older than a week leave the failed_jobs table. Horizon keeps its own list.
$monitored(
    Schedule::command('queue:prune-failed --hours=168')->dailyAt('04:30'),
    'queue:prune-failed',
    overlapMinutes: 30,
);

// Mondays 05:00 Madrid. Re-reads each source's robots.txt and known terms for the admin panel notes.
// At most a few requests per source, each capped at 15 s, and no AI call.
$monitored(
    Schedule::command(CheckNewsSourcePolicies::class)->weeklyOn(1, '05:00')->onOneServer(),
    'news:check-policies',
    overlapMinutes: 60,
);

// Heartbeat: cron runs schedule:run every minute, so this check goes down if the scheduler stops.
Schedule::call(fn () => null)
    ->everyMinute()
    ->description('Scheduler heartbeat')
    ->onSuccess(fn () => $ping(config('monitoring.healthchecks.scheduler')));

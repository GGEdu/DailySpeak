<?php

use App\Console\Commands\FetchDailyNews;
use App\Console\Commands\PruneVoiceRecordings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Times are in app.schedule_timezone (Europe/Madrid by default).

Schedule::command(FetchDailyNews::class)
    ->dailyAt(config('news.schedule.time'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(PruneVoiceRecordings::class)
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();

// Horizon's throughput and wait-time graphs are built from these snapshots.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

<?php

use App\Console\Commands\FetchDailyNews;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(FetchDailyNews::class)
    ->dailyAt(config('news.schedule.time'))
    ->timezone(config('news.schedule.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

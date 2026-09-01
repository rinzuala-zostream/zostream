<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('birthday:send')->hourly()->between('9:00', '13:00');

Schedule::command("app:queue-today-birthdays")->daily();

Schedule::command('app:movie-schedule')
    ->hourly()
    ->between('12:00', '14:00')
    ->withoutOverlapping(30);

// 12 AM: deactivate only
Schedule::command('app:subscription-maintenance --deactivate=1 --send-reminders=0')
    ->daily();

// 10 AM: reminder only
Schedule::command('app:subscription-maintenance --deactivate=0 --reminder-days=3 --send-reminders=1')
    ->dailyAt('10:00');

// Keep stopped/expired rows briefly for idempotent stop retries and diagnosis,
// then remove them in small batches so the live-session table stays compact.
Schedule::command('streams:prune-inactive')
    ->dailyAt('03:30')
    ->withoutOverlapping(30);

Schedule::command('recommender:train')
    ->cron((string) config('recommender.train_schedule', '0 3 * * *'))
    ->timezone((string) config('recommender.train_timezone', config('app.timezone', 'UTC')))
    ->withoutOverlapping(180)
    ->onOneServer();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('birthday:send')->hourly()->between('9:00', '13:00');

Schedule::command('app:queue-today-birthdays')->daily();

Schedule::command('app:movie-schedule')
    ->hourly()
    ->between('12:00', '14:00')
    ->withoutOverlapping(30);

// 12 AM: deactivate only
Schedule::command('app:subscription-maintenance --deactivate=1 --send-reminders=0')
    ->daily()
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(30);

// 10 AM: reminder only
Schedule::command('app:subscription-maintenance --deactivate=0 --reminder-days=2 --send-reminders=1')
    ->dailyAt('10:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(30);

// 10 AM IST: remind active ISP customers whose WiFi plan expires tomorrow.
Schedule::command('isp:send-wifi-reminders')
    ->dailyAt('10:00')
    ->withoutOverlapping(30);

// 12 AM IST: enforce expiry in RADIUS and disconnect sessions without changing manual status.
Schedule::command('isp:suspend-expired')
    ->dailyAt('00:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

Schedule::command('isp:prune-customer-onboardings')
    ->dailyAt('02:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

// Keep stopped/expired rows briefly for idempotent stop retries and diagnosis,
// then remove them in small batches so the live-session table stays compact.
Schedule::command('streams:prune-inactive')
    ->dailyAt('03:30')
    ->withoutOverlapping(30);

Schedule::command('ads:maintain-campaigns')
    ->hourly()
    ->withoutOverlapping(10);

Schedule::command('recommender:train-sql-backup')
    ->cron((string) config('recommender.train_schedule', '0 3 * * *'))
    ->timezone((string) config('recommender.train_timezone', config('app.timezone', 'UTC')))
    ->withoutOverlapping(180)
    ->onOneServer();

// Home requests only read this cache; refresh expensive monthly rankings here.
Schedule::command('home:warm-monthly-top-ten')
    ->hourly()
    ->withoutOverlapping(120);

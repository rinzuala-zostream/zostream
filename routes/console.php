<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('birthday:send')->hourly()->between('9:00', '13:00');

Schedule::command("app:queue-today-birthdays")->daily();

Schedule::command('movie-schedule')->hourly()->between('12:00', '14:00');
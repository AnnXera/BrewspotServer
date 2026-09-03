<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscriptions:expire')->everyMinute();

Schedule::command('subscriptions:send-expiration-reminders')->dailyAt('08:00');

Schedule::command('registrations:cleanup-abandoned --hours=1')->hourly();
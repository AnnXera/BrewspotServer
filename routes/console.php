<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run inside the schedule:run process. On Hostinger shared hosting, Schedule::command()'s
// child processes exit immediately without doing any work while still reporting DONE.
$inProcess = fn (string $command) => fn () => Artisan::call($command);

Schedule::call($inProcess('subscriptions:expire'))->name('subscriptions:expire')->everyMinute();

Schedule::call($inProcess('subscriptions:send-expiration-reminders'))->name('subscriptions:send-expiration-reminders')->everyMinute();

Schedule::call($inProcess('registrations:cleanup-abandoned --hours=24'))->name('registrations:cleanup-abandoned')->daily();

Schedule::call($inProcess('app:cleanup-temp-uploads'))->name('app:cleanup-temp-uploads')->daily();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// DSCO poller — the scheduler fires every minute.
// The command enforces DSCO_POLL_INTERVAL_MINUTES (.env) internally,
// so changing the interval only requires updating .env (no scheduler restart).
Schedule::command('dsco:poll')->everyMinute()->withoutOverlapping();

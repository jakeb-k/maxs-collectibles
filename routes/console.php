<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();


Schedule::command('zoho:refresh-token')
    ->everyFifteenMinutes()
    ->withoutOverlapping(5)
    ->runInBackground();
Schedule::command('checkout:reconcile')->everyMinute()->withoutOverlapping(5);

Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping(2);

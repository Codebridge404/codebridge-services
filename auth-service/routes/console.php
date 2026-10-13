<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CodeBridges Meeting Module Scheduled Background Turnovers & Reminders
Schedule::command('meetings:auto-release')->everyMinute()->withoutOverlapping();
Schedule::command('meetings:send-reminders')->everyMinute()->withoutOverlapping();

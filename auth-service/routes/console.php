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

// Customer Store Credit & Digital Voucher Daily Expiration Sweep
Schedule::command('store-credit:expire')->dailyAt('00:05')->withoutOverlapping();

// Inactive & Expired Sessions Periodic Cleanup Sweep
Schedule::command('sessions:cleanup-inactive')->hourly()->withoutOverlapping();

// Automated Daily Encrypted Auth Database Backup
Schedule::command('backup:database-encrypted --destination=minio')->dailyAt('02:15')->withoutOverlapping();

// Horizon queue metrics snapshot for supervisor monitoring
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();

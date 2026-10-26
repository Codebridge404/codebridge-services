<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Background Cron Jobs
|--------------------------------------------------------------------------
*/
// 1. National Bank of Cambodia (NBC) Official USD-KHR Daily Rate Sync
// Runs every morning at 08:30 AM (Asia/Phnom_Penh) when NBC publishes daily reference rate
Schedule::command('currency:sync-nbc')->dailyAt('08:30')->withoutOverlapping();

// 2. Automated Daily Encrypted Database Backup & MinIO/S3 Cloud Upload
// Runs nightly at 02:00 AM (Asia/Phnom_Penh)
Schedule::command('backup:database-encrypted --destination=minio')->dailyAt('02:00')->withoutOverlapping();

<?php

use App\Http\Controllers\Api\V1\SecurityEventController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Security Events Forensic Audit & Incident Inspection Routes
| Accessible by super_admin, organization, and tenant admin
|--------------------------------------------------------------------------
*/
Route::middleware('role:super_admin,organization,admin')->prefix('security-events')->group(function () {
    Route::get('/stats', [SecurityEventController::class, 'stats']);
    Route::get('/settings', [SecurityEventController::class, 'settings']);
    Route::put('/settings', [SecurityEventController::class, 'updateSettings']);
    Route::get('/banned-ips', [SecurityEventController::class, 'bannedIps']);
    Route::post('/ban-ip', [SecurityEventController::class, 'banIp']);
    Route::post('/unban-ip', [SecurityEventController::class, 'unbanIp']);
    Route::get('/', [SecurityEventController::class, 'index']);
    Route::get('/{id}', [SecurityEventController::class, 'show']);
});


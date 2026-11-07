<?php

use App\Http\Controllers\Api\V1\NotificationProviderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Multi-Tenant & Platform Notification Providers
|--------------------------------------------------------------------------
*/
Route::middleware('role:super_admin,organization,admin')->group(function () {
    Route::get('/notification-providers', [NotificationProviderController::class, 'index']);
    Route::post('/notification-providers', [NotificationProviderController::class, 'store']);
    Route::get('/notification-providers/{id}', [NotificationProviderController::class, 'show']);
    Route::put('/notification-providers/{id}', [NotificationProviderController::class, 'update']);
    Route::patch('/notification-providers/{id}', [NotificationProviderController::class, 'update']);
    Route::delete('/notification-providers/{id}', [NotificationProviderController::class, 'destroy']);
    Route::patch('/notification-providers/{id}/toggle', [NotificationProviderController::class, 'toggle']);
    Route::post('/notification-providers/{id}/test', [NotificationProviderController::class, 'test']);
});

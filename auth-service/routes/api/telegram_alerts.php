<?php

use App\Http\Controllers\Api\V1\TelegramAlertController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Telegram Real-Time Alerts & Notification Management Routes
| Accessible by super_admin, organization, and tenant admin
|--------------------------------------------------------------------------
*/
Route::middleware('role:super_admin,organization,admin')->prefix('telegram-alerts')->group(function () {
    Route::get('/status', [TelegramAlertController::class, 'status']);
    Route::get('/history', [TelegramAlertController::class, 'history']);
    Route::post('/send-test', [TelegramAlertController::class, 'sendTest']);
    Route::post('/preview', [TelegramAlertController::class, 'preview']);
    Route::get('/templates', [TelegramAlertController::class, 'templates']);
    Route::put('/templates/{type}', [TelegramAlertController::class, 'updateTemplate']);
    Route::post('/templates/{type}/reset', [TelegramAlertController::class, 'resetTemplate']);
});


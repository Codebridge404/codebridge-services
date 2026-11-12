<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Microservice Health Check
    |--------------------------------------------------------------------------
    */
    Route::get('/health', [HealthController::class, 'health']);

    /*
    |--------------------------------------------------------------------------
    | Modular Domain Route Files
    |--------------------------------------------------------------------------
    */
    require __DIR__ . '/api/catalog.php';
    require __DIR__ . '/api/inventory.php';
    require __DIR__ . '/api/sales.php';
    require __DIR__ . '/api/shifts.php';
    require __DIR__ . '/api/payments.php';
    require __DIR__ . '/api/finance.php';
    require __DIR__ . '/api/reports.php';
    require __DIR__ . '/api/currencies.php';
    require __DIR__ . '/api/pos_bff.php';
});

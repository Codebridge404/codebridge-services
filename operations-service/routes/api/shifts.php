<?php

use App\Http\Controllers\Api\V1\ShiftController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cash Drawer Shifts & Till Floats (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/shifts/active', [ShiftController::class, 'active']);
    Route::post('/shifts/open', [ShiftController::class, 'open']);
    Route::get('/shifts/history', [ShiftController::class, 'history']);
    Route::get('/shifts/variance-alerts', [ShiftController::class, 'varianceAlerts']);
    Route::get('/shifts/variance-config', [ShiftController::class, 'varianceConfig']);
    Route::post('/shifts/test-variance-alert', [ShiftController::class, 'testVarianceAlert']);
    Route::get('/shifts/{id}/x-report', [ShiftController::class, 'xReport']);
    Route::post('/shifts/{id}/cash-movement', [ShiftController::class, 'cashMovement']);
    Route::post('/shifts/{id}/close', [ShiftController::class, 'close']);
    Route::post('/shifts/cash-movement', [ShiftController::class, 'cashMovement']);
    Route::post('/shifts/close', [ShiftController::class, 'close']);

    // End-of-Day (EOD) Z-Report & Nightly Dispatch
    Route::match(['get', 'post'], '/reports/z-report', [ShiftController::class, 'zReport']);
    Route::match(['get', 'post'], '/pos/reports/z-report', [ShiftController::class, 'zReport']);
    Route::post('/shifts/eod-dispatch', [ShiftController::class, 'eodDispatch']);
    Route::post('/pos/shifts/eod-dispatch', [ShiftController::class, 'eodDispatch']);
});

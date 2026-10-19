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
    Route::get('/shifts/{id}/x-report', [ShiftController::class, 'xReport']);
    Route::post('/shifts/{id}/cash-movement', [ShiftController::class, 'cashMovement']);
    Route::post('/shifts/{id}/close', [ShiftController::class, 'close']);
    Route::post('/shifts/cash-movement', [ShiftController::class, 'cashMovement']);
    Route::post('/shifts/close', [ShiftController::class, 'close']);
});

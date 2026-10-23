<?php

use App\Http\Controllers\Api\V1\ExchangeRateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Multi-Currency & NBC Dynamic Exchange Rate Sync Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/currencies/exchange-rate/active', [ExchangeRateController::class, 'getActive']);
    Route::get('/currencies/exchange-rate/history', [ExchangeRateController::class, 'history']);
    Route::post('/currencies/exchange-rate/sync-nbc', [ExchangeRateController::class, 'syncNbc']);
    Route::post('/currencies/exchange-rate/convert', [ExchangeRateController::class, 'convert']);
    Route::post('/currencies/exchange-rate', [ExchangeRateController::class, 'store']);
});

<?php

use App\Http\Controllers\Api\V1\ExchangeRateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Multi-Currency & NBC Dynamic Exchange Rate Sync Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('role:organization,organization_owner,owner,admin,super_admin,outlet_manager,supervisor,accountant,cashier,inventory_clerk,employee')->group(function () {
        Route::get('/currencies/exchange-rate/active', [ExchangeRateController::class, 'getActive']);
        Route::get('/currencies/exchange-rate/history', [ExchangeRateController::class, 'history']);
        Route::post('/currencies/exchange-rate/convert', [ExchangeRateController::class, 'convert']);
    });

    Route::middleware('role:organization,organization_owner,owner,admin,super_admin,outlet_manager,accountant,supervisor')->group(function () {
        Route::post('/currencies/exchange-rate/sync-nbc', [ExchangeRateController::class, 'syncNbc']);
        Route::post('/currencies/exchange-rate', [ExchangeRateController::class, 'store']);
    });
});

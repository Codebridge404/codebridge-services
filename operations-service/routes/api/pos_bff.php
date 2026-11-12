<?php

use App\Http\Controllers\Api\V1\PosBffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| POS Backend-for-Frontend (BFF) Screen-Shaped Endpoints
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('pos-bff')->group(function () {
    Route::middleware('role:organization,organization_owner,owner,cashier,supervisor,outlet_manager,admin,super_admin,accountant,inventory_clerk,employee')->group(function () {
        Route::get('/bootstrap', [PosBffController::class, 'bootstrap']);
        Route::get('/catalog', [PosBffController::class, 'catalog']);
        Route::get('/shift-summary', [PosBffController::class, 'shiftSummary']);
        Route::post('/checkout', [PosBffController::class, 'checkout']);
    });
});


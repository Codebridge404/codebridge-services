<?php

use App\Http\Controllers\Api\V1\RefreshTokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Enterprise Refresh Tokens Lineage & Revocation Routes
| Accessible by super_admin, organization, and tenant admin
|--------------------------------------------------------------------------
*/
Route::middleware('role:super_admin,organization,admin')->prefix('refresh-tokens')->group(function () {
    Route::get('/stats', [RefreshTokenController::class, 'stats']);
    Route::get('/', [RefreshTokenController::class, 'index']);
    Route::get('/{id}', [RefreshTokenController::class, 'show']);
    Route::post('/{id}/revoke', [RefreshTokenController::class, 'revoke']);
});


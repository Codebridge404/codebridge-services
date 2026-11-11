<?php

use App\Http\Controllers\Api\V1\UserSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Enterprise User Sessions & Multi-Device Governance Routes
| Accessible by super_admin, organization, and tenant admin
|--------------------------------------------------------------------------
*/
Route::middleware('role:super_admin,organization,admin')->prefix('user-sessions')->group(function () {
    Route::get('/stats', [UserSessionController::class, 'stats']);
    Route::get('/', [UserSessionController::class, 'index']);
    Route::get('/{id}', [UserSessionController::class, 'show']);
    Route::post('/{id}/revoke', [UserSessionController::class, 'revoke']);
    Route::post('/revoke-all', [UserSessionController::class, 'revokeAll']);
});


<?php

use App\Http\Controllers\Api\V1\ApiClientController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Enterprise API Client Management Endpoints
|--------------------------------------------------------------------------
*/
Route::middleware('role:super_admin,organization,admin')->group(function () {
    Route::get('/api-clients', [ApiClientController::class, 'index']);
    Route::post('/api-clients', [ApiClientController::class, 'store']);
    Route::get('/api-clients/{id}', [ApiClientController::class, 'show']);
    Route::match(['put', 'patch'], '/api-clients/{id}', [ApiClientController::class, 'update']);
    Route::put('/api-clients/{id}/toggle', [ApiClientController::class, 'toggle']);
    Route::post('/api-clients/{id}/regenerate-key', [ApiClientController::class, 'regenerateKey']);
    Route::delete('/api-clients/{id}', [ApiClientController::class, 'destroy']);
});


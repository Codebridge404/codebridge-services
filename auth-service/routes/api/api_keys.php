<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant & Developer API Keys
|--------------------------------------------------------------------------
*/
Route::middleware('role:organization,admin,super_admin')->group(function () {
Route::get('/api-keys', [ApiKeyController::class, 'index']);
Route::post('/api-keys', [ApiKeyController::class, 'store']);
Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);
Route::put('/api-keys/{id}/toggle', [ApiKeyController::class, 'toggle']);

});

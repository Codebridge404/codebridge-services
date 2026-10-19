<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Active Devices & Session Management
|--------------------------------------------------------------------------
*/
Route::get('/auth/sessions', [AuthController::class, 'sessions']);
Route::delete('/auth/sessions/{id}', [AuthController::class, 'revokeSession']);
Route::post('/auth/logout-all-devices', [AuthController::class, 'logoutAllDevices']);
Route::post('/auth/2fa/toggle', [AuthController::class, 'toggle2fa']);

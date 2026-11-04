<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Active Devices & Session Management
|--------------------------------------------------------------------------
*/
Route::get('/auth/sessions', [AuthController::class, 'sessions']);
Route::get('/auth/sessions/settings', [AuthController::class, 'getSessionSettings']);
Route::match(['put', 'post'], '/auth/sessions/settings', [AuthController::class, 'updateSessionSettings']);
Route::post('/auth/sessions/ping', [AuthController::class, 'pingSession']);
Route::delete('/auth/sessions/{id}', [AuthController::class, 'revokeSession']);
Route::post('/auth/logout-all-devices', [AuthController::class, 'logoutAllDevices']);

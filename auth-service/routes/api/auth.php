<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Authentication & Invite Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth_throttle:5,15')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/quick-switch', [AuthController::class, 'quickSwitch']);
});

Route::post('/auth/register', [AuthController::class, 'register']);
Route::get('/auth/verify-invite', [AuthController::class, 'verifyInvite']);
Route::post('/auth/accept-invite', [AuthController::class, 'acceptInvite']);

/*
|--------------------------------------------------------------------------
| Authenticated User Profile & Identity Lifecycle
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::match(['put', 'post'], '/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

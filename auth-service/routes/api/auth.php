<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Authentication & Invite Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth_throttle:5,15')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/quick-switch', [AuthController::class, 'quickSwitch']);
    Route::post('/auth/2fa/challenge', [TwoFactorController::class, 'challenge']);
});

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);
Route::get('/auth/verify-invite', [AuthController::class, 'verifyInvite']);
Route::post('/auth/accept-invite', [AuthController::class, 'acceptInvite']);

/*
|--------------------------------------------------------------------------
| Email Verification Routes (Mailpit / SMTP)
|--------------------------------------------------------------------------
*/
Route::middleware('auth_throttle:10,1')->group(function () {
    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
    Route::post('/auth/email/create-link', [AuthController::class, 'createVerificationLink']);
    Route::post('/auth/email/send-link', [AuthController::class, 'createVerificationLink']);
    Route::post('/auth/email/verify-code', [AuthController::class, 'verifyEmailCode']);
    Route::match(['get', 'post'], '/auth/email/check', [AuthController::class, 'checkEmailVerification']);
    Route::post('/auth/email/verification-notification', [AuthController::class, 'sendVerificationNotification']);
    Route::get('/auth/email/template-preview', [AuthController::class, 'previewEmailTemplate']);
});

/*
|--------------------------------------------------------------------------
| Authenticated User Profile & Identity Lifecycle
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::match(['put', 'post'], '/auth/profile', [AuthController::class, 'update']);
    Route::post('/auth/upload-profile', [AuthController::class, 'uploadProfile']);
    Route::post('/auth/profile/avatar', [AuthController::class, 'uploadProfile']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    /* Two-Factor Authentication (TOTP Authenticator App) */
    Route::post('/auth/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('/auth/2fa/disable', [TwoFactorController::class, 'disable']);
    Route::post('/auth/2fa/toggle', [TwoFactorController::class, 'toggle']);
});

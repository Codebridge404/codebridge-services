<?php

/**
 * Author: Antigravity Team
 * Date: 2026-11-04
 * Task: 2FA Recovery Codes & Email OTP UI (BD-0133)
 */

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PasskeyController;
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
    Route::post('/auth/two-factor/challenge', [TwoFactorController::class, 'challenge']);

    /* Public Passkey Authentication Ceremony */
    Route::get('/auth/passkey-test', function () {
        return response()->file(public_path('passkey-test.html'));
    });
    Route::post('/auth/passkeys/authentication/options', [PasskeyController::class, 'authenticationOptions']);
    Route::post('/auth/passkeys/authentication/verify', [PasskeyController::class, 'authenticationVerify']);

    /* Mobile Platform Association (.well-known) */
    Route::get('/.well-known/assetlinks.json', [PasskeyController::class, 'assetLinks']);
    Route::get('/.well-known/apple-app-site-association', [PasskeyController::class, 'appleAppSiteAssociation']);
    Route::get('/auth/.well-known/assetlinks.json', [PasskeyController::class, 'assetLinks']);
    Route::get('/auth/.well-known/apple-app-site-association', [PasskeyController::class, 'appleAppSiteAssociation']);
});

Route::middleware('throttle:60,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
});

Route::middleware('auth_throttle:10,15')->group(function () {
    Route::get('/auth/verify-invite', [AuthController::class, 'verifyInvite']);
    Route::post('/auth/accept-invite', [AuthController::class, 'acceptInvite']);
});

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
Route::middleware(['auth:sanctum', 'pos_scope'])->group(function () {
    require __DIR__ . '/sessions.php';
    Route::get('/auth/authorize-context', [AuthController::class, 'authorizeContext']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::match(['put', 'post'], '/auth/profile', [AuthController::class, 'update']);
    Route::post('/auth/upload-profile', [AuthController::class, 'uploadProfile']);
    Route::post('/auth/profile/avatar', [AuthController::class, 'uploadProfile']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword'])->middleware('auth_throttle:10,15');
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    /* Two-Factor Authentication (TOTP Authenticator App) */
    Route::post('/auth/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('/auth/2fa/disable', [TwoFactorController::class, 'disable']);
    Route::post('/auth/2fa/toggle', [TwoFactorController::class, 'toggle']);
    Route::get('/auth/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
    Route::post('/auth/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);

    /* Step-Up Reauthentication Grants */
    Route::post('/auth/reauthenticate/options', [PasskeyController::class, 'reauthOptions']);
    Route::post('/auth/reauthenticate/verify', [PasskeyController::class, 'reauthVerify']);

    /* Passkey Registration & Management */
    Route::post('/auth/passkeys/registration/options', [PasskeyController::class, 'registrationOptions']);
    Route::post('/auth/passkeys/registration/verify', [PasskeyController::class, 'registrationVerify']);
    Route::get('/auth/passkeys', [PasskeyController::class, 'index']);
    Route::patch('/auth/passkeys/{credential}', [PasskeyController::class, 'update']);
    Route::delete('/auth/passkeys/{credential}', [PasskeyController::class, 'destroy']);
});

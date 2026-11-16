<?php

use App\Http\Controllers\Organization\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| POS & Supervisor PIN Verification Override
|--------------------------------------------------------------------------
*/
Route::middleware('auth_throttle:5,15')->group(function () {
    Route::post('/auth/verify-pin', [UserController::class, 'verifyPin']);
});

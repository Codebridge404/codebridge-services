<?php

use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| POS & Supervisor PIN Verification Override
|--------------------------------------------------------------------------
*/
Route::post('/auth/verify-pin', [UserController::class, 'verifyPin']);

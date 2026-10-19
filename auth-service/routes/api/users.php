<?php

use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User & Staff Management
|--------------------------------------------------------------------------
*/
Route::middleware('role:organization,admin,super_admin,outlet_manager')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/lookup', [UserController::class, 'lookup']);
    Route::post('/users', [UserController::class, 'store'])->middleware('quota:users');
    Route::post('/users/invite', [UserController::class, 'invite'])->middleware('quota:users');
    Route::post('/users/attach', [UserController::class, 'attach'])->middleware('quota:users');
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
});

<?php

use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantModuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Profile, Quota & Module Subscriptions
|--------------------------------------------------------------------------
*/
Route::get('/tenants/quota-usage', [TenantController::class, 'quotaUsage']);
Route::get('/tenants/profile', [TenantController::class, 'getProfile']);
Route::match(['put', 'post'], '/tenants/profile', [TenantController::class, 'updateProfile']);
Route::get('/tenants/modules', [TenantModuleController::class, 'index']);
Route::put('/tenants/modules', [TenantModuleController::class, 'update']);

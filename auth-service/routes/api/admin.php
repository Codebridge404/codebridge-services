<?php

use App\Http\Controllers\Api\V1\AdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Unified Role-Scoped Administrative Endpoints
| Accessible by super_admin, organization (admin), and outlet_manager
|--------------------------------------------------------------------------
*/
Route::middleware('role:super_admin,organization,admin,outlet_manager,supervisor')->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/roles', [AdminController::class, 'roles']);
    Route::get('/tenants', [AdminController::class, 'tenants']);
    Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
});


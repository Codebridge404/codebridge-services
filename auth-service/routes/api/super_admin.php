<?php

use App\Http\Controllers\Api\V1\PlatformModuleController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TelemetryController;
use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super Admin Platform & Tenant Management
|--------------------------------------------------------------------------
*/
Route::middleware('role:super_admin')->prefix('super-admin')->group(function () {
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::get('/subscription-plans', [TenantController::class, 'subscriptionPlans']);
    Route::post('/tenants', [TenantController::class, 'register']);
    Route::get('/tenants/{id}', [TenantController::class, 'show']);
    Route::put('/tenants/{id}', [TenantController::class, 'update']);
    Route::put('/tenants/{id}/subscription', [TenantController::class, 'updateSubscription']);
    Route::post('/tenants/{id}/suspend', [TenantController::class, 'suspend']);
    Route::delete('/tenants/{id}', [TenantController::class, 'destroy']);

    // Infrastructure Telemetry
    Route::get('/infrastructure/telemetry', [TelemetryController::class, 'index']);

    // Platform Support Desk & Broadcast Announcements
    Route::get('/support/tickets', [SupportController::class, 'tickets']);
    Route::post('/support/tickets', [SupportController::class, 'storeTicket']);
    Route::put('/support/tickets/{id}', [SupportController::class, 'updateTicket']);
    Route::get('/support/announcements', [SupportController::class, 'announcements']);
    Route::post('/support/announcements', [SupportController::class, 'storeAnnouncement']);
    Route::patch('/support/announcements/{id}/toggle', [SupportController::class, 'toggleAnnouncement']);
    Route::delete('/support/announcements/{id}', [SupportController::class, 'destroyAnnouncement']);

    // Platform Modules Master CRUD Management
    Route::get('/platform-modules', [PlatformModuleController::class, 'index']);
    Route::post('/platform-modules', [PlatformModuleController::class, 'store']);
    Route::put('/platform-modules/{id}', [PlatformModuleController::class, 'update']);
    Route::delete('/platform-modules/{id}', [PlatformModuleController::class, 'destroy']);
});

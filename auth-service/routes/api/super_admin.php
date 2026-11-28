<?php

use App\Http\Controllers\Organization\TenantController;
use App\Http\Controllers\Security\IpRuleController;
use App\Http\Controllers\System\MaintenanceController;
use App\Http\Controllers\System\PlatformModuleController;
use App\Http\Controllers\System\QueueMonitorController;
use App\Http\Controllers\System\SupportController;
use App\Http\Controllers\System\TelemetryController;
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
    Route::match(['put', 'patch'], '/tenants/{id}', [TenantController::class, 'update']);
    Route::put('/tenants/{id}/subscription', [TenantController::class, 'updateSubscription']);
    Route::post('/tenants/{id}/suspend', [TenantController::class, 'suspend']);
    Route::delete('/tenants/{id}', [TenantController::class, 'destroy']);

    // Infrastructure Telemetry & Object Storage Governance (BD-0136)
    Route::get('/infrastructure/telemetry', [TelemetryController::class, 'index']);
    Route::get('/infrastructure/storage', [TelemetryController::class, 'storageConfig']);
    Route::post('/infrastructure/storage/test', [TelemetryController::class, 'testStorageConnection']);
    Route::get('/infrastructure/queues', [QueueMonitorController::class, 'index']);
    Route::post('/infrastructure/queues/{service}/retry/{job_id}', [QueueMonitorController::class, 'retry']);

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

    // Platform Maintenance Mode & Scheduled Downtime
    Route::get('/maintenance', [MaintenanceController::class, 'show']);
    Route::put('/maintenance', [MaintenanceController::class, 'update']);

    // Platform IP Allowlist, Rate-Limit & WAF Rules
    Route::get('/security/ip-rules', [IpRuleController::class, 'index']);
    Route::post('/security/ip-rules', [IpRuleController::class, 'store']);
    Route::delete('/security/ip-rules/{id}', [IpRuleController::class, 'destroy']);
});

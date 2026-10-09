<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerLoyaltyController;
use App\Http\Controllers\Api\V1\OutletController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\GiftCardController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\CrmController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MeetingRoomController;
use App\Http\Controllers\Api\V1\MeetingMinutesController;
use App\Http\Controllers\Api\V1\MeetingTelegramController;
use App\Http\Controllers\Api\V1\TelemetryController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TenantModuleController;
use App\Http\Controllers\Api\V1\PlatformModuleController;

Route::prefix('v1')->group(function () {
    // Health Check Endpoint for Auth Microservice
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'service' => 'auth-service',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Public Auth Routes (with Brute-Force Rate Limiting)
    Route::middleware('auth_throttle:5,15')->group(function () {
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/quick-switch', [AuthController::class, 'quickSwitch']);
    });
    Route::post('/auth/register', [AuthController::class, 'register']);

    // Staff Invitation Verification & Acceptance (Public)
    Route::get('/auth/verify-invite', [AuthController::class, 'verifyInvite']);
    Route::post('/auth/accept-invite', [AuthController::class, 'acceptInvite']);

    // Public Tenant Self-Registration
    Route::post('/tenants/register', [TenantController::class, 'register']);

    // Public Platform Modules Catalog
    Route::get('/platform-modules', [PlatformModuleController::class, 'index']);
    Route::get('/platform-modules/{id}', [PlatformModuleController::class, 'show']);

    // Protected Auth & Management API Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::match(['put', 'post'], '/auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('/auth/password', [AuthController::class, 'updatePassword']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Active Devices & Session Management
        Route::get('/auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('/auth/sessions/{id}', [AuthController::class, 'revokeSession']);
        Route::post('/auth/logout-all-devices', [AuthController::class, 'logoutAllDevices']);
        Route::post('/auth/2fa/toggle', [AuthController::class, 'toggle2fa']);

        // Subscription Quota Usage & Module Management
        Route::get('/tenants/quota-usage', [TenantController::class, 'quotaUsage']);
        Route::get('/tenants/profile', [TenantController::class, 'getProfile']);
        Route::match(['put', 'post'], '/tenants/profile', [TenantController::class, 'updateProfile']);
        Route::get('/tenants/modules', [TenantModuleController::class, 'index']);
        Route::put('/tenants/modules', [TenantModuleController::class, 'update']);

        // Developer / Merchant API Keys
        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);
        Route::put('/api-keys/{id}/toggle', [ApiKeyController::class, 'toggle']);

        // General Read-Only Organization Data for Authenticated Users
        Route::get('/outlets', [OutletController::class, 'index']);
        Route::get('/departments', [DepartmentController::class, 'index']);
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/customers', [CustomerController::class, 'index']);

        // CRM Operations
        Route::get('/crm/deals', [CrmController::class, 'deals']);
        Route::post('/crm/deals', [CrmController::class, 'storeDeal']);
        Route::put('/crm/deals/{id}', [CrmController::class, 'updateDeal']);
        Route::delete('/crm/deals/{id}', [CrmController::class, 'destroyDeal']);
        Route::get('/crm/leads', [CrmController::class, 'leads']);
        Route::post('/crm/leads', [CrmController::class, 'storeLead']);
        Route::get('/crm/activities', [CrmController::class, 'activities']);
        Route::post('/crm/activities', [CrmController::class, 'storeActivity']);
        Route::get('/crm/kpis', [CrmController::class, 'kpis']);

        // User & Staff Dynamic RBAC Management
        Route::middleware('role:organization,admin,super_admin,outlet_manager')->group(function () {
            Route::get('/roles', [RoleController::class, 'index']);
            Route::post('/roles', [RoleController::class, 'store']);
            Route::get('/roles/{id}', [RoleController::class, 'show']);
            Route::put('/roles/{id}', [RoleController::class, 'update']);
            Route::delete('/roles/{id}', [RoleController::class, 'destroy']);

            Route::get('/permissions', [PermissionController::class, 'index']);

            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/gift-cards', [GiftCardController::class, 'index']);
            Route::post('/gift-cards', [GiftCardController::class, 'store']);

            // User management with Quota Enforcement
            Route::get('/users', [UserController::class, 'index']);
            Route::get('/users/lookup', [UserController::class, 'lookup']);
            Route::get('/roles/permissions', [UserController::class, 'permissions']);
            Route::post('/users', [UserController::class, 'store'])->middleware('quota:users');
            Route::post('/users/invite', [UserController::class, 'invite'])->middleware('quota:users');
            Route::post('/users/attach', [UserController::class, 'attach'])->middleware('quota:users');
            Route::put('/users/{id}', [UserController::class, 'update']);
            Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
            Route::delete('/users/{id}', [UserController::class, 'destroy']);

            // Suppliers Mutations (Write)
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
            Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);

            // Customers Mutations & Loyalty (Write)
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::put('/customers/{id}', [CustomerController::class, 'update']);
            Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
            Route::post('/customers/{id}/store-credit', [CustomerLoyaltyController::class, 'adjustStoreCredit']);
            Route::post('/customers/{id}/loyalty', [CustomerLoyaltyController::class, 'adjustLoyaltyPoints']);
            Route::post('/customers/{id}/convert-points', [CustomerLoyaltyController::class, 'convertPointsToCredit']);
            Route::get('/customers/{id}/history', [CustomerLoyaltyController::class, 'getHistory']);

            // Outlets Management with Quota Enforcement
            Route::post('/outlets', [OutletController::class, 'store'])->middleware('quota:outlets');
            Route::put('/outlets/{id}', [OutletController::class, 'update']);
            Route::delete('/outlets/{id}', [OutletController::class, 'destroy']);

            // Departments Management
            Route::post('/departments', [DepartmentController::class, 'store']);
            Route::put('/departments/{id}', [DepartmentController::class, 'update']);
            Route::delete('/departments/{id}', [DepartmentController::class, 'destroy']);

            // Employees CRUD
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::post('/employees', [EmployeeController::class, 'store']);
            Route::put('/employees/{id}', [EmployeeController::class, 'update']);
            Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);
        });

        // Attendance & Clock-In/Clock-Out Tracking
        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut']);

        // Meeting Management Systems (MMS) Routes
        Route::get('/meetings/stats', [MeetingController::class, 'stats']);
        Route::get('/meetings', [MeetingController::class, 'index']);
        Route::post('/meetings', [MeetingController::class, 'store']);
        Route::get('/meetings/{id}', [MeetingController::class, 'show']);
        Route::put('/meetings/{id}', [MeetingController::class, 'update']);
        Route::delete('/meetings/{id}', [MeetingController::class, 'destroy']);
        Route::post('/meetings/{id}/check-in', [MeetingController::class, 'checkIn']);
        Route::get('/meetings/{id}/ics', [MeetingController::class, 'exportIcs']);

        Route::get('/meeting-rooms', [MeetingRoomController::class, 'index']);
        Route::post('/meeting-rooms', [MeetingRoomController::class, 'store']);
        Route::put('/meeting-rooms/{id}', [MeetingRoomController::class, 'update']);
        Route::delete('/meeting-rooms/{id}', [MeetingRoomController::class, 'destroy']);

        Route::get('/meetings/{id}/minutes', [MeetingMinutesController::class, 'show']);
        Route::post('/meetings/{id}/minutes', [MeetingMinutesController::class, 'storeOrUpdate']);

        Route::get('/meetings/telegram/config', [MeetingTelegramController::class, 'show']);
        Route::put('/meetings/telegram/config', [MeetingTelegramController::class, 'update']);
        Route::post('/meetings/telegram/test', [MeetingTelegramController::class, 'testNotification']);


        // Supervisor PIN Verification Override
        Route::post('/auth/verify-pin', [UserController::class, 'verifyPin']);

        // Super Admin Tenant Management Routes
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
    });
});

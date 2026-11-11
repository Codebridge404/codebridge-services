<?php

use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MeetingIssueController;
use App\Http\Controllers\Api\V1\PlatformModuleController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Microservice Health Check
    |--------------------------------------------------------------------------
    */
    Route::get('/health', [HealthController::class, 'health']);

    /*
    |--------------------------------------------------------------------------
    | Public Endpoints & Kiosk Displays
    |--------------------------------------------------------------------------
    */
    Route::get('/platform-modules', [PlatformModuleController::class, 'index']);
    Route::get('/platform-modules/{id}', [PlatformModuleController::class, 'show']);
    Route::post('/meeting-rooms/{roomId}/issues/public', [MeetingIssueController::class, 'report']);
    Route::get('/meetings/room-display/{roomId}', [MeetingController::class, 'roomDisplay']);
    Route::post('/meetings/{id}/check-in/public', [MeetingController::class, 'checkIn'])
        ->middleware(['signed', 'throttle:30,1'])->name('meetings.public-check-in');

    /*
    |--------------------------------------------------------------------------
    | Authentication & Identity Routes
    |--------------------------------------------------------------------------
    */
    require __DIR__ . '/api/auth.php';

    /*
    |--------------------------------------------------------------------------
    | Workspace Provisioning (Authenticated Account Session Required; Pre-Workspace)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'pos_scope'])->group(function () {
        Route::post('/tenants/register', [TenantController::class, 'register']);
    });

    /*
    |--------------------------------------------------------------------------
    | Protected Routes (Authenticated via Sanctum)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'pos_scope', 'workspace'])->group(function () {
        require __DIR__ . '/api/users.php';
        require __DIR__ . '/api/roles.php';
        require __DIR__ . '/api/permissions.php';
        require __DIR__ . '/api/pos_pins.php';
        require __DIR__ . '/api/tenants.php';
        require __DIR__ . '/api/api_keys.php';
        require __DIR__ . '/api/api_clients.php';
        require __DIR__ . '/api/organization.php';
        require __DIR__ . '/api/notification_providers.php';
        require __DIR__ . '/api/crm.php';
        require __DIR__ . '/api/security_events.php';
        require __DIR__ . '/api/request_logs.php';
        require __DIR__ . '/api/refresh_tokens.php';
        require __DIR__ . '/api/user_sessions.php';
        require __DIR__ . '/api/telegram_alerts.php';
        Route::middleware('meeting_access')->group(function () {
            require __DIR__ . '/api/meetings.php';
        });
        require __DIR__ . '/api/super_admin.php';
        require __DIR__ . '/api/admin.php';
    });
});

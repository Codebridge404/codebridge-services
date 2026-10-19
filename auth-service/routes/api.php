<?php

use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MeetingIssueController;
use App\Http\Controllers\Api\V1\PlatformModuleController;
use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Microservice Health Check
    |--------------------------------------------------------------------------
    */
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'service' => 'auth-service',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Public Endpoints & Kiosk Displays
    |--------------------------------------------------------------------------
    */
    Route::post('/tenants/register', [TenantController::class, 'register']);
    Route::get('/platform-modules', [PlatformModuleController::class, 'index']);
    Route::get('/platform-modules/{id}', [PlatformModuleController::class, 'show']);
    Route::post('/meeting-rooms/{roomId}/issues/public', [MeetingIssueController::class, 'report']);
    Route::get('/meetings/room-display/{roomId}', [MeetingController::class, 'roomDisplay']);
    Route::post('/meetings/{id}/check-in/public', [MeetingController::class, 'checkIn']);

    /*
    |--------------------------------------------------------------------------
    | Authentication & Identity Routes
    |--------------------------------------------------------------------------
    */
    require __DIR__ . '/api/auth.php';

    /*
    |--------------------------------------------------------------------------
    | Protected Routes (Authenticated via Sanctum)
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function () {
        require __DIR__ . '/api/users.php';
        require __DIR__ . '/api/roles.php';
        require __DIR__ . '/api/permissions.php';
        require __DIR__ . '/api/pos_pins.php';
        require __DIR__ . '/api/sessions.php';
        require __DIR__ . '/api/tenants.php';
        require __DIR__ . '/api/api_keys.php';
        require __DIR__ . '/api/organization.php';
        require __DIR__ . '/api/crm.php';
        require __DIR__ . '/api/meetings.php';
        require __DIR__ . '/api/super_admin.php';
    });
});

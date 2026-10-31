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
        try {
            \Illuminate\Support\Facades\DB::select('SELECT 1');
            $probeKey = 'health:' . \Illuminate\Support\Str::uuid();
            \Illuminate\Support\Facades\Cache::put($probeKey, 'ready', 10);
            $cacheReady = \Illuminate\Support\Facades\Cache::get($probeKey) === 'ready';
            \Illuminate\Support\Facades\Cache::forget($probeKey);
            if (!$cacheReady) throw new \RuntimeException('Cache unavailable');
        } catch (\Throwable $e) {
            return response()->json(['status' => 'unhealthy', 'service' => 'auth-service'], 503);
        }
        return response()->json([
            'status' => 'healthy',
            'service' => 'auth-service',
            'version' => config('app.version', '1.0.0'),
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
        require __DIR__ . '/api/organization.php';
        require __DIR__ . '/api/crm.php';
        Route::middleware('meeting_access')->group(function () {
            require __DIR__ . '/api/meetings.php';
        });
        require __DIR__ . '/api/super_admin.php';
        require __DIR__ . '/api/admin.php';
    });
});

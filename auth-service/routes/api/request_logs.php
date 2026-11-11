<?php

use App\Http\Controllers\Api\V1\ApiRequestLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Request Logs & Performance Telemetry Routes
| Accessible by super_admin, organization, and tenant admin
|--------------------------------------------------------------------------
*/
Route::middleware('role:super_admin,organization,admin')->prefix('request-logs')->group(function () {
    Route::get('/stats', [ApiRequestLogController::class, 'stats']);
    Route::get('/', [ApiRequestLogController::class, 'index']);
    Route::get('/{id}', [ApiRequestLogController::class, 'show']);
});


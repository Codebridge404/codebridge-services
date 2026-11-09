<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Executive Dashboard & Business Intelligence Reports (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('role:organization,organization_owner,owner,admin,super_admin,outlet_manager,accountant,supervisor')->group(function () {
        Route::get('/admin/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/admin/dashboard/widgets', [DashboardController::class, 'widgets']);
        Route::get('/admin/dashboard/charts', [DashboardController::class, 'charts']);

        Route::get('/reports/tax', [ReportController::class, 'tax']);
        Route::get('/reports/tax-audit', [ReportController::class, 'taxAudit']);
        Route::post('/reports/tax-audit/async', [ReportController::class, 'queueTaxAudit']);
        Route::get('/reports/export-status/{id}', [ReportController::class, 'getExportStatus']);
        Route::get('/reports/export-download/{id}', [ReportController::class, 'downloadExport']);
        Route::get('/reports/export', [ReportController::class, 'export']);
    });

    Route::middleware('role:organization,organization_owner,owner,admin,super_admin,outlet_manager,accountant,supervisor,cashier,inventory_clerk')->group(function () {
        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::get('/reports/shifts', [ReportController::class, 'shifts']);
    });
});

<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\UpdateMaintenanceRequest;
use App\Models\System\PlatformMaintenanceSetting;
use App\Services\MaintenanceModeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform Maintenance Mode & Scheduled Downtime Controller.
 */
class MaintenanceController extends Controller
{
    public function __construct(
        protected MaintenanceModeService $maintenanceService
    ) {}

    /**
     * Read current maintenance schedule & banner configuration (Super Admin).
     * GET /api/v1/super-admin/maintenance
     */
    public function show(): JsonResponse
    {
        return $this->successResponse(
            data: $this->maintenanceService->getConfig(),
            message: 'Maintenance configuration retrieved successfully.'
        );
    }

    /**
     * Update global maintenance schedule & mutation blocking policy (Super Admin).
     * PUT /api/v1/super-admin/maintenance
     */
    public function update(UpdateMaintenanceRequest $request): JsonResponse
    {
        $config = $this->maintenanceService->updateConfig(
            $request->validated(),
            $request->user()
        );

        $this->recordAuditLog(
            action: 'maintenance.updated',
            module: 'platform',
            entityType: PlatformMaintenanceSetting::class,
            entityId: 'global',
            payload: $config,
            details: $config['enabled']
                ? 'Platform maintenance mode configured (status: '.$config['status'].')'
                : 'Platform maintenance mode disabled'
        );

        return $this->successResponse(
            data: $config,
            message: 'Maintenance configuration updated successfully.'
        );
    }

    /**
     * Public maintenance banner status for frontend & POS terminals.
     * GET /api/v1/maintenance/status
     */
    public function publicStatus(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: $this->maintenanceService->getConfig(),
            message: 'Maintenance status retrieved successfully.'
        );
    }
}

<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\InfrastructureTelemetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [BD-0079] System Telemetry, Health Metrics & Runtime Telemetry Controller
 *
 * Provides live cluster telemetry, real host/container CPU & memory metrics,
 * dual-database metrics (codebridge_auth & codebridge_operations),
 * live Redis INFO metrics, and real MinIO/S3 storage verification.
 */
class TelemetryController extends Controller
{
    public function __construct(
        protected ?InfrastructureTelemetryService $telemetry = null
    ) {
        $this->telemetry = $telemetry ?? app(InfrastructureTelemetryService::class);
    }

    /**
     * Get platform infrastructure telemetry, container statuses, and dual-database metrics.
     * GET /api/v1/super-admin/infrastructure/telemetry
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->telemetry->collectClusterTelemetry(),
        ]);
    }

    /**
     * Get MinIO / S3 Object Storage Configuration and Status
     * GET /api/v1/super-admin/infrastructure/storage
     *
     * @ticket BD-0136
     */
    public function storageConfig(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->telemetry->collectStorageStatus(),
        ]);
    }

    /**
     * Ping & Test Object Storage Connectivity (Real S3 Read/Write/Delete Probe)
     * POST /api/v1/super-admin/infrastructure/storage/test
     *
     * @ticket BD-0136
     */
    public function testStorageConnection(Request $request): JsonResponse
    {
        $requestedEndpoint = $request->input('endpoint');
        $result = $this->telemetry->testStorageConnectivity(
            is_string($requestedEndpoint) ? $requestedEndpoint : null
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['http_status']);
    }
}

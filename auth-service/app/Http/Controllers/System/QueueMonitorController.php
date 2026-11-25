<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\QueueMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-Service Queue, Cron & Failed Jobs Monitor Controller (Super Admin).
 */
class QueueMonitorController extends Controller
{
    public function __construct(
        protected QueueMonitorService $queueMonitor
    ) {}

    /**
     * View queue, failed jobs, worker, and scheduler heartbeat status across services.
     * GET /api/v1/super-admin/infrastructure/queues
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            data: $this->queueMonitor->getOverview(),
            message: 'Queue and scheduler status retrieved successfully.'
        );
    }

    /**
     * Retry a failed job on a specific service and record an audit log.
     * POST /api/v1/super-admin/infrastructure/queues/{service}/retry/{job_id}
     */
    public function retry(Request $request, string $service, string $jobId): JsonResponse
    {
        $result = $this->queueMonitor->retryFailedJob($service, $jobId);

        $this->recordAuditLog(
            action: 'queue.job_retry',
            module: 'infrastructure',
            entityType: 'failed_job',
            entityId: "{$service}:{$jobId}",
            payload: [
                'service' => $service,
                'job_id' => $jobId,
                'outcome' => $result['status'],
                'http_status' => $result['http_status'],
            ],
            details: "Admin triggered failed job retry on {$service} (job {$jobId}): {$result['status']}"
        );

        if ($result['status'] !== 'success') {
            return $this->errorResponse(
                message: $result['message'],
                statusCode: $result['http_status'],
                errorCode: $result['code'] ?? 'QUEUE_RETRY_FAILED'
            );
        }

        return $this->successResponse(
            data: $result['data'] ?? [],
            message: $result['message']
        );
    }
}

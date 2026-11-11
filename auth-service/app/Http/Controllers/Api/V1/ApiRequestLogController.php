<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiRequestLog\ApiRequestLogResource;
use App\Models\ApiRequestLog;
use App\Services\UserAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0063] API Request Traffic & Latency Inspection Controller
 *
 * Provides administrative oversight into API request traffic, status codes,
 * response latencies, and service telemetry across CodeBridge endpoints.
 */
class ApiRequestLogController extends Controller
{
    /**
     * List paginated API request telemetry logs with forensic filters.
     * GET /api/v1/request-logs
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = ApiRequestLog::with(['user:id,name,email']);

        // Strict multi-tenant isolation
        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->where('user_id', $user?->id);
            }
        } elseif ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->integer('tenant_id'));
        }

        // Filter by HTTP Method
        if ($request->filled('method')) {
            $query->where('method', strtoupper($request->input('method')));
        }

        // Filter by Status Code or Status Class (e.g. 500 or 5xx, 4xx, 2xx)
        if ($request->filled('status_code')) {
            $status = $request->input('status_code');
            if (is_numeric($status)) {
                $query->where('status_code', (int) $status);
            } elseif (str_ends_with($status, 'xx')) {
                $prefix = (int) substr($status, 0, 1);
                $query->whereBetween('status_code', [$prefix * 100, ($prefix * 100) + 99]);
            }
        }

        // Filter by minimum latency (duration_ms)
        if ($request->filled('min_duration_ms')) {
            $query->where('duration_ms', '>=', $request->integer('min_duration_ms'));
        }

        // Filter by error code
        if ($request->filled('error_code')) {
            $query->where('error_code', $request->input('error_code'));
        }

        // Filter by path / endpoint
        if ($request->filled('path')) {
            $query->where('path', 'like', '%' . trim($request->input('path')) . '%');
        }

        // Date range filter
        if ($request->filled('from_date') || $request->filled('from')) {
            $from = $request->input('from_date') ?: $request->input('from');
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($request->filled('to_date') || $request->filled('to')) {
            $to = $request->input('to_date') ?: $request->input('to');
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        // Search in path, IP address, request_id, or error_message
        if ($request->filled('search')) {
            $search = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('path', 'like', $search)
                    ->orWhere('ip_address', 'like', $search)
                    ->orWhere('request_id', 'like', $search)
                    ->orWhere('error_message', 'like', $search);
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginator = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => ApiRequestLogResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'message' => 'API request logs retrieved successfully.',
        ]);
    }

    /**
     * Get aggregate traffic and performance telemetry metrics.
     * GET /api/v1/request-logs/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $baseQuery = DB::table('api_request_logs');

        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $baseQuery->where('tenant_id', $tenantId);
            } else {
                $baseQuery->where('user_id', $user?->id);
            }
        } elseif ($request->filled('tenant_id')) {
            $baseQuery->where('tenant_id', $request->integer('tenant_id'));
        }

        $totalRequests = (clone $baseQuery)->count();

        // Calculate average duration
        $avgDuration = (clone $baseQuery)->avg('duration_ms');

        // Status code distribution
        $statusDistribution = [
            '2xx' => (clone $baseQuery)->whereBetween('status_code', [200, 299])->count(),
            '3xx' => (clone $baseQuery)->whereBetween('status_code', [300, 399])->count(),
            '4xx' => (clone $baseQuery)->whereBetween('status_code', [400, 499])->count(),
            '5xx' => (clone $baseQuery)->whereBetween('status_code', [500, 599])->count(),
        ];

        // Slow requests count (> 500ms)
        $slowRequests = (clone $baseQuery)->where('duration_ms', '>=', 500)->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_requests' => $totalRequests,
                'avg_duration_ms' => round((float) $avgDuration, 2),
                'status_distribution' => $statusDistribution,
                'slow_requests_count' => $slowRequests,
            ],
            'message' => 'API request metrics retrieved successfully.',
        ]);
    }

    /**
     * Inspect a specific request log.
     * GET /api/v1/request-logs/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = ApiRequestLog::with(['user:id,name,email']);

        if (is_numeric($id)) {
            $query->where('id', (int) $id);
        } else {
            $query->where('uuid', $id);
        }

        // Multi-tenant check
        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->where('user_id', $user?->id);
            }
        }

        $log = $query->first();

        if (! $log) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'API request log not found or access denied.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => new ApiRequestLogResource($log),
            'message' => 'API request log details retrieved successfully.',
        ]);
    }
}


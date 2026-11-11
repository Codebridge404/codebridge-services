<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserSession\UserSessionResource;
use App\Models\UserSession;
use App\Services\UserAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0065] User Session Management & Multi-Device Governance Controller
 *
 * Provides administrative oversight into active user sessions, device tokens,
 * concurrency limits, and forced session termination.
 */
class UserSessionController extends Controller
{
    /**
     * List paginated user sessions.
     * GET /api/v1/user-sessions
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = UserSession::with(['user:id,name,email', 'apiClient:id,name,client_type', 'tenant:id,name']);

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

        // Filter by user_id
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        // Filter by api_client_id
        if ($request->filled('api_client_id')) {
            $query->where('api_client_id', $request->integer('api_client_id'));
        }

        // Filter by status: active, revoked, expired
        if ($request->filled('status')) {
            $status = strtolower($request->input('status'));
            $now = Carbon::now();

            if ($status === 'active') {
                $query->whereNull('revoked_at')
                    ->where(function ($q) use ($now) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                    });
            } elseif ($status === 'revoked') {
                $query->whereNotNull('revoked_at');
            } elseif ($status === 'expired') {
                $query->whereNotNull('expires_at')->where('expires_at', '<=', $now);
            }
        }

        // Search device or IP
        if ($request->filled('search')) {
            $search = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('device_name', 'like', $search)
                    ->orWhere('ip_address', 'like', $search)
                    ->orWhere('user_agent', 'like', $search);
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginator = $query->orderBy('last_seen_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => UserSessionResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'message' => 'User sessions retrieved successfully.',
        ]);
    }

    /**
     * Get aggregate statistics on user sessions.
     * GET /api/v1/user-sessions/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = DB::table('user_sessions');

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

        $now = now();
        $total = (clone $query)->count();
        $active = (clone $query)
            ->whereNull('revoked_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })->count();

        $revoked = (clone $query)->whereNotNull('revoked_at')->count();
        $expired = (clone $query)->whereNotNull('expires_at')->where('expires_at', '<=', $now)->count();

        // Unique active users
        $activeUsers = (clone $query)
            ->whereNull('revoked_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->distinct('user_id')
            ->count('user_id');

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_sessions' => $total,
                'active_sessions' => $active,
                'revoked_sessions' => $revoked,
                'expired_sessions' => $expired,
                'active_users_count' => $activeUsers,
            ],
            'message' => 'User session statistics retrieved successfully.',
        ]);
    }

    /**
     * Inspect a specific user session.
     * GET /api/v1/user-sessions/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = UserSession::with(['user:id,name,email', 'apiClient:id,name,client_type', 'tenant:id,name', 'activeRefreshToken']);

        if (is_numeric($id)) {
            $query->where('id', (int) $id);
        } else {
            $query->where('uuid', $id);
        }

        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->where('user_id', $user?->id);
            }
        }

        $session = $query->first();

        if (! $session) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'User session not found or access denied.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => new UserSessionResource($session),
            'message' => 'User session details retrieved successfully.',
        ]);
    }

    /**
     * Terminate / Revoke a specific user session.
     * POST /api/v1/user-sessions/{id}/revoke
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = UserSession::query();

        if (is_numeric($id)) {
            $query->where('id', (int) $id);
        } else {
            $query->where('uuid', $id);
        }

        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->where('user_id', $user?->id);
            }
        }

        $session = $query->first();

        if (! $session) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'User session not found or access denied.',
            ], 404);
        }

        $reason = $request->input('reason', 'administrative_revocation');
        $session->revoke($reason);

        $this->recordAuditLog(
            action: 'user_session_revoked',
            module: 'auth',
            entityType: UserSession::class,
            entityId: (string) $session->id,
            details: "User session {$session->uuid} revoked by {$user?->name}: {$reason}"
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User session revoked successfully.',
        ]);
    }

    /**
     * Terminate all active sessions for a target user or all users in tenant.
     * POST /api/v1/user-sessions/revoke-all
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = UserSession::whereNull('revoked_at');

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

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        $reason = $request->input('reason', 'bulk_administrative_revocation');
        $count = 0;

        $query->chunk(50, function ($sessions) use (&$count, $reason) {
            foreach ($sessions as $session) {
                $session->revoke($reason);
                $count++;
            }
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'revoked_count' => $count,
            ],
            'message' => "Successfully revoked {$count} active user sessions.",
        ]);
    }
}


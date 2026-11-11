<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RefreshToken\RefreshTokenResource;
use App\Models\RefreshToken;
use App\Models\UserSession;
use App\Services\UserAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0064] Refresh Token Lifecycle, Rotation Lineage & Replay Auditing Controller
 *
 * Provides administrative oversight into enterprise rotating refresh token families,
 * lineage tracking, and manual revocation capabilities.
 */
class RefreshTokenController extends Controller
{
    /**
     * List paginated refresh tokens with lineage filters.
     * GET /api/v1/refresh-tokens
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = RefreshToken::with(['session.user:id,name,email', 'session.apiClient:id,name']);

        // Multi-tenant isolation: join session to check tenant ownership
        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->whereHas('session', function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                });
            } else {
                $query->whereHas('session', function ($q) use ($user) {
                    $q->where('user_id', $user?->id);
                });
            }
        } elseif ($request->filled('tenant_id')) {
            $tId = $request->integer('tenant_id');
            $query->whereHas('session', function ($q) use ($tId) {
                $q->where('tenant_id', $tId);
            });
        }

        // Filter by user_id
        if ($request->filled('user_id')) {
            $uId = $request->integer('user_id');
            $query->whereHas('session', function ($q) use ($uId) {
                $q->where('user_id', $uId);
            });
        }

        // Filter by session_id
        if ($request->filled('session_id')) {
            $query->where('session_id', $request->integer('session_id'));
        }

        // Filter by status: active, consumed, revoked, expired
        if ($request->filled('status')) {
            $status = strtolower($request->input('status'));
            $now = Carbon::now();

            if ($status === 'active') {
                $query->whereNull('consumed_at')
                    ->whereNull('revoked_at')
                    ->where(function ($q) use ($now) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                    });
            } elseif ($status === 'consumed') {
                $query->whereNotNull('consumed_at');
            } elseif ($status === 'revoked') {
                $query->whereNotNull('revoked_at');
            } elseif ($status === 'expired') {
                $query->whereNotNull('expires_at')->where('expires_at', '<=', $now);
            }
        }

        // Date range
        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('from_date'))->startOfDay());
        }
        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('to_date'))->endOfDay());
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginator = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => RefreshTokenResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'message' => 'Refresh tokens retrieved successfully.',
        ]);
    }

    /**
     * Get aggregate refresh token metrics.
     * GET /api/v1/refresh-tokens/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = DB::table('refresh_tokens as rt')
            ->join('user_sessions as us', 'rt.session_id', '=', 'us.id');

        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->where('us.tenant_id', $tenantId);
            } else {
                $query->where('us.user_id', $user?->id);
            }
        } elseif ($request->filled('tenant_id')) {
            $query->where('us.tenant_id', $request->integer('tenant_id'));
        }

        $now = now();
        $total = (clone $query)->count();
        $active = (clone $query)
            ->whereNull('rt.consumed_at')
            ->whereNull('rt.revoked_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('rt.expires_at')->orWhere('rt.expires_at', '>', $now);
            })->count();

        $consumed = (clone $query)->whereNotNull('rt.consumed_at')->count();
        $revoked = (clone $query)->whereNotNull('rt.revoked_at')->count();
        $expired = (clone $query)->whereNotNull('rt.expires_at')->where('rt.expires_at', '<=', $now)->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_tokens' => $total,
                'active_count' => $active,
                'consumed_count' => $consumed,
                'revoked_count' => $revoked,
                'expired_count' => $expired,
            ],
            'message' => 'Refresh token statistics retrieved successfully.',
        ]);
    }

    /**
     * Inspect a specific refresh token and its rotation lineage.
     * GET /api/v1/refresh-tokens/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = RefreshToken::with(['session.user:id,name,email', 'session.apiClient:id,name', 'parent', 'successor']);

        if (is_numeric($id)) {
            $query->where('id', (int) $id);
        } else {
            $query->where('token_id', $id);
        }

        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->whereHas('session', function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                });
            } else {
                $query->whereHas('session', function ($q) use ($user) {
                    $q->where('user_id', $user?->id);
                });
            }
        }

        $token = $query->first();

        if (! $token) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'Refresh token not found or access denied.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => new RefreshTokenResource($token),
            'message' => 'Refresh token details retrieved successfully.',
        ]);
    }

    /**
     * Revoke a refresh token and its session family lineage.
     * POST /api/v1/refresh-tokens/{id}/revoke
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = RefreshToken::with('session');

        if (is_numeric($id)) {
            $query->where('id', (int) $id);
        } else {
            $query->where('token_id', $id);
        }

        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->whereHas('session', function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                });
            } else {
                $query->whereHas('session', function ($q) use ($user) {
                    $q->where('user_id', $user?->id);
                });
            }
        }

        $token = $query->first();

        if (! $token) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'Refresh token not found or access denied.',
            ], 404);
        }

        // Revoke token and its entire session family
        DB::transaction(function () use ($token) {
            $token->update(['revoked_at' => now()]);
            if ($token->session) {
                $token->session->revoke('administrative_revocation');
            }
        });

        $this->recordAuditLog(
            action: 'refresh_token_revoked',
            module: 'auth',
            entityType: RefreshToken::class,
            entityId: (string) $token->id,
            details: "Refresh token #{$token->id} revoked by admin {$user?->name}"
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Refresh token and session family revoked successfully.',
        ]);
    }
}


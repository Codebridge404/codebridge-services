<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserSession\UserSessionResource;
use App\Models\Auth\UserSession;
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
                $query->where(function ($q) use ($tenantId, $user) {
                    $q->where('tenant_id', $tenantId)
                        ->orWhere(function ($sub) use ($user) {
                            $sub->whereNull('tenant_id')
                                ->where('user_id', $user?->id);
                        });
                });
            } else {
                $query->where('user_id', $user?->id);
            }
        } elseif ($request->filled('tenant_id') && $request->integer('tenant_id') > 0) {
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

        // Search device, IP, or location
        if ($request->filled('search')) {
            $search = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('device_name', 'like', $search)
                    ->orWhere('ip_address', 'like', $search)
                    ->orWhere('location', 'like', $search)
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
                $query->where(function ($q) use ($tenantId, $user) {
                    $q->where('tenant_id', $tenantId)
                        ->orWhere(function ($sub) use ($user) {
                            $sub->whereNull('tenant_id')
                                ->where('user_id', $user?->id);
                        });
                });
            } else {
                $query->where('user_id', $user?->id);
            }
        } elseif ($request->filled('tenant_id') && $request->integer('tenant_id') > 0) {
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
                $query->where(function ($q) use ($tenantId, $user) {
                    $q->where('tenant_id', $tenantId)
                        ->orWhere(function ($sub) use ($user) {
                            $sub->whereNull('tenant_id')
                                ->where('user_id', $user?->id);
                        });
                });
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
                $query->where(function ($q) use ($tenantId, $user) {
                    $q->where('tenant_id', $tenantId)
                        ->orWhere(function ($sub) use ($user) {
                            $sub->whereNull('tenant_id')
                                ->where('user_id', $user?->id);
                        });
                });
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
                $query->where(function ($q) use ($tenantId, $user) {
                    $q->where('tenant_id', $tenantId)
                        ->orWhere(function ($sub) use ($user) {
                            $sub->whereNull('tenant_id')
                                ->where('user_id', $user?->id);
                        });
                });
            } else {
                $query->where('user_id', $user?->id);
            }
        } elseif ($request->filled('tenant_id') && $request->integer('tenant_id') > 0) {
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

    /**
     * [BD-0172] Update current active session & device with exact GPS area location.
     * PATCH /api/v1/auth/sessions/location
     */
    public function updateCurrentLocation(Request $request, \App\Services\DeviceDetectorService $detector): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $geo = $detector->resolveDetailedLocation(
            $request,
            (float) $validated['latitude'],
            (float) $validated['longitude']
        );

        $session = UserSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();

        if ($session) {
            $updates = [
                'location' => $geo['location'],
                'last_seen_at' => Carbon::now(),
            ];
            if (\App\Support\SchemaGuard::hasColumn('user_sessions', 'latitude')) {
                $updates['latitude'] = $geo['latitude'];
            }
            if (\App\Support\SchemaGuard::hasColumn('user_sessions', 'longitude')) {
                $updates['longitude'] = $geo['longitude'];
            }
            if (\App\Support\SchemaGuard::hasColumn('user_sessions', 'location_source')) {
                $updates['location_source'] = $geo['source'];
            }
            $session->update($updates);

            if ($session->user_device_id) {
                $deviceUpdates = ['last_location' => $geo['location']];
                if (\App\Support\SchemaGuard::hasColumn('user_devices', 'latitude')) {
                    $deviceUpdates['latitude'] = $geo['latitude'];
                }
                if (\App\Support\SchemaGuard::hasColumn('user_devices', 'longitude')) {
                    $deviceUpdates['longitude'] = $geo['longitude'];
                }
                if (\App\Support\SchemaGuard::hasColumn('user_devices', 'location_source')) {
                    $deviceUpdates['location_source'] = $geo['source'];
                }
                \App\Models\Auth\UserDevice::where('id', $session->user_device_id)->update($deviceUpdates);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $geo,
        ]);
    }
}


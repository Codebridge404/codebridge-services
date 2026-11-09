<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use App\Services\TenantAuthorizationService;
use App\Support\SchemaGuard;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0111] Operations Distributed JWT Authentication & Verification Middleware
 *
 * @ticket BD-0111
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Middleware/AuthenticateJwt.php
 * @related-model App\Services\JwtService, App\Services\AuthServiceClient
 * @entry-point AuthenticateJwt::handle()
 * @routes
 *  - Middleware: jwt.auth
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Bearer Token Parsing): Extracts Authorization Bearer token header from incoming microservice request.
 * Step 2 (Cryptographic Signature Verification): Validates token signature locally using shared HMAC-SHA256 secret.
 * Step 3 (Token Purpose Enforcement): Rejects refresh tokens or non-access tokens from authenticating endpoints.
 * Step 4 (Revocation & Expiration Check): Verifies token JTI blacklist and user session revocation.
 * Step 5 (Tenant Scoping Injection): Injects user_id, tenant_id, and outlet_id into request attributes.
 */
class AuthenticateJwt
{
    public function __construct(
        protected JwtService $jwtService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token && substr_count($token, '.') === 2) {
            $payload = $this->jwtService->verify($token);

            if ($payload && !empty($payload['sub'])) {
                // 1. Strictly enforce Token Purpose: require explicit token_use=access
                $tokenUse = $payload['token_use'] ?? null;
                if ($tokenUse !== 'access') {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'INVALID_TOKEN_PURPOSE',
                        'message' => 'Invalid token purpose: only access tokens can authenticate API endpoints.',
                    ], 401);
                }

                $revocationCache = $this->sharedRevocationCache();

                // 2. Enforce Token Revocation via Cache (Blacklist JTI)
                $jti = $payload['jti'] ?? null;
                if ($jti && ($this->isJtiRevoked((string) $jti, $revocationCache))) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'TOKEN_REVOKED',
                        'message' => 'Token has been revoked. Please sign in again.',
                    ], 401);
                }

                // 3. Enforce User Global Session Revocation
                $userId = $payload['sub'];
                $tokenIat = (int) ($payload['iat'] ?? 0);
                $revokedAt = $this->getMaxNumericCacheValue("revoked_user_sessions:{$userId}", $revocationCache);
                if ($revokedAt !== null && $tokenIat <= $revokedAt) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'SESSION_REVOKED',
                        'message' => 'User session has been revoked. Please sign in again.',
                    ], 401);
                }

                // Check session version if present in token claims
                if (isset($payload['session_version'])) {
                    $currentVersion = (int) ($this->getMaxNumericCacheValue("session_version:{$userId}", $revocationCache) ?? 0);
                    if ((int) $payload['session_version'] < $currentVersion) {
                        return response()->json([
                            'status' => 'error',
                            'code' => 'SESSION_SUPERSEDED',
                            'message' => 'Session has expired or was revoked by a newer login.',
                        ], 401);
                    }
                }

                // 4. Verify Auth-owned session UUID (sid) revocation/expiration via cache and auth_db
                $sid = $payload['sid'] ?? null;
                if (is_string($sid) && $sid !== '') {
                    if ($this->isSidRevoked($sid, $revocationCache)) {
                        return response()->json([
                            'status' => 'error',
                            'code' => 'SESSION_REVOKED',
                            'message' => 'Session has expired or been revoked. Please sign in again.',
                        ], 401);
                    }

                    if (Str::isUuid($sid) && !config('services.auth.verify_via_http', false)) {
                        if ($this->isSessionRevokedInAuthDb($sid, (string) $userId)) {
                            return response()->json([
                                'status' => 'error',
                                'code' => 'SESSION_REVOKED',
                                'message' => 'Session has expired or been revoked. Please sign in again.',
                            ], 401);
                        }
                    }
                }

                $userId = (int) $payload['sub'];
                $claimRole = is_string($payload['role'] ?? null) ? $payload['role'] : 'user';
                $claimTenantId = TenantAuthorizationService::normalizeTenantId($payload['tenant_id'] ?? null);
                $userOutletId = isset($payload['outlet_id']) && is_numeric($payload['outlet_id']) ? (int) $payload['outlet_id'] : null;

                // Construct lightweight candidate user subject before resolving authoritative tenant-scoped Auth context
                $probeUser = new User();
                $probeUser->forceFill([
                    'name' => $payload['name'] ?? 'Authorized User',
                    'email' => $payload['email'] ?? '',
                    'role' => $claimRole,
                    'tenant_id' => $claimTenantId,
                    'outlet_id' => $userOutletId,
                ]);
                $probeUser->id = $userId;
                $probeUser->exists = true;
                $probeUser->pendingRole = $claimRole;
                $probeUser->pendingTenantId = $claimTenantId;
                $probeUser->pendingOutletId = $userOutletId;

                $context = TenantAuthorizationService::resolveAuthorizationContext(
                    $request,
                    $probeUser,
                    $payload,
                    false
                );

                if (empty($context['authorized'])) {
                    return response()->json([
                        'status' => 'error',
                        'code' => $context['code'] ?? 'UNAUTHORIZED',
                        'message' => $context['message'] ?? 'Unauthorized.',
                    ], (int) ($context['status'] ?? 403));
                }

                $dbUser = $context['db_user'] ?? null;
                $resolvedRole = $context['role'] ?? 'user';
                $resolvedTenantId = $context['tenant_id'] ?? null;
                $resolvedOutletId = $context['outlet_id'] ?? $userOutletId;

                // Construct authenticated user with verified tenant-scoped credentials
                $user = new User();
                $user->forceFill([
                    'name' => $dbUser->name ?? ($payload['name'] ?? 'Authorized User'),
                    'email' => $dbUser->email ?? ($payload['email'] ?? ''),
                    'role' => $resolvedRole,
                    'tenant_id' => $resolvedTenantId,
                    'outlet_id' => $resolvedOutletId,
                ]);
                $user->id = (int) ($context['user_id'] ?? $userId);
                $user->exists = true;
                $user->pendingRole = $resolvedRole;
                $user->pendingTenantId = $resolvedTenantId;
                $user->pendingOutletId = $resolvedOutletId;
                $user->scopedRoles = is_array($context['roles'] ?? null) ? $context['roles'] : [$resolvedRole];
                $user->isPlatformSuperAdmin = (bool) ($context['is_platform_super_admin'] ?? false);
                $user->resolvedSuperAdmin = (bool) ($context['is_super_admin'] ?? false);

                $request->attributes->set('jwt_claims', $payload);
                $request->attributes->set('auth_context', $context);

                // Propagate user across both default and sanctum guards
                Auth::setUser($user);
                Auth::guard('sanctum')->setUser($user);
                $request->setUserResolver(fn () => $user);
            }
        }

        return $next($request);
    }

    protected function sharedRevocationCache(): ?\Illuminate\Contracts\Cache\Repository
    {
        if (!config('cache.stores.revocation.enabled', false) && config('cache.default') !== 'redis') {
            return null;
        }

        try {
            return Cache::store('revocation');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isJtiRevoked(string $jti, ?\Illuminate\Contracts\Cache\Repository $revocationCache): bool
    {
        if (Cache::get("revoked_jwt:{$jti}")) {
            return true;
        }

        if ($revocationCache) {
            try {
                return (bool) $revocationCache->get("revoked_jwt:{$jti}", false);
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    protected function isSidRevoked(string $sid, ?\Illuminate\Contracts\Cache\Repository $revocationCache): bool
    {
        if (Cache::get("revoked_sid:{$sid}")) {
            return true;
        }

        if ($revocationCache) {
            try {
                return (bool) $revocationCache->get("revoked_sid:{$sid}", false);
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    protected function getMaxNumericCacheValue(string $key, ?\Illuminate\Contracts\Cache\Repository $revocationCache): ?int
    {
        $local = Cache::get($key);
        $remote = null;

        if ($revocationCache) {
            try {
                $remote = $revocationCache->get($key);
            } catch (\Throwable) {
                $remote = null;
            }
        }

        if ($local === null && $remote === null) {
            return null;
        }

        return max(
            $local !== null ? (int) $local : PHP_INT_MIN,
            $remote !== null ? (int) $remote : PHP_INT_MIN
        );
    }

    protected function isSessionRevokedInAuthDb(string $sid, string $userId): bool
    {
        $conn = TenantAuthorizationService::authConnectionName();
        $isSqlite = config('database.default') === 'sqlite'
            || (config("database.connections.{$conn}.driver") === 'sqlite');

        try {
            if (!SchemaGuard::hasTable('user_sessions', $conn)) {
                return !$isSqlite;
            }

            $session = DB::connection($conn)
                ->table('user_sessions')
                ->where('uuid', $sid)
                ->where('user_id', $userId)
                ->first(['id', 'revoked_at', 'expires_at']);

            if (!$session) {
                return true;
            }

            if (!empty($session->revoked_at)) {
                return true;
            }

            if (!empty($session->expires_at) && Carbon::parse($session->expires_at)->isPast()) {
                return true;
            }

            return false;
        } catch (\Throwable) {
            return !$isSqlite;
        }
    }
}

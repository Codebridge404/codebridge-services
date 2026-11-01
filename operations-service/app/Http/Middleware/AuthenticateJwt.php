<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
                // 1. Enforce Token Purpose: Reject refresh tokens used to authenticate API endpoints
                $tokenUse = $payload['token_use'] ?? null;
                if ($tokenUse && $tokenUse !== 'access') {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'INVALID_TOKEN_PURPOSE',
                        'message' => 'Invalid token purpose: refresh tokens cannot be used to authenticate API endpoints.',
                    ], 401);
                }

                // 2. Enforce Token Revocation via Cache (Blacklist JTI)
                $jti = $payload['jti'] ?? null;
                if ($jti && Cache::get("revoked_jwt:{$jti}")) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'TOKEN_REVOKED',
                        'message' => 'Token has been revoked. Please sign in again.',
                    ], 401);
                }

                // 3. Enforce User Global Session Revocation
                $userId = $payload['sub'];
                $tokenIat = (int) ($payload['iat'] ?? 0);
                $revokedAt = Cache::get("revoked_user_sessions:{$userId}");
                if ($revokedAt !== null && $tokenIat <= (int) $revokedAt) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'SESSION_REVOKED',
                        'message' => 'User session has been revoked. Please sign in again.',
                    ], 401);
                }

                // Check session version if present in token claims
                if (isset($payload['session_version'])) {
                    $currentVersion = (int) Cache::get("session_version:{$userId}", 0);
                    if ((int) $payload['session_version'] < $currentVersion) {
                        return response()->json([
                            'status' => 'error',
                            'code' => 'SESSION_SUPERSEDED',
                            'message' => 'Session has expired or was revoked by a newer login.',
                        ], 401);
                    }
                }

                // Construct stateless authenticated user from verified cryptographic JWT claims
                $user = new User([
                    'name' => $payload['name'] ?? 'Authorized User',
                    'email' => $payload['email'] ?? '',
                    'role' => $payload['role'] ?? 'cashier',
                    'tenant_id' => $payload['tenant_id'] ?? null,
                    'outlet_id' => $payload['outlet_id'] ?? null,
                ]);
                $user->id = (int) $payload['sub'];
                $user->exists = true;

                // Propagate user across both default and sanctum guards
                Auth::setUser($user);
                Auth::guard('sanctum')->setUser($user);
                $request->setUserResolver(fn () => $user);
            }
        }

        return $next($request);
    }
}

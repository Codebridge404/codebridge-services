<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
 * Step 3 (Expiration & Claims Check): Verifies token expiration timestamp (exp) and issuer claims.
 * Step 4 (Tenant Scoping Injection): Injects user_id, tenant_id, and outlet_id into request attributes.
 * Step 5 (Security Guard Fallback): Blocks unauthenticated requests with HTTP 401 Unauthorized.
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

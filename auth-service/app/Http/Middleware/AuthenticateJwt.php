<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0040] Stateless JWT Authentication Middleware.
 *
 * @ticket BD-0040
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Middleware/AuthenticateJwt.php
 * @related-service App\Services\JwtService
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Validates HMAC-SHA256 signed Bearer tokens statelessly across all microservice requests.
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

        if ($request->is('api/v1/auth/refresh')) return $next($request);

        if ($token && substr_count($token, '.') === 2) {
            $payload = $this->jwtService->verify($token);

            if ($payload && !empty($payload['sub'])) {
                // [P1] Reject refresh tokens from accessing standard API endpoints
                if (($payload['token_use'] ?? 'access') === 'refresh' && !$request->is('api/v1/auth/refresh')) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'INVALID_TOKEN_TYPE',
                        'message' => 'Refresh token cannot be used for API authentication.',
                    ], 401);
                }

                // Check if token has been explicitly revoked
                if (!empty($payload['jti']) && $this->jwtService->isRevoked($payload['jti'])) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'TOKEN_REVOKED',
                        'message' => 'Token has been revoked.',
                    ], 401);
                }

                // Check if user session has been revoked globally
                $iat = $payload['iat'] ?? 0;
                if (!empty($payload['sub']) && $this->jwtService->isUserSessionRevoked($payload['sub'], (int) $iat, $payload['session_version'] ?? null)) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'TOKEN_REVOKED',
                        'message' => 'Session has been revoked.',
                    ], 401);
                }

                $user = User::find($payload['sub']);

                // [P1] Reject missing users in the authoritative auth service
                if (!$user) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'USER_NOT_FOUND',
                        'message' => 'User account does not exist or has been deleted.',
                    ], 401);
                }

                // Verify that the user account is active and not locked out
                if (!$user->is_active) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'ACCOUNT_DEACTIVATED',
                        'message' => 'User account is deactivated.',
                    ], 401);
                }

                if ($user->isLockedOut()) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'ACCOUNT_LOCKED_OUT',
                        'message' => 'User account is temporarily locked out.',
                    ], 401);
                }

                if (!empty($payload['sid'])) {
                    $session = $this->jwtService->sessionForPayload($payload);
                    if (!$session) return response()->json(['status' => 'error', 'code' => 'TOKEN_REVOKED', 'message' => 'Session has been revoked.'], 401);
                    $user->withAccessToken($session);
                }
                $request->attributes->set('is_pos_session', !empty($payload['is_pos_session']) || ($payload['scope'] ?? null) === 'pos');
                Auth::setUser($user);
                Auth::guard('sanctum')->setUser($user);
                $request->setUserResolver(fn () => $user);
            }
        }

        return $next($request);
    }
}

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
        $token = $request->bearerToken()
            ?: $request->header('token')
            ?: $request->header('Token')
            ?: $request->header('x-token')
            ?: $request->header('X-Token');

        if ($token && str_starts_with($token, 'Bearer ')) {
            $token = trim(substr($token, 7));
        }

        if ($request->is('api/v1/auth/refresh')) return $next($request);

        if ($token && substr_count($token, '.') === 2) {
            $payload = $this->jwtService->verify($token);

            if ($payload && !empty($payload['sub'])) {
                // [P1] Strictly require explicit token_use=access on standard API endpoints
                if (($payload['token_use'] ?? null) !== 'access' && !$request->is('api/v1/auth/refresh')) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'INVALID_TOKEN_TYPE',
                        'message' => 'Only access tokens can be used for API authentication.',
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

                // Check if specific session UUID has been revoked
                if (!empty($payload['sid']) && $this->jwtService->isSessionUuidRevoked((string) $payload['sid'])) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'TOKEN_REVOKED',
                        'message' => 'Session has been revoked.',
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

                    if ($session instanceof \App\Models\UserSession) {
                        $request->attributes->set('user_session', $session);

                        $inactivityDays = $user->session_inactivity_days ?: 180;
                        $pat = \Laravel\Sanctum\PersonalAccessToken::where('tokenable_id', $user->id)
                            ->where('tokenable_type', get_class($user))
                            ->where('name', $session->device_name)
                            ->latest('id')
                            ->first();

                        if ($pat) {
                            $lastActive = $pat->last_used_at ? \Illuminate\Support\Carbon::parse($pat->last_used_at) : ($pat->created_at ? \Illuminate\Support\Carbon::parse($pat->created_at) : null);
                            if ($lastActive && $lastActive->copy()->addDays($inactivityDays)->isPast()) {
                                $session->revoke('inactivity_timeout');
                                $pat->delete();
                                return response()->json([
                                    'status' => 'error',
                                    'code' => 'SESSION_EXPIRED',
                                    'message' => 'Session has expired due to inactivity.',
                                ], 401);
                            }
                            $session->touchLastSeen($request->ip(), $request->userAgent());
                            $user->withAccessToken($pat);
                        } else {
                            $lastActive = $session->last_seen_at ?? $session->created_at;
                            if ($lastActive && $lastActive->copy()->addDays($inactivityDays)->isPast()) {
                                $session->revoke('inactivity_timeout');
                                return response()->json([
                                    'status' => 'error',
                                    'code' => 'SESSION_EXPIRED',
                                    'message' => 'Session has expired due to inactivity.',
                                ], 401);
                            }
                            $session->touchLastSeen($request->ip(), $request->userAgent());
                            $user->withAccessToken($session);
                        }
                    } else {
                        // Enforce account inactivity timeout policy on PersonalAccessToken
                        $inactivityDays = $user->session_inactivity_days ?: 180;
                        $lastActive = $session->last_used_at ? \Illuminate\Support\Carbon::parse($session->last_used_at) : ($session->created_at ? \Illuminate\Support\Carbon::parse($session->created_at) : null);
                        if ($lastActive && $lastActive->copy()->addDays($inactivityDays)->isPast()) {
                            $session->delete();
                            return response()->json([
                                'status' => 'error',
                                'code' => 'SESSION_EXPIRED',
                                'message' => 'Session has expired due to inactivity.',
                            ], 401);
                        }

                        // Throttle updating last_used_at to at most once every 60 seconds
                        if (!$session->last_used_at || \Illuminate\Support\Carbon::parse($session->last_used_at)->diffInSeconds(now()) >= 60) {
                            $session->forceFill(['last_used_at' => now()])->save();
                        }

                        $user->withAccessToken($session);
                    }
                }
                $request->attributes->set('is_pos_session', !empty($payload['is_pos_session']) || ($payload['scope'] ?? null) === 'pos');
                $request->attributes->set('jwt_claims', $payload);
                if (array_key_exists('tenant_id', $payload) && $payload['tenant_id'] !== null && $payload['tenant_id'] !== '') {
                    if (is_numeric($payload['tenant_id'])) {
                        $claimTenantId = (int) $payload['tenant_id'];
                        $user->pendingTenantId = $claimTenantId;
                        $request->attributes->set('jwt_tenant_id', $claimTenantId);
                    } else {
                        $request->attributes->set('jwt_tenant_id_invalid', true);
                    }
                }
                Auth::setUser($user);
                Auth::guard('sanctum')->setUser($user);
                $request->setUserResolver(fn () => $user);
            } else {
                try {
                    if (\App\Support\SchemaGuard::hasTable('security_events')) {
                        \App\Models\SecurityEvent::record(
                            eventType: 'jwt_invalid_signature',
                            ruleCode: 'SEC_JWT_INVALID_SIGNATURE',
                            summary: 'JWT verification failed: invalid signature, expired, or malformed claims',
                            severity: 'medium',
                            outcome: 'blocked',
                            confidence: 'confirmed',
                            request: $request,
                            statusCode: 401
                        );
                    }
                } catch (\Throwable) {
                    // Safe fall-through
                }
            }
        } elseif ($token && substr_count($token, '.') !== 2) {
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if (! $accessToken) {
                Auth::forgetGuards();
                return response()->json([
                    'status' => 'error',
                    'code' => 'TOKEN_INVALID',
                    'message' => 'Token is invalid or has been revoked.',
                ], 401);
            }

            // [P1] Enforce explicit token expiration on Sanctum PersonalAccessToken
            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                Auth::forgetGuards();
                return response()->json([
                    'status' => 'error',
                    'code' => 'TOKEN_EXPIRED',
                    'message' => 'Token has expired.',
                ], 401);
            }

            // [P1] Enforce configured Sanctum expiration minutes
            $expiration = config('sanctum.expiration');
            if ($expiration && $accessToken->created_at && $accessToken->created_at->lte(now()->subMinutes((int) $expiration))) {
                Auth::forgetGuards();
                return response()->json([
                    'status' => 'error',
                    'code' => 'TOKEN_EXPIRED',
                    'message' => 'Token has expired.',
                ], 401);
            }

            // [P1] Enforce custom hard session expiration on Sanctum PersonalAccessToken
            if (! empty($accessToken->session_expires_at) && \Illuminate\Support\Carbon::parse($accessToken->session_expires_at)->isPast()) {
                $accessToken->delete();
                Auth::forgetGuards();
                return response()->json([
                    'status' => 'error',
                    'code' => 'SESSION_EXPIRED',
                    'message' => 'Session has expired.',
                ], 401);
            }

            $user = $accessToken->tokenable;
            if (! $user) {
                Auth::forgetGuards();
                return response()->json([
                    'status' => 'error',
                    'code' => 'USER_NOT_FOUND',
                    'message' => 'User account does not exist or has been deleted.',
                ], 401);
            }

            // [P1] Enforce account session inactivity timeout policy
            $inactivityDays = $user->session_inactivity_days ?: 180;
            $lastActive = $accessToken->last_used_at
                ? \Illuminate\Support\Carbon::parse($accessToken->last_used_at)
                : ($accessToken->created_at ? \Illuminate\Support\Carbon::parse($accessToken->created_at) : null);

            if ($lastActive && $lastActive->copy()->addDays($inactivityDays)->isPast()) {
                $accessToken->delete();
                Auth::forgetGuards();
                return response()->json([
                    'status' => 'error',
                    'code' => 'SESSION_EXPIRED',
                    'message' => 'Session has expired due to inactivity.',
                ], 401);
            }

            if (! $user->is_active) {
                Auth::forgetGuards();
                return response()->json([
                    'status' => 'error',
                    'code' => 'ACCOUNT_DEACTIVATED',
                    'message' => 'User account is deactivated.',
                ], 401);
            }

            if ($user->isLockedOut()) {
                Auth::forgetGuards();
                return response()->json([
                    'status' => 'error',
                    'code' => 'ACCOUNT_LOCKED_OUT',
                    'message' => 'User account is temporarily locked out.',
                ], 401);
            }

            // Throttle updating last_used_at to at most once every 60 seconds
            if (! $accessToken->last_used_at || \Illuminate\Support\Carbon::parse($accessToken->last_used_at)->diffInSeconds(now()) >= 60) {
                $accessToken->forceFill(['last_used_at' => now()])->save();
            }

            // [P1] Attach validated credential to user so abilities, currentAccessToken and logout operate on it
            $user->withAccessToken($accessToken);

            Auth::setUser($user);
            Auth::guard('sanctum')->setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('auth_user_id', $user->id);
            $request->attributes->set('auth_tenant_id', $user->tenant_id);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInviteRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\QuickSwitchRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\SendVerificationLinkRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\UploadProfileImageRequest;
use App\Http\Resources\User\UserResource;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserOutlet;
use App\Models\User;
use App\Models\UserPosPin;
use App\Http\Responses\HttpStatus;
use App\Http\Responses\ResponseCode;
use App\Services\JwtService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * [BD-0001] Central Authentication, Multi-Tenant Session & Security Controller.
 *
 * @ticket BD-0001
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/AuthController.php
 * @related-model App\Models\User, App\Models\TenantUser, App\Models\UserPosPin
 *
 * Process Capabilities:
 * 1. Sanitizes login input against null-byte injection and multi-identifier login (email or phone).
 * 2. High-performance Redis/Cache account lockout protection (5 failed attempts = 15-minute lock).
 * 3. BCrypt / Argon2 password verification with automated counter reset.
 * 4. Dual-token issuance: Stateless Enterprise JWT (15m access token) + Rotating Refresh Token (30d).
 * 5. POS Cashier Quick-Switch powered by SHA-256 hashed user_pos_pins.
 * 6. Multi-tenant workspace and outlet context resolution for Elements OpenAPI docs.
 */
class AuthController extends Controller
{
    /**
     * Central Authentication supporting Email and Phone Number Login.
     * POST /api/v1/auth/login
     */
    #[OpenApiResponse(status: 200, description: 'Login successful', type: 'array{status: string, message: string, access_token: string, token?: string, refresh_token: string, token_type: string, expires_in: int, user: \App\Http\Resources\User\UserResource}')]
    #[OpenApiResponse(status: 403, description: 'Two-factor authentication required', type: 'array{status: string, code: string, message: string, status_mfa?: string, two_factor_required?: bool, challenge_token?: string, expires_in?: int}')]
    public function login(LoginRequest $request)
    {
        $rawEmail = $request->input('email');
        $rawPhone = $request->input('phone');
        $rawLogin = $request->input('login') ?? $request->input('identifier');

        foreach (['email' => $rawEmail, 'phone' => $rawPhone, 'login' => $rawLogin] as $field => $val) {
            if ($val !== null && preg_match('/[\x00-\x1F\x7F]/', (string) $val)) {
                throw ValidationException::withMessages([
                    $field => ['The login identifier contains invalid characters.'],
                ]);
            }
        }

        $email = $rawEmail ? trim((string) $rawEmail) : null;
        $phone = $rawPhone ? trim((string) $rawPhone) : null;
        $login = $rawLogin ? trim((string) $rawLogin) : null;

        if (!$email && !$phone && !$login) {
            throw ValidationException::withMessages([
                'email' => ['Please provide an email address or phone number to sign in.'],
            ]);
        }

        // Global IP Rate Limiting (30 requests/minute per IP)
        $ipKey = 'auth:login:ip:' . hash('sha256', (string) $request->ip());
        $ipAttempts = RateLimiter::increment($ipKey, 60);
        if ($ipAttempts > 30) {
            return $this->tooManyAttemptsResponse(
                seconds: RateLimiter::availableIn($ipKey),
                code: 'TOO_MANY_ATTEMPTS'
            );
        }

        // Query user by email OR phone number flexibly
        $query = User::query();

        if ($email && $phone) {
            $query->where(function ($q) use ($email, $phone) {
                $q->where('email', $email)
                  ->orWhere('phone', $phone)
                  ->orWhere('email', $phone)
                  ->orWhere('phone', $email);
            });
        } elseif ($email) {
            $query->where(function ($q) use ($email) {
                $q->where('email', $email)->orWhere('phone', $email);
            });
        } elseif ($phone) {
            $query->where(function ($q) use ($phone) {
                $q->where('phone', $phone)->orWhere('email', $phone);
            });
        } elseif ($login) {
            $query->where(function ($q) use ($login) {
                $q->where('email', $login)->orWhere('phone', $login);
            });
        }

        $user = $query->first();

        // Block deactivated accounts
        if ($user && isset($user->is_active) && !(bool) $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is inactive. Please contact your administrator.'],
            ]);
        }

        // Determine lockout tracking subject (user ID or hashed identifier)
        $primaryIdentifier = $email ?? $phone ?? $login;
        $subject = $user ? (string) $user->id
            : 'unknown:' . hash('sha256', mb_strtolower($primaryIdentifier));

        $failedKey = 'auth:failed_attempts:' . $subject;
        $lockedKey = 'auth:login_lockout:' . $subject;
        $expiresKey = 'auth:login_lockout_expire:' . $subject;

        // Check if account is locked out
        if (Cache::get($lockedKey, false) || ($user && $user->isLockedOut())) {
            $lockoutExpire = (int) Cache::get($expiresKey, now()->addMinutes(15)->timestamp);
            $remainingSeconds = max(1, $lockoutExpire - now()->timestamp);
            return $this->tooManyAttemptsResponse(
                seconds: $remainingSeconds,
                code: 'ACCOUNT_LOCKED_OUT',
            );
        }

        // Verify password
        if (!$user || !Hash::check($request->password, $user->password)) {
            $attempts = (int) Cache::increment($failedKey);
            Cache::put($failedKey, $attempts, now()->addMinutes(30));

            if ($attempts >= 5) {
                $expire = now()->addMinutes(15);
                Cache::put($lockedKey, true, $expire);
                Cache::put($expiresKey, $expire->timestamp, $expire);
                Cache::forget($failedKey);

                Log::warning('User account locked out due to multiple failed login attempts', [
                    'subject' => $subject,
                    'email' => $user?->email ?? $email,
                    'phone' => $user?->phone ?? $phone,
                    'ip' => $request->ip(),
                ]);
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials (email or phone number) are incorrect.'],
            ]);
        }

        // Reset failed attempt counters on successful authentication
        Cache::forget($failedKey);
        Cache::forget($lockedKey);
        Cache::forget($expiresKey);

        // MFA verification check
        if ((bool) ($user->two_factor_enabled ?? false)) {
            /** @var \App\Services\TwoFactorService $twoFactorService */
            $twoFactorService = app(\App\Services\TwoFactorService::class);
            $challengeToken = $twoFactorService->createChallengeToken($user);

            return $this->forbiddenResponse(
                message: 'Two-factor authentication is required.',
                errorCode: ResponseCode::TWO_FACTOR_REQUIRED,
                headers: [
                    'Cache-Control' => 'no-store',
                    'Pragma' => 'no-cache',
                ],
                extra: [
                    'status' => 'requires_mfa',
                    'two_factor_required' => true,
                    'challenge_token' => $challengeToken,
                    'expires_in' => 300,
                ]
            );
        }

        // Issue Stateless Enterprise JWT Token & Rotating Refresh Token
        /** @var JwtService $jwtService */
        $jwtService = app(JwtService::class);
        $jwtToken = $jwtService->generateTokenForUser($user);
        $refreshToken = $jwtService->generateRefreshTokenForUser($user);

        // Generate Sanctum token with client device telemetry
        $deviceName = $request->device_name ?? $this->parseDeviceName($request->userAgent());
        $expiresAt = now()->addSeconds($jwtService->getTtlSeconds());
        $tokenResult = $user->createToken($deviceName, ['*'], $expiresAt);
        $jwtService->bindSession($tokenResult->accessToken);
        $jwtToken = $jwtService->generateTokenForUser($user, ['sid' => (string) $tokenResult->accessToken->id]);
        $refreshToken = $jwtService->generateRefreshTokenForUser($user, ['sid' => (string) $tokenResult->accessToken->id]);

        DB::table('personal_access_tokens')
            ->where('id', $tokenResult->accessToken->id)
            ->update([
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'device_name' => $deviceName,
            ]);

        // Resolve workspace branding
        if ($user->tenant_id) {
            $tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
            if ($tenant) {
                $user->tenant_name = $tenant->name;
                $user->company_name = $tenant->name;
                $user->tenant_logo_url = $tenant->logo_url ?? null;
                $user->logo_url = $tenant->logo_url ?? null;
            }
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('auth_tenant_id', $user->tenant_id);

        $this->recordAuditLog(
            action: 'auth.login',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "User {$user->email} logged in successfully",
            userId: $user->id,
            userName: $user->name,
            tenantId: $user->tenant_id
        );

        return $this->successResponse(
            message: 'Login successful.',
            headers: [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ],
            extra: [
                'token' => $jwtToken,
                'access_token' => $jwtToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $jwtService->getTtlSeconds(),
                'user' => new UserResource($user),
            ]
        );
    }

    /**
     * Cashier Fast PIN Quick-Switch (Physical Register Quick-Switch).
     * POST /api/v1/auth/quick-switch
     */
    #[OpenApiResponse(status: 200, description: 'Cashier quick-switch successful', type: 'array{status: string, message: string, access_token: string, token: string, refresh_token: string, token_type: string, expires_in: int, sanctum_token?: string, user: \App\Http\Resources\User\UserResource}')]
    public function quickSwitch(QuickSwitchRequest $request)
    {
        $pin = trim($request->pin_code);

        // Derive tenant context server-side or validate explicit parameter
        $tenantId = $request->user()?->tenant_id ?? ($request->filled('tenant_id') ? (int) $request->tenant_id : null);
        if (!$tenantId) {
            return $this->errorResponse(
                'Tenant context is required for fast cashier PIN switch.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::TENANT_REQUIRED
            );
        }

        // Strict rate limiting on fast PIN verification
        $ipThrottleKey = 'auth:quick_switch:ip:' . hash('sha256', (string) ($request->ip() . '|' . $tenantId));
        if (RateLimiter::tooManyAttempts($ipThrottleKey, 10)) {
            return $this->tooManyAttemptsResponse(RateLimiter::availableIn($ipThrottleKey), 'TOO_MANY_ATTEMPTS');
        }

        // Search active PINs strictly scoped to this tenant
        $posPinQuery = UserPosPin::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with(['user', 'tenant']);

        if ($request->filled('user_id')) {
            $posPinQuery->where('user_id', $request->user_id);
        }

        $candidatePins = $posPinQuery->get();
        $matchedPin = null;
        $matchedCount = 0;

        foreach ($candidatePins as $posPin) {
            if ($posPin->isLockedOut()) {
                continue;
            }

            $isBcrypt = str_starts_with($posPin->pin_hash, '$2') || str_starts_with($posPin->pin_hash, '$argon2');
            $matches = false;
            if ($isBcrypt) {
                $matches = Hash::check($pin, $posPin->pin_hash);
            } else {
                $matches = \App\Services\PinService::matches($pin, $posPin->pin_hash);
                if ($matches) {
                    // Transparently upgrade legacy hash to bcrypt
                    $posPin->update(['pin_hash' => Hash::make($pin)]);
                }
            }

            if ($matches) {
                $matchedPin = $posPin;
                $matchedCount++;
            }
        }

        // Fail-closed on ambiguous PIN matches across different cashiers in same tenant
        if ($matchedCount > 1) {
            return $this->errorResponse(
                'Multiple cashiers share this PIN. Please provide user_id to specify your account.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::AMBIGUOUS_PIN
            );
        }

        if (!$matchedPin) {
            RateLimiter::hit($ipThrottleKey, 600);
            return $this->errorResponse(
                'Invalid staff PIN code. Please check and try again.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::INVALID_PIN
            );
        }

        RateLimiter::clear($ipThrottleKey);
        $matchedPin->update(['last_used_at' => now(), 'failed_attempts' => 0]);

        $user = $matchedPin->user;

        if (!$user || !(bool) $user->is_active) {
            return $this->errorResponse(
                'Cashier account is deactivated or inactive.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::ACCOUNT_INACTIVE
            );
        }

        if ($user->isLockedOut() || Cache::get('auth:login_lockout:' . $user->id, false)) {
            $lockoutExpire = (int) Cache::get('auth:login_lockout_expire:' . $user->id, now()->addMinutes(15)->timestamp);
            $remaining = max(1, $lockoutExpire - now()->timestamp);
            return $this->tooManyAttemptsResponse(
                seconds: $remaining,
                code: 'ACCOUNT_LOCKED_OUT',
            );
        }

        /** @var JwtService $jwtService */
        $jwtService = app(JwtService::class);
        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id') ?? $request->input('device_uuid');
        $deviceName = $request->device_name ?? ($deviceId ? "Trusted POS Terminal ({$deviceId})" : 'POS Register Terminal (' . $this->parseDeviceName($request->userAgent()) . ')');
        $expiresAt = now()->addSeconds($jwtService->getTtlSeconds());

        // Enforce Principle of Least Privilege: POS PIN login grants limited POS abilities only
        $posAbilities = ['pos:access', 'pos:sales', 'pos:orders', 'pos:cashier'];
        $tokenResult = $user->createToken($deviceName, $posAbilities, $expiresAt);
        $plainToken = $tokenResult->plainTextToken;

        DB::table('personal_access_tokens')
            ->where('id', $tokenResult->accessToken->id)
            ->update([
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'device_name' => $deviceName,
            ]);

        if ($user->tenant_id) {
            $tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
            if ($tenant) {
                $user->tenant_name = $tenant->name;
                $user->company_name = $tenant->name;
                $user->tenant_logo_url = $tenant->logo_url ?? null;
                $user->logo_url = $tenant->logo_url ?? null;
            }
        }

        /** @var JwtService $jwtService */
        $jwtService = app(JwtService::class);
        $jwtService->bindSession($tokenResult->accessToken);
        $posClaims = [
            'sid' => (string) $tokenResult->accessToken->id,
            'scope' => 'pos',
            'is_pos_session' => true,
            'trusted_device_id' => $deviceId,
            'permissions' => $posAbilities,
        ];
        $jwtToken = $jwtService->generateTokenForUser($user, $posClaims);
        $refreshToken = $jwtService->generateRefreshTokenForUser($user, $posClaims);

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('auth_tenant_id', $user->tenant_id);

        $this->recordAuditLog(
            action: 'auth.quick_switch',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "Cashier {$user->name} performed fast PIN quick-switch",
            userId: $user->id,
            userName: $user->name,
            tenantId: $tenantId
        );

        return $this->successResponse(
            message: "Welcome back, {$user->name}!",
            extra: [
                'token' => $jwtToken,
                'access_token' => $jwtToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $jwtService->getTtlSeconds(),
                'sanctum_token' => $plainToken,
                'user' => new UserResource($user),
            ]
        );
    }

    /**
     * Refresh Access Token using Refresh Token.
     * POST /api/v1/auth/refresh
     */
    #[OpenApiResponse(status: 200, description: 'Tokens refreshed successfully', type: 'array{status: string, message: string, access_token: string, token: string, refresh_token: string, token_type: string, expires_in: int, user: \App\Http\Resources\User\UserResource}')]
    #[OpenApiResponse(status: 401, description: 'Invalid or expired refresh token', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 403, description: 'Account inactive or locked out', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 404, description: 'User not found', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Refresh token required', type: 'array{status: string, code: string, message: string}')]
    public function refresh(RefreshTokenRequest $request)
    {
        $refreshToken = $request->input('refresh_token') ?? $request->bearerToken();

        if (!$refreshToken) {
            return $this->errorResponse(
                'Refresh token is required.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::TOKEN_REQUIRED
            );
        }

        /** @var JwtService $jwtService */
        $jwtService = app(JwtService::class);
        $payload = $jwtService->verifyRefreshToken($refreshToken);

        if (!$payload) {
            return $this->unauthorizedResponse(
                'Invalid, expired, or revoked refresh token.',
                ResponseCode::INVALID_REFRESH_TOKEN
            );
        }

        $userId = $payload['sub'] ?? null;
        $user = $userId ? User::find($userId) : null;

        if (!$user) {
            return $this->notFoundResponse(
                'User associated with this token was not found.',
                ResponseCode::USER_NOT_FOUND
            );
        }

        if (isset($user->is_active) && !(bool) $user->is_active) {
            return $this->forbiddenResponse(
                'User account is deactivated.',
                ResponseCode::ACCOUNT_INACTIVE
            );
        }

        if ($user->isLockedOut()) {
            $lockoutExpire = (int) Cache::get('auth:login_lockout_expire:' . $user->id, now()->addMinutes(15)->timestamp);
            $remaining = max(1, $lockoutExpire - now()->timestamp);
            return $this->errorResponse(
                "Account is temporarily locked out. Please try again in {$remaining} seconds.",
                HttpStatus::LOCKED,
                ResponseCode::ACCOUNT_LOCKED_OUT
            );
        }

        // Token Rotation: atomically consume previous refresh token to prevent replay attacks & race conditions
        if (isset($payload['jti'])) {
            $remainingSeconds = max(1, ($payload['exp'] ?? time()) - time());
            $consumed = Cache::add("consumed_refresh:{$payload['jti']}", true, $remainingSeconds);
            if (!$consumed) {
                return $this->unauthorizedResponse(
                    'Refresh token has already been consumed or invalidated.',
                    ResponseCode::INVALID_REFRESH_TOKEN
                );
            }
            $jwtService->revokeToken($payload['jti'], $remainingSeconds);
        }

        if ($user->tenant_id) {
            $tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
            if ($tenant) {
                $user->tenant_name = $tenant->name;
                $user->company_name = $tenant->name;
                $user->tenant_logo_url = $tenant->logo_url ?? null;
                $user->logo_url = $tenant->logo_url ?? null;
            }
        }

        $claims = array_intersect_key($payload, array_flip(['sid', 'scope', 'is_pos_session', 'trusted_device_id']));
        if (!empty($payload['is_pos_session']) || ($payload['scope'] ?? null) === 'pos') {
            $claims['permissions'] = ['pos:access', 'pos:sales', 'pos:orders', 'pos:cashier'];
        }
        $newAccessToken = $jwtService->generateTokenForUser($user, $claims);
        $newRefreshToken = $jwtService->generateRefreshTokenForUser($user, $claims);

        return $this->successResponse(
            message: 'Token refreshed successfully.',
            extra: [
                'token' => $newAccessToken,
                'access_token' => $newAccessToken,
                'refresh_token' => $newRefreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $jwtService->getTtlSeconds(),
                'user' => new UserResource($user),
            ]
        );
    }

    /**
     * Active Devices & Sessions Listing.
     * GET /api/v1/auth/sessions
     */
    #[OpenApiResponse(status: 200, description: 'Sessions retrieved successfully', type: 'array{status: string, message: string, data: array<int, array{id: string, name: string, device_name: string, ip_address: ?string, user_agent: ?string, is_current: bool, last_used_at: ?string, created_at: ?string}>}')]
    public function sessions(Request $request)
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', get_class($user))
            ->orderBy('last_used_at', 'desc')
            ->get()
            ->map(function ($token) use ($currentTokenId) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'device_name' => $token->device_name ?? $token->name ?? 'Unknown Device',
                    'ip_address' => $token->ip_address,
                    'user_agent' => $token->user_agent,
                    'is_current' => (string) $token->id === (string) $currentTokenId,
                    'last_used_at' => $token->last_used_at ?? $token->created_at,
                    'created_at' => $token->created_at,
                ];
            });

        return $this->successResponse(
            data: $tokens,
            message: 'Sessions retrieved successfully.'
        );
    }

    /**
     * Remote Session Revocation.
     * DELETE /api/v1/auth/sessions/{id}
     */
    #[OpenApiResponse(status: 200, description: 'Session terminated successfully', type: 'array{status: string, message: string}')]
    #[OpenApiResponse(status: 404, description: 'Session not found or already terminated', type: 'array{status: string, code: string, message: string}')]
    public function revokeSession(Request $request, $id)
    {
        $user = $request->user();

        $session = $user->tokens()->where('id', $id)->first();
        $deleted = $user->tokens()->where('id', $id)->delete();
        if ($deleted && !$session?->session_expires_at) app(JwtService::class)->revokeAllUserTokens($user->id);

        if (!$deleted) {
            return $this->notFoundResponse('Session not found or already terminated.');
        }

        return $this->successResponse(message: 'Session terminated successfully.');
    }

    /**
     * Terminate all devices except current.
     * POST /api/v1/auth/logout-all-devices
     */
    #[OpenApiResponse(status: 200, description: 'Successfully terminated active session(s)', type: 'array{status: string, message: string, revoked_count: int}')]
    public function logoutAllDevices(Request $request)
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $query = $user->tokens();

        if ($currentTokenId && !$request->boolean('include_current')) {
            $query->where('id', '!=', $currentTokenId);
        }

        $revokedCount = $query->delete();

        // Globally invalidate all existing stateless JWT tokens for this user
        /** @var JwtService $jwtService */
        $jwtService = app(JwtService::class);
        if (!$currentTokenId || $request->boolean('include_current')) $jwtService->revokeAllUserTokens($user->id);

        return $this->successResponse(
            message: "Successfully terminated {$revokedCount} active session(s).",
            extra: [
                'revoked_count' => $revokedCount,
            ]
        );
    }

    /**
     * Verify Staff Invitation Link.
     * GET /api/v1/auth/verify-invite?token=...
     */
    #[OpenApiResponse(status: 200, description: 'Invitation is valid', type: 'array{status: string, message: string, data: array{email: string, role: string, tenant_name: string, outlet_name: string, expires_at: string}}')]
    #[OpenApiResponse(status: 404, description: 'Invalid, expired, or already accepted invitation link', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Missing invitation token', type: 'array{status: string, code: string, message: string}')]
    public function verifyInvite(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return $this->errorResponse(
                'Missing invitation token.',
                HttpStatus::UNPROCESSABLE_ENTITY
            );
        }

        $invitation = DB::table('user_invitations')
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$invitation) {
            return $this->notFoundResponse(
                'Invalid, expired, or already accepted invitation link.'
            );
        }

        $tenant = $invitation->tenant_id ? DB::table('tenants')->where('id', $invitation->tenant_id)->first() : null;
        $outlet = !empty($invitation->outlet_uuid) ? DB::table('outlets')->where('uuid', $invitation->outlet_uuid)->first() : null;

        return $this->successResponse(
            data: [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'tenant_name' => $tenant->name ?? 'CodeBridges Store Organization',
                'outlet_name' => $outlet->name ?? 'Primary Store',
                'expires_at' => $invitation->expires_at,
            ],
            message: 'Invitation is valid.'
        );
    }

    /**
     * Accept Invitation & Complete Staff Setup.
     * POST /api/v1/auth/accept-invite
     */
    #[OpenApiResponse(status: 200, description: 'Membership accepted (MFA required)', type: 'array{status: string, message: string, code?: string}')]
    #[OpenApiResponse(status: 201, description: 'Staff account setup completed successfully', type: 'array{status: string, message: string, access_token: string, token: string, refresh_token: string, sanctum_token?: string, user: \App\Http\Resources\User\UserResource}')]
    #[OpenApiResponse(status: 403, description: 'Account suspended or forbidden', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Invalid or expired invitation link', type: 'array{status: string, code: string, message: string}')]
    public function acceptInvite(AcceptInviteRequest $request)
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated, $request) {
            $invitation = DB::table('user_invitations')
                ->where('token', $validated['token'])
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$invitation) {
                return $this->errorResponse(
                    'Invalid or expired invitation link.',
                    HttpStatus::UNPROCESSABLE_ENTITY
                );
            }

            // Attach or create the user identity
            $existingUser = DB::table('users')->where('email', $invitation->email)->first();
            if ($existingUser) {
                // Preserve account suspension
                if (isset($existingUser->is_active) && !(bool) $existingUser->is_active) {
                    return $this->forbiddenResponse(
                        'This account has been suspended. Please contact your organization administrator.',
                        ResponseCode::ACCOUNT_SUSPENDED
                    );
                }

                // If not authenticated, require password verification
                $authenticatedUser = $request->user();
                if (!$authenticatedUser || $authenticatedUser->id !== $existingUser->id) {
                    if (!Hash::check($validated['password'], $existingUser->password)) {
                        return $this->errorResponse(
                            'Existing account password verification failed.',
                            HttpStatus::UNPROCESSABLE_ENTITY,
                            ResponseCode::INVALID_PASSWORD
                        );
                    }
                }

                DB::table('users')->where('id', $existingUser->id)->update([
                    'name' => $validated['name'] ?? $existingUser->name,
                    'updated_at' => now(),
                ]);
                $user = User::find($existingUser->id);
            } else {
                $user = User::create([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'name' => $validated['name'],
                    'email' => $invitation->email,
                    'phone' => $validated['phone'] ?? null,
                    'password' => Hash::make($validated['password']),
                    'is_active' => true,
                ]);
            }

            // Resolve role within tenant or global scope; reject if not found
            $roleQuery = DB::table('roles')->where('slug', $invitation->role);
            if (\Illuminate\Support\Facades\Schema::hasColumn('roles', 'tenant_id')) {
                $roleQuery->where(function ($q) use ($invitation) {
                    $q->where('tenant_id', $invitation->tenant_id)->orWhereNull('tenant_id');
                });
            }
            $role = $roleQuery->first();
            if (!$role) {
                return $this->errorResponse(
                    'Configured role for this invitation was not found.',
                    HttpStatus::UNPROCESSABLE_ENTITY,
                    ResponseCode::ROLE_NOT_FOUND
                );
            }
            $roleId = $role->id;

            $tenantUser = TenantUser::firstOrCreate(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                ['status' => 'active', 'joined_at' => now()]
            );

            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $roleId],
                [
                    'tenant_id' => $invitation->tenant_id,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $outletUuid = $invitation->outlet_uuid ?? null;
            if ($outletUuid && $tenantUser) {
                TenantUserOutlet::firstOrCreate(
                    ['tenant_user_id' => $tenantUser->id, 'outlet_uuid' => $outletUuid],
                    ['is_primary' => true, 'is_active' => true]
                );
            }

            if (!empty($validated['pin_code'])) {
                UserPosPin::updateOrCreate(
                    ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                    [
                        'pin_hash' => Hash::make((string) $validated['pin_code']),
                        'is_active' => true,
                        'failed_attempts' => 0,
                        'updated_at' => now(),
                    ]
                );
            }

            // Mark invitation as accepted
            DB::table('user_invitations')->where('id', $invitation->id)->update([
                'accepted_at' => now(),
                'updated_at' => now(),
            ]);

            // Check if user has MFA enabled before issuing tokens
            if ((bool) ($user->two_factor_enabled ?? false)) {
                return $this->successResponse(
                    message: 'Membership accepted. Two-factor authentication is required to sign in.',
                    extra: [
                        'status' => 'requires_mfa',
                        'code' => ResponseCode::TWO_FACTOR_REQUIRED,
                    ]
                );
            }

            /** @var JwtService $jwtService */
            $jwtService = app(JwtService::class);
            $jwtToken = $jwtService->generateTokenForUser($user);
            $refreshToken = $jwtService->generateRefreshTokenForUser($user);
            $expiresAt = now()->addSeconds($jwtService->getTtlSeconds());
            $tokenResult = $user->createToken('POS Web Portal', ['*'], $expiresAt);
            $jwtService->bindSession($tokenResult->accessToken);
            $jwtToken = $jwtService->generateTokenForUser($user, ['sid' => (string) $tokenResult->accessToken->id]);
            $refreshToken = $jwtService->generateRefreshTokenForUser($user, ['sid' => (string) $tokenResult->accessToken->id]);

            Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('auth_tenant_id', $user->tenant_id);

        $this->recordAuditLog(
                action: 'auth.invite_accepted',
                module: 'auth',
                entityType: 'user_invitation',
                entityId: (string) $invitation->id,
                details: "User {$user->email} accepted staff invitation into tenant #{$invitation->tenant_id}",
                userId: $user->id,
                userName: $user->name,
                tenantId: $invitation->tenant_id
            );

            return $this->createdResponse(
             
                message: 'Staff account setup completed successfully. Welcome aboard!',
                extra: [
                    'token' => $jwtToken,
                    'access_token' => $jwtToken,
                    'refresh_token' => $refreshToken,
                    'sanctum_token' => $tokenResult->plainTextToken,
                    'user' => new UserResource($user),
                ]
            );
        });
    }

    /**
     * Two-Factor Authentication Management.
     * POST /api/v1/auth/2fa/toggle
     */
    #[OpenApiResponse(status: 200, description: 'Two-Factor Authentication disabled', type: 'array{status: string, message: string, two_factor_enabled: bool}')]
    #[OpenApiResponse(status: 401, description: 'Unauthenticated', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Current password required or 2FA enrollment incomplete', type: 'array{status: string, code: string, message: string}')]
    public function toggle2fa(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $request->validate([
            'enable' => ['required', 'boolean'],
            'password' => ['nullable', 'string'],
        ]);

        $enable = $request->boolean('enable');

        if ($enable) {
            return $this->errorResponse(
                'Two-factor enrollment and code verification must be completed before enabling 2FA.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::TWO_FACTOR_ENROLLMENT_REQUIRED
            );
        }

        // Reauthentication check when disabling 2FA
        if ($user->password && (!isset($request->password) || !Hash::check($request->password, $user->password))) {
            return $this->errorResponse(
                'Current password is required to disable Two-Factor Authentication.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::INVALID_PASSWORD
            );
        }

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $this->successResponse(
            message: 'Two-Factor Authentication disabled.',
            extra: [
                'two_factor_enabled' => false,
            ]
        );
    }

    /**
     * Send or Resend Email Verification Link & 6-digit Code to Mailpit.
     * POST /api/v1/auth/email/verification-notification
     */
    #[OpenApiResponse(status: 200, description: 'Verification email sent', type: 'array{status: string, message: string, mailpit_url?: string}')]
    public function sendVerificationNotification(Request $request)
    {
        $user = $request->user();

        if (!$user && $request->filled('email')) {
            $user = User::where('email', trim($request->email))->first();
        }

        // Rate limit verification email dispatch to prevent spam (5 requests per 5 minutes per IP)
        $ipThrottleKey = 'auth:send_verification:ip:' . hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($ipThrottleKey, 5)) {
            return $this->tooManyAttemptsResponse(RateLimiter::availableIn($ipThrottleKey), 'TOO_MANY_ATTEMPTS');
        }
        RateLimiter::hit($ipThrottleKey, 300);

        // Anti-Account Enumeration: Return generic success response when unauthenticated and email is not found
        if (!$user) {
            return $this->successResponse(
                message: 'If an account matches this email address, a verification email has been dispatched. Please check your inbox.',
                headers: ['Cache-Control' => 'no-store'],
                extra: [
                    'mailpit_url' => 'http://localhost:8025',
                ]
            );
        }

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse(
                message: 'This email address is already verified.',
                extra: [
                    'status' => 'already_verified',
                    'email_verified_at' => $user->email_verified_at,
                ]
            );
        }

        $user->sendEmailVerificationNotification();

        return $this->successResponse(
            message: 'Verification email dispatched successfully. Check your Mailpit inbox.',
            headers: ['Cache-Control' => 'no-store'],
            extra: [
                'mailpit_url' => 'http://localhost:8025',
            ]
        );
    }

    /**
     * Generate a signed email verification link and dispatch the email to Mailpit.
     * POST /api/v1/auth/email/create-link
     * POST /api/v1/auth/email/send-link
     */
    #[OpenApiResponse(status: 200, description: 'Verification link created and email dispatched to Mailpit', type: 'array{status: string, message: string, email: string, expires_at: string, otp_expires_at: string, mail_sent: bool, mailpit_url: string}')]
    #[OpenApiResponse(status: 404, description: 'User account not found', type: 'array{status: string, code: string, message: string}')]
    public function createVerificationLink(SendVerificationLinkRequest $request)
    {
        $user = $request->user();

        if (!$user && $request->filled('email')) {
            $user = User::where('email', trim($request->email))->first();
        }

        if (!$user) {
            if (!$request->filled('email')) {
                return $this->errorResponse(
                    'Email address is required in the request body when unauthenticated.',
                    HttpStatus::UNPROCESSABLE_ENTITY,
                    ResponseCode::VALIDATION_ERROR
                );
            }

            return $this->notFoundResponse('User account not found.');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse(
                message: 'This email address is already verified.',
                extra: [
                    'status' => 'already_verified',
                    'email_verified_at' => $user->email_verified_at,
                ]
            );
        }

        // Rate limit link creation by IP / target email (10 attempts / 10 minutes)
        $rateLimitKey = 'auth:create_verify_link:' . hash('sha256', (string) ($request->ip() . '|' . $user->email));
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return $this->tooManyAttemptsResponse(RateLimiter::availableIn($rateLimitKey), 'TOO_MANY_ATTEMPTS');
        }
        RateLimiter::hit($rateLimitKey, 600);

        $expireMinutes = (int) config('auth.verification.expire', 60);
        $expiresAt = now()->addMinutes($expireMinutes);
        $verificationUrl = $user->createEmailVerificationUrl($expireMinutes);

        // Generate or retrieve 6-digit OTP code in cache (valid for 15 minutes)
        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("auth:email_verify_code:{$user->id}", $code, now()->addMinutes(15));

        $mailSent = false;
        if ($request->boolean('send_mail', true)) {
            try {
                $user->notify(new \App\Notifications\VerifyEmailNotification($verificationUrl, $code));
                $mailSent = true;
            } catch (\Throwable $e) {
                Log::warning('Email delivery failed: ' . $e->getMessage());
            }
        }

        // Security: Deliver verification credentials through email only.
        // Never leak verification_link or verification_code in API JSON responses.
        return $this->successResponse(
            message: $mailSent
                ? 'Verification link created and email dispatched to Mailpit.'
                : 'Verification link created successfully.',
            headers: ['Cache-Control' => 'no-store'],
            extra: [
                'email' => $user->email,
                'expires_at' => $expiresAt->toIso8601String(),
                'otp_expires_at' => now()->addMinutes(15)->toIso8601String(),
                'mail_sent' => $mailSent,
                'mailpit_url' => 'http://localhost:8025',
            ]
        );
    }

    /**
     * Preview Email Verification Template.
     * GET /api/v1/auth/email/template-preview
     */
    public function previewEmailTemplate(Request $request)
    {
        $mockUser = (object) [
            'name' => $request->input('name', 'Alex Johnson'),
            'email' => $request->input('email', 'alex.johnson@codebridge.dev'),
        ];

        $verificationUrl = $request->input('url', url('/api/v1/auth/email/verify/1/samplehash1234567890abcdef'));
        $code = $request->input('code', '584920');
        $expireMinutes = (int) config('auth.verification.expire', 60);

        return response()->view('emails.verify-email', [
            'user' => $mockUser,
            'verificationUrl' => $verificationUrl,
            'code' => $code,
            'expireMinutes' => $expireMinutes,
        ]);
    }

    /**
     * Verify Email Address via Signed Verification Link.
     * GET /api/v1/auth/email/verify/{id}/{hash}
     */
    #[OpenApiResponse(status: 200, description: 'Email address verified or already verified', type: 'array{status: string, message: string, status?: string, email_verified_at?: string}')]
    #[OpenApiResponse(status: 403, description: 'Invalid or expired verification hash', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 404, description: 'User not found', type: 'array{status: string, code: string, message: string}')]
    public function verifyEmail(Request $request, $id, $hash)
    {
        // Enforce Laravel signed route verification while allowing optional redirect_url parameter
        if (!URL::hasValidSignature($request, true, ['redirect_url'])) {
            return $this->forbiddenResponse(
                'The email verification link is invalid, expired, or tampered.',
                ResponseCode::INVALID_VERIFICATION_HASH
            );
        }

        $user = User::find($id);

        if (!$user) {
            return $this->notFoundResponse('User not found.');
        }

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return $this->forbiddenResponse(
                'The email verification link is invalid or corrupted.',
                ResponseCode::INVALID_VERIFICATION_HASH
            );
        }

        if ($user->hasVerifiedEmail()) {
            if ($request->filled('redirect_url')) {
                $safeRedirect = $this->getSafeRedirectUrl($request->query('redirect_url'));
                return redirect($safeRedirect . (str_contains($safeRedirect, '?') ? '&' : '?') . 'verified=1');
            }

            return $this->successResponse(
                message: 'Email address is already verified.',
                extra: [
                    'status' => 'already_verified',
                    'email_verified_at' => $user->email_verified_at,
                ]
            );
        }

        $user->markEmailAsVerified();
        Cache::forget("auth:email_verify_code:{$user->id}");

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('auth_tenant_id', $user->tenant_id);

        $this->recordAuditLog(
            action: 'auth.email_verified',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "Email address {$user->email} verified via signed URL",
            userId: $user->id,
            userName: $user->name,
            tenantId: $user->tenant_id
        );

        if ($request->filled('redirect_url')) {
            $safeRedirect = $this->getSafeRedirectUrl($request->query('redirect_url'));
            return redirect($safeRedirect . (str_contains($safeRedirect, '?') ? '&' : '?') . 'verified=1');
        }

        return $this->successResponse(
       
            message: 'Email address verified successfully.',
            extra: [
                'email_verified_at' => $user->email_verified_at,
                'user' => new UserResource($user),
            ]
        );
    }

    /**
     * Verify Email Address via 6-digit OTP Code.
     * POST /api/v1/auth/email/verify-code
     */
    #[OpenApiResponse(status: 200, description: 'Email address verified successfully', type: 'array{status: string, message: string, email_verified_at: string, user: \App\Http\Resources\User\UserResource}')]
    #[OpenApiResponse(status: 404, description: 'User not found', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Invalid or expired verification code', type: 'array{status: string, code: string, message: string}')]
    public function verifyEmailCode(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $normalizedEmail = strtolower(trim($request->email));

        // Rate limit verification attempts (5 attempts per 15 minutes)
        $codeAttemptKey = 'auth:verify_code:' . hash('sha256', (string) ($request->ip() . '|' . $normalizedEmail));
        if (RateLimiter::tooManyAttempts($codeAttemptKey, 5)) {
            return $this->tooManyAttemptsResponse(RateLimiter::availableIn($codeAttemptKey), 'TOO_MANY_ATTEMPTS');
        }

        $user = User::where('email', $normalizedEmail)->first();
        if (!$user) {
            RateLimiter::hit($codeAttemptKey, 900);
            return $this->notFoundResponse('User not found.');
        }

        // Minimal response without user details to prevent enumeration
        if ($user->hasVerifiedEmail()) {
            return $this->successResponse(
                message: 'Email address is already verified.',
                extra: [
                    'status' => 'already_verified',
                    'email_verified_at' => $user->email_verified_at,
                ]
            );
        }

        $cachedCode = Cache::get("auth:email_verify_code:{$user->id}");
        if (!$cachedCode || !hash_equals((string) $cachedCode, trim($request->code))) {
            RateLimiter::hit($codeAttemptKey, 900);
            return $this->errorResponse(
                'The verification code is invalid or has expired.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::INVALID_CODE
            );
        }

        // Atomically consume code and clear rate limiter
        Cache::forget("auth:email_verify_code:{$user->id}");
        RateLimiter::clear($codeAttemptKey);

        $user->markEmailAsVerified();

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('auth_tenant_id', $user->tenant_id);

        $this->recordAuditLog(
            action: 'auth.email_verified',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "Email address {$user->email} verified via 6-digit OTP code",
            userId: $user->id,
            userName: $user->name,
            tenantId: $user->tenant_id
        );

        return $this->successResponse(
            message: 'Email address verified successfully.',
            extra: [
                'email_verified_at' => $user->email_verified_at,
                'user' => new UserResource($user),
            ]
        );
    }

    /**
     * Check Email Verification Status for a User.
     * GET|POST /api/v1/auth/email/check
     */
    #[OpenApiResponse(status: 200, description: 'Email verification status checked', type: 'array{status: string, message: string, email: string, is_verified: bool, email_verified_at: ?string}')]
    #[OpenApiResponse(status: 404, description: 'User account not found', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Email address is required', type: 'array{status: string, code: string, message: string}')]
    public function checkEmailVerification(Request $request)
    {
        $email = $request->input('email') ?? $request->user()?->email;

        if (!$email) {
            return $this->errorResponse(
                'Email address is required.',
                HttpStatus::UNPROCESSABLE_ENTITY
            );
        }

        $user = User::where('email', trim($email))->first();
        if (!$user) {
            return $this->notFoundResponse('User account not found.');
        }

        return $this->successResponse(
            extra: [
                'email' => $user->email,
                'is_verified' => $user->hasVerifiedEmail(),
                'email_verified_at' => $user->email_verified_at,
            ]
        );
    }

    /**
     * Registration.
     * POST /api/v1/auth/register
     */
    #[OpenApiResponse(status: 201, description: 'User account registered successfully', type: 'array{status: string, message: string, access_token: string, token: string, refresh_token: string, sanctum_token?: string, token_type: string, expires_in: int, user: \App\Http\Resources\User\UserResource}')]
    public function register(RegisterRequest $request)
    {
        $email = $request->email;
        $phone = $request->phone;

        if (!$email && !$phone) {
            return $this->errorResponse(
                'Please provide either an email address or a phone number.',
                HttpStatus::UNPROCESSABLE_ENTITY
            );
        }

        if ($email && User::where('email', $email)->exists()) {
            return $this->errorResponse(
                'This email address is already registered.',
                HttpStatus::UNPROCESSABLE_ENTITY
            );
        }

        if ($phone && User::where('phone', $phone)->exists()) {
            return $this->errorResponse(
                'This phone number is already registered.',
                HttpStatus::UNPROCESSABLE_ENTITY
            );
        }

        if (!$email && $phone) {
            $cleanedPhone = preg_replace('/[^0-9]/', '', $phone);
            $email = $cleanedPhone . '@phone.codebridges.com';
        }

        $user = User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => $request->name,
            'email' => $email,
            'password' => Hash::make($request->password),
            'role' => 'user',
            'phone' => $phone,
            'is_active' => true,
        ]);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) && !str_ends_with($email, '@phone.codebridges.com')) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                Log::warning('Email verification dispatch failed: ' . $e->getMessage());
            }
        }

        /** @var JwtService $jwtService */
        $jwtService = app(JwtService::class);
        $jwtToken = $jwtService->generateTokenForUser($user);
        $refreshToken = $jwtService->generateRefreshTokenForUser($user);
        $expiresAt = now()->addSeconds($jwtService->getTtlSeconds());
        $tokenResult = $user->createToken('pos_token', ['*'], $expiresAt);
        $plainToken = $tokenResult->plainTextToken;
        $jwtService->bindSession($tokenResult->accessToken);
        $jwtToken = $jwtService->generateTokenForUser($user, ['sid' => (string) $tokenResult->accessToken->id]);
        $refreshToken = $jwtService->generateRefreshTokenForUser($user, ['sid' => (string) $tokenResult->accessToken->id]);

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('auth_tenant_id', $user->tenant_id);

        $this->recordAuditLog(
            action: 'auth.register',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "User registered account {$user->email}",
            userId: $user->id,
            userName: $user->name,
            tenantId: $user->tenant_id
        );

        return $this->createdResponse(
            message: 'User account registered successfully.',
            extra: [
                'access_token' => $jwtToken,
                'refresh_token' => $refreshToken,
                'sanctum_token' => $plainToken,
                'token_type' => 'Bearer',
                'expires_in' => $jwtService->getTtlSeconds(),
                'user' => new UserResource($user),
            ]
        );
    }

    /**
     * Get currently authenticated user details.
     * GET /api/v1/me
     */
    #[OpenApiResponse(status: 200, description: 'Authenticated user profile', type: 'array{status: string, message: string, user: \App\Http\Resources\User\UserResource}')]
    public function me(Request $request)
    {
        $user = $request->user();
        if ($user && $user->tenant_id) {
            $tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
            if ($tenant) {
                $user->tenant_name = $tenant->name;
                $user->company_name = $tenant->name;
                $user->tenant_logo_url = $tenant->logo_url ?? null;
                $user->logo_url = $tenant->logo_url ?? null;
            }
        }

        return $this->successResponse(
         
            extra: [
                'user' => new UserResource($user),
            ]
        );
    }

    /**
     * Update user .
     * PUT|POST /api/v1/auth/profile
     */
    #[OpenApiResponse(status: 200, description: 'Profile updated successfully', type: 'array{status: string, message: string, user: \App\Http\Resources\User\UserResource}')]
    #[OpenApiResponse(status: 401, description: 'Unauthenticated', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Tenant membership required for PIN update', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 500, description: 'Failed to store avatar image', type: 'array{status: string, code: string, message: string}')]
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $validated = $request->validated();

        $updateData = [];
        if (isset($validated['name'])) $updateData['name'] = trim($validated['name']);
        if (array_key_exists('phone', $validated)) $updateData['phone'] = $validated['phone'];

        // Secure PIN update: Require explicit tenant membership, hash with bcrypt
        if (!empty($validated['pin_code'])) {
            if (empty($user->tenant_id)) {
                return $this->errorResponse(
                    'Setting a POS PIN requires an active tenant membership.',
                    HttpStatus::UNPROCESSABLE_ENTITY,
                    ResponseCode::TENANT_REQUIRED
                );
            }

            UserPosPin::updateOrCreate(
                ['tenant_id' => $user->tenant_id, 'user_id' => $user->id],
                [
                    'pin_hash' => Hash::make((string) $validated['pin_code']),
                    'is_active' => true,
                    'failed_attempts' => 0,
                    'updated_at' => now(),
                ]
            );
        }

        // Profile Image CRUD (MinIO S3 with WebP conversion & local fallback)
        $avatarFile = $request->file('avatar') ?? $request->file('avatar_url');

        // 1. Delete / Remove Avatar
        if ($request->boolean('remove_avatar') || ($request->has('avatar_url') && empty($request->avatar_url) && !$avatarFile)) {
            app(\App\Services\ImageService::class)->deleteAvatar($user->avatar_url);
            $updateData['avatar_url'] = null;
        }
        // 2. Create / Update Avatar from File Upload (accepts both avatar_url and avatar)
        elseif ($avatarFile) {
            $uploadedUrl = null;

            try {
                /** @var \App\Services\ImageService $imageService */
                $imageService = app(\App\Services\ImageService::class);
                $uploadedUrl = $imageService->storeAvatar($avatarFile, $user->id, $user->avatar_url);
            } catch (\Throwable $e) {
                Log::error('[updateProfile] Avatar upload failed: ' . $e->getMessage());
                return $this->serverErrorResponse('Failed to store avatar image.');
            }

            $updateData['avatar_url'] = $uploadedUrl;
        }
        // 3. Create / Update Avatar from Direct URL or Base64 (converts Base64 to .webp -> MinIO)
        elseif ($request->has('avatar_url') && !empty($request->avatar_url) && is_string($request->avatar_url)) {
            $url = $request->avatar_url;
            if (str_starts_with($url, 'data:')) {
                abort_unless(preg_match('#^data:image/(png|jpeg|webp|gif);base64,#', $url), 422, 'Unsupported avatar image.');
                try {
                    /** @var \App\Services\ImageService $imageService */
                    $imageService = app(\App\Services\ImageService::class);
                    $url = $imageService->storeAvatar($url, $user->id, $user->avatar_url);
                } catch (\Throwable $e) {
                    Log::error('[updateProfile] Base64 avatar upload failed: ' . $e->getMessage());
                    return $this->serverErrorResponse('Failed to process avatar image.');
                }
            }
            $updateData['avatar_url'] = $url;
        }

        $updateData['updated_at'] = now();

        DB::table('users')->where('id', $user->id)->update($updateData);

        $freshUser = User::find($user->id) ?? $user;
        if (!empty($uploadedUrl)) {
            $freshUser->avatar_url = $uploadedUrl;
        } elseif (array_key_exists('avatar_url', $updateData)) {
            $freshUser->avatar_url = $updateData['avatar_url'];
        }
        if ($freshUser && $freshUser->tenant_id) {
            $tenant = DB::table('tenants')->where('id', $freshUser->tenant_id)->first();
            if ($tenant) {
                $freshUser->tenant_name = $tenant->name;
                $freshUser->company_name = $tenant->name;
                $freshUser->tenant_logo_url = $tenant->logo_url ?? null;
                $freshUser->logo_url = $tenant->logo_url ?? null;
            }
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('auth_tenant_id', $user->tenant_id);

        $this->recordAuditLog(
            action: 'auth.profile_update',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "User updated profile attributes",
            userId: $user->id,
            userName: $freshUser->name ?? $user->name,
            tenantId: $freshUser->tenant_id ?? $user->tenant_id
        );

        return $this->successResponse(
            message: 'Profile updated successfully.',
            extra: [
                'user' => new UserResource($freshUser),
            ]
        );
    }

    /**
     * Backward-compatible alias for update.
     */
    public function updateProfile(UpdateProfileRequest $request)
    {
        return $this->update($request);
    }

    /**
     * Upload User Profile Picture / Avatar (Isolated from profile update).
     * POST /api/v1/auth/upload-profile
     * POST /api/v1/auth/profile/avatar
     */
    #[OpenApiResponse(
        status: 200,
        description: 'Profile avatar uploaded and converted to WebP successfully',
        type: 'array{status: string, message: string, avatar_url: string}'
    )]
    #[OpenApiResponse(status: 401, description: 'Unauthenticated', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Validation error: image file required', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 500, description: 'Failed to process and store image', type: 'array{status: string, code: string, message: string}')]
    public function uploadProfile(UploadProfileImageRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $avatarFile = $request->file('avatar') ?? $request->file('avatar_url');

        if (!$avatarFile && $request->filled('avatar_url') && is_string($request->avatar_url)) {
            $avatarFile = $request->avatar_url;
        }

        if (!$avatarFile) {
            return $this->errorResponse('Image file is required.', HttpStatus::UNPROCESSABLE_ENTITY, ResponseCode::VALIDATION_ERROR);
        }

        try {
            /** @var \App\Services\ImageService $imageService */
            $imageService = app(\App\Services\ImageService::class);
            $uploadedUrl = $imageService->storeAvatar($avatarFile, $user->id, $user->avatar_url);
        } catch (\Throwable $e) {
            Log::error('[uploadProfile] Avatar upload failed: ' . $e->getMessage());
            return $this->serverErrorResponse('Failed to store avatar image.');
        }

        DB::table('users')->where('id', $user->id)->update([
            'avatar_url' => $uploadedUrl,
            'updated_at' => now(),
        ]);

        $freshUser = User::find($user->id) ?? $user;
        if (!empty($uploadedUrl)) {
            $freshUser->avatar_url = $uploadedUrl;
        }
        if ($freshUser && $freshUser->tenant_id) {
            $tenant = DB::table('tenants')->where('id', $freshUser->tenant_id)->first();
            if ($tenant) {
                $freshUser->tenant_name = $tenant->name;
                $freshUser->company_name = $tenant->name;
                $freshUser->tenant_logo_url = $tenant->logo_url ?? null;
                $freshUser->logo_url = $tenant->logo_url ?? null;
            }
        }

        if ($freshUser) {
            Auth::setUser($freshUser);
            $request->setUserResolver(fn () => $freshUser);
            $request->attributes->set('auth_user_id', $freshUser->id);
            $request->attributes->set('auth_tenant_id', $freshUser->tenant_id);
        }

        $this->recordAuditLog(
            action: 'auth.avatar_upload',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "User uploaded profile avatar to MinIO WebP",
            userId: $user->id,
            userName: $freshUser->name ?? $user->name,
            tenantId: $freshUser->tenant_id ?? $user->tenant_id
        );

        return $this->successResponse(
            message: 'Profile image uploaded successfully.',
            extra: [
                'avatar_url' => $uploadedUrl,
            ]
        );
    }

    /**
     * Update account password and invalidate existing active sessions.
     * PUT /api/v1/auth/password
     */
    #[OpenApiResponse(status: 200, description: 'Account password updated successfully', type: 'array{status: string, message: string}')]
    #[OpenApiResponse(status: 401, description: 'Unauthenticated', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Current password provided is incorrect', type: 'array{status: string, code: string, message: string}')]
    public function updatePassword(UpdatePasswordRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $validated = $request->validated();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return $this->errorResponse(
                'The current password you provided is incorrect.',
                HttpStatus::UNPROCESSABLE_ENTITY
            );
        }

        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($validated['new_password']),
            'updated_at' => now(),
        ]);

        // Revoke all personal access tokens for this user
        $user->tokens()->delete();

        // Invalidate all active JWT and refresh tokens globally
        /** @var JwtService $jwtService */
        $jwtService = app(JwtService::class);
        $jwtService->revokeAllUserTokens($user->id);

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('auth_tenant_id', $user->tenant_id);

        $this->recordAuditLog(
            action: 'auth.password_update',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "User changed password and invalidated all active sessions",
            userId: $user->id,
            userName: $user->name,
            tenantId: $user->tenant_id
        );

        return $this->successResponse(message: 'Account password updated successfully.');
    }

    /**
     * User Logout & Token Revocation.
     * POST /api/v1/auth/logout
     */
    #[OpenApiResponse(status: 200, description: 'Logged out successfully', type: 'array{status: string, message: string}')]
    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('auth_tenant_id', $user->tenant_id);

        $this->recordAuditLog(
                action: 'auth.logout',
                module: 'auth',
                entityType: 'user',
                entityId: (string) $user->id,
                details: "User logged out and session terminated",
                userId: $user->id,
                userName: $user->name,
                tenantId: $user->tenant_id
            );
        }

        $request->user()?->currentAccessToken()?->delete();

        // Also revoke stateless JWT token in Redis if Bearer token is present
        $token = $request->bearerToken();
        if ($token && substr_count($token, '.') === 2) {
            /** @var JwtService $jwtService */
            $jwtService = app(JwtService::class);
            $payload = $jwtService->verify($token);
            if (!empty($payload['jti'])) {
                $exp = $payload['exp'] ?? (time() + $jwtService->getTtlSeconds());
                $remainingTtl = max(0, $exp - time());
                $jwtService->revokeToken($payload['jti'], $remainingTtl);
                if (empty($payload['sid'])) {
                    $user->tokens()->delete();
                    $jwtService->revokeAllUserTokens($user->id);
                }
            }
        }

        return $this->successResponse(message: 'Logged out successfully');
    }

    private function parseDeviceName(?string $userAgent): string
    {
        if (empty($userAgent)) return 'Web Client';
        if (str_contains($userAgent, 'Windows')) return 'Windows PC';
        if (str_contains($userAgent, 'Macintosh')) return 'Mac Desktop';
        if (str_contains($userAgent, 'iPhone')) return 'Apple iPhone';
        if (str_contains($userAgent, 'iPad')) return 'Apple iPad';
        if (str_contains($userAgent, 'Android')) return 'Android POS Device';
        if (str_contains($userAgent, 'Linux')) return 'Linux Device';
        return 'Web Browser';
    }

    /**
     * Validate and sanitize redirect_url against allowlisted frontend domains.
     * Prevents Open Redirect (CWE-601) vulnerabilities.
     */
    protected function getSafeRedirectUrl(?string $redirectUrl): ?string
    {
        if (empty($redirectUrl)) {
            return null;
        }

        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        $allowedHosts = array_filter([
            parse_url($frontendUrl, PHP_URL_HOST),
            'localhost',
            '127.0.0.1',
            'codebridge.dev',
            'app.codebridge.dev',
        ]);

        $host = parse_url($redirectUrl, PHP_URL_HOST);
        $scheme = parse_url($redirectUrl, PHP_URL_SCHEME);

        // Allow safe relative paths
        if (!$host && str_starts_with($redirectUrl, '/') && !str_starts_with($redirectUrl, '//')) {
            return $redirectUrl;
        }

        // Validate scheme and host against allowlist
        if ($scheme && in_array(strtolower($scheme), ['http', 'https'], true) && $host && in_array(strtolower($host), $allowedHosts, true)) {
            return $redirectUrl;
        }

        // Fallback to configured safe frontend URL
        return rtrim($frontendUrl, '/') . '/auth/login';
    }
}

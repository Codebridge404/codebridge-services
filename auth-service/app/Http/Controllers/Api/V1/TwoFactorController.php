<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Requests\Auth\TwoFactorConfirmRequest;
use App\Http\Requests\Auth\TwoFactorDisableRequest;
use App\Http\Resources\User\UserResource;
use App\Http\Responses\HttpStatus;
use App\Http\Responses\ResponseCode;
use App\Models\User;
use App\Services\JwtService;
use App\Services\TwoFactorService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Two-Factor Authentication (2FA) Controller.
 *
 * Implements RFC 6238 TOTP Authenticator-App 2FA:
 * 1. POST /api/v1/auth/2fa/setup     - Generates secret key, otpauth setup URI, and QR code.
 * 2. POST /api/v1/auth/2fa/confirm   - Verifies first code, activates 2FA, and returns recovery codes.
 * 3. POST /api/v1/auth/2fa/challenge - Validates login challenge token + 6-digit TOTP code or recovery code.
 * 4. POST /api/v1/auth/2fa/disable   - Disables 2FA with user's current password.
 * 5. POST /api/v1/auth/2fa/toggle    - Backward-compatible endpoint for toggling 2FA state.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        protected TwoFactorService $twoFactorService,
        protected JwtService $jwtService
    ) {}

    /**
     * Setup Two-Factor Authentication.
     * Generates a new Base32 secret key, otpauth URI, and QR code URL for Google Authenticator.
     *
     * POST /api/v1/auth/2fa/setup
     */
    #[OpenApiResponse(
        status: 200,
        description: 'Two-factor authentication secret and QR code generated successfully',
        type: 'array{status: string, message: string, secret: string, setup_uri: string, qr_code_url: string, qr_code_svg: string}'
    )]
    #[OpenApiResponse(status: 401, description: 'Unauthenticated', type: 'array{status: string, code: string, message: string}')]
    public function setup(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        // [P1] If 2FA is already enabled, require password reauthentication to prevent unauthorized replacement
        if ($user->two_factor_enabled) {
            if (!$request->filled('password') || !Hash::check((string) $request->input('password'), $user->password)) {
                return $this->errorResponse(
                    'Current password confirmation is required to reconfigure two-factor authentication.',
                    HttpStatus::UNPROCESSABLE_ENTITY,
                    ResponseCode::INVALID_CREDENTIALS
                );
            }
        }

        // [P1] Keep the replacement secret pending in cache until confirmed with a valid TOTP code.
        // Never overwrite an existing active authenticator before successful confirmation.
        $secret = $this->twoFactorService->generateSecretKey(32);
        $this->twoFactorService->storePendingSecret($user, $secret);
        if (!$user->two_factor_enabled && empty($user->two_factor_secret)) {
            $this->twoFactorService->storeSecret($user, $secret);
        }

        $company = config('app.name', 'Codebridge');
        $setupUri = $this->twoFactorService->getSetupUri($company, $user->email, $secret);
        $qrCodeSvg = $this->twoFactorService->getQrCodeSvg($setupUri);
        $qrCodeDataUri = $this->twoFactorService->getQrCodeDataUri($setupUri);

        return $this->successResponse(
            message: 'Two-factor authentication secret generated. Scan the QR code or enter the secret manually, then verify with /auth/2fa/confirm.',
            headers: [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ],
            extra: [
                'secret' => $secret,
                'setup_uri' => $setupUri,
                'qr_code_url' => $qrCodeDataUri,
                'qr_code_svg' => $qrCodeSvg,
            ]
        );
    }

    /**
     * Confirm & Enable Two-Factor Authentication.
     * Verifies the 6-digit TOTP code from authenticator app, activates 2FA, and returns 8 one-time recovery codes.
     *
     * POST /api/v1/auth/2fa/confirm
     */
    #[OpenApiResponse(
        status: 200,
        description: 'Two-factor authentication confirmed and enabled successfully',
        type: 'array{status: string, message: string, two_factor_enabled: bool, recovery_codes: array<int, string>}'
    )]
    #[OpenApiResponse(status: 401, description: 'Unauthenticated', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Invalid authentication code or setup not initiated', type: 'array{status: string, code: string, message: string}')]
    public function confirm(TwoFactorConfirmRequest $request)
    {
        /** @var User|null $user */
        $user = $request->user();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        // [P1] Retrieve pending secret first, with fallback to initial secret if not yet enabled
        $secret = $this->twoFactorService->getPendingSecret($user);
        if (!$secret && !$user->two_factor_enabled) {
            $secret = $this->twoFactorService->getDecryptedSecret($user);
        }

        if (!$secret) {
            return $this->errorResponse(
                'Two-factor setup has not been initiated. Please call /auth/2fa/setup first.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::TWO_FACTOR_ENROLLMENT_REQUIRED
            );
        }

        $code = (string) $request->input('code');
        if (!$this->twoFactorService->verifyCode($secret, $code)) {
            return $this->errorResponse(
                'The provided two-factor authentication code is invalid or has expired.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::VALIDATION_ERROR
            );
        }

        // Save confirmed secret permanently and clear pending secret
        $this->twoFactorService->storeSecret($user, $secret);
        $this->twoFactorService->clearPendingSecret($user);

        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes(8);
        $this->twoFactorService->storeRecoveryCodes($user, $recoveryCodes);

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->recordAuditLog(
            action: 'auth.2fa_enabled',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "User {$user->email} enabled Two-Factor Authentication",
            userId: $user->id,
            userName: $user->name,
            tenantId: $user->tenant_id
        );

        return $this->successResponse(
            message: 'Two-factor authentication has been confirmed and enabled successfully. Store your recovery codes securely.',
            headers: [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ],
            extra: [
                'two_factor_enabled' => true,
                'recovery_codes' => $recoveryCodes,
            ]
        );
    }

    /**
     * Complete Login with 2FA Challenge.
     * Validates temporary challenge token together with 6-digit TOTP code or emergency recovery code.
     *
     * POST /api/v1/auth/2fa/challenge
     */
    #[OpenApiResponse(
        status: 200,
        description: 'Two-factor authentication challenge verified. Full access tokens issued.',
        type: 'array{status: string, message: string, access_token: string, refresh_token: string, token_type: string, expires_in: int, user: \App\Http\Resources\User\UserResource}'
    )]
    #[OpenApiResponse(status: 422, description: 'Invalid challenge token or authentication code', type: 'array{status: string, code: string, message: string}')]
    public function challenge(TwoFactorChallengeRequest $request)
    {
        $challengeToken = (string) $request->input('challenge_token');
        $code = trim((string) $request->input('code'));

        $user = $this->twoFactorService->validateChallengeToken($challengeToken);
        if (!$user) {
            return $this->errorResponse(
                'The two-factor challenge token is invalid or has expired. Please sign in again.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::VALIDATION_ERROR
            );
        }

        $secret = $this->twoFactorService->getDecryptedSecret($user);
        $isCodeValid = false;

        // 1. Check TOTP authenticator code
        if ($secret && $this->twoFactorService->verifyCode($secret, $code)) {
            $isCodeValid = true;
        }
        // 2. Check emergency recovery code
        elseif ($this->twoFactorService->verifyAndConsumeRecoveryCode($user, $code)) {
            $isCodeValid = true;
        }

        if (!$isCodeValid) {
            return $this->errorResponse(
                'The provided authentication code or recovery code is incorrect.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::VALIDATION_ERROR
            );
        }

        // Invalidate challenge token once verified
        $this->twoFactorService->consumeChallengeToken($challengeToken);

        // Issue tokens
        $jwtToken = $this->jwtService->generateTokenForUser($user);
        $refreshToken = $this->jwtService->generateRefreshTokenForUser($user);

        $deviceName = $request->input('device_name') ?? $this->parseDeviceName($request->userAgent());
        $expiresAt = now()->addSeconds($this->jwtService->getTtlSeconds());
        $tokenResult = $user->createToken($deviceName, ['*'], $expiresAt);
        $this->jwtService->bindSession($tokenResult->accessToken);
        $jwtToken = $this->jwtService->generateTokenForUser($user, ['sid' => (string) $tokenResult->accessToken->id]);
        $refreshToken = $this->jwtService->generateRefreshTokenForUser($user, ['sid' => (string) $tokenResult->accessToken->id]);

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
            action: 'auth.2fa_challenge_success',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "User {$user->email} passed Two-Factor challenge and logged in",
            userId: $user->id,
            userName: $user->name,
            tenantId: $user->tenant_id
        );

        return $this->successResponse(
            message: 'Two-factor authentication verified successfully.',
            headers: [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ],
            extra: [
                'access_token' => $jwtToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->jwtService->getTtlSeconds(),
                'user' => new UserResource($user),
            ]
        );
    }

    /**
     * Disable Two-Factor Authentication.
     * Disables 2FA on the account after validating the user's current password.
     *
     * POST /api/v1/auth/2fa/disable
     */
    #[OpenApiResponse(
        status: 200,
        description: 'Two-Factor Authentication disabled successfully',
        type: 'array{status: string, message: string, two_factor_enabled: bool}'
    )]
    #[OpenApiResponse(status: 401, description: 'Unauthenticated', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Current password incorrect', type: 'array{status: string, code: string, message: string}')]
    public function disable(TwoFactorDisableRequest $request)
    {
        /** @var User|null $user */
        $user = $request->user();
        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $password = (string) $request->input('password');
        if ($user->password && !Hash::check($password, $user->password)) {
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

        $this->recordAuditLog(
            action: 'auth.2fa_disabled',
            module: 'auth',
            entityType: 'user',
            entityId: (string) $user->id,
            details: "User {$user->email} disabled Two-Factor Authentication",
            userId: $user->id,
            userName: $user->name,
            tenantId: $user->tenant_id
        );

        return $this->successResponse(
            message: 'Two-Factor Authentication disabled successfully.',
            extra: [
                'two_factor_enabled' => false,
            ]
        );
    }

    /**
     * Backward-compatible toggle endpoint for existing clients and tests.
     * POST /api/v1/auth/2fa/toggle
     */
    #[OpenApiResponse(status: 200, description: 'Two-Factor Authentication disabled', type: 'array{status: string, message: string, two_factor_enabled: bool}')]
    #[OpenApiResponse(status: 401, description: 'Unauthenticated', type: 'array{status: string, code: string, message: string}')]
    #[OpenApiResponse(status: 422, description: 'Current password required or 2FA enrollment incomplete', type: 'array{status: string, code: string, message: string}')]
    public function toggle(Request $request)
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
                'Two-factor enrollment and code verification must be completed before enabling 2FA. Please use /auth/2fa/setup and /auth/2fa/confirm.',
                HttpStatus::UNPROCESSABLE_ENTITY,
                ResponseCode::TWO_FACTOR_ENROLLMENT_REQUIRED
            );
        }

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
     * Resolve device name from user-agent header.
     */
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
}

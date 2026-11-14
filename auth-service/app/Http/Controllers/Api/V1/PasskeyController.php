<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserResource;
use App\Http\Responses\HttpStatus;
use App\Http\Responses\ResponseCode;
use App\Models\ApiClient;
use App\Models\PasskeyCredential;
use App\Models\RefreshToken;
use App\Models\User;
use App\Models\UserSession;
use App\Services\DeviceDetectorService;
use App\Services\JwtService;
use App\Services\PasskeyService;
use App\Support\SchemaGuard;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class PasskeyController extends Controller
{
    public function __construct(
        protected PasskeyService $passkeyService,
        protected JwtService $jwtService
    ) {}

    /**
     * Generate options for registering a new passkey.
     * POST /api/v1/auth/passkeys/registration/options
     */
    public function registrationOptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Enforce recent reauthentication (step-up) for adding new login methods
        if (! $this->passkeyService->hasRecentReauth($user, 'passkey_enrollment')) {
            return response()->json([
                'status' => 'error',
                'code' => 'REAUTHENTICATION_REQUIRED',
                'message' => 'Recent reauthentication is required before registering a new passkey.',
            ], 403);
        }

        $options = $this->passkeyService->generateRegistrationOptions($user);

        return response()->json([
            'status' => 'success',
            'data' => $options,
            'message' => 'Passkey registration options generated successfully.',
        ]);
    }

    /**
     * Verify and complete passkey registration.
     * POST /api/v1/auth/passkeys/registration/verify
     */
    public function registrationVerify(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->passkeyService->hasRecentReauth($user, 'passkey_enrollment')) {
            return response()->json([
                'status' => 'error',
                'code' => 'REAUTHENTICATION_REQUIRED',
                'message' => 'Recent reauthentication is required before completing passkey registration.',
            ], 403);
        }

        $validated = $request->validate([
            'challenge_id' => 'required|string',
            'name' => 'nullable|string|max:100',
            'id' => 'nullable|string',
            'response' => 'nullable|array',
            'response.clientDataJSON' => 'required_with:response|string',
            'response.attestationObject' => 'required_with:response|string',
            'credential' => 'nullable|array',
            'credential.id' => 'nullable|string',
            'credential.response' => 'nullable|array',
            'credential.response.clientDataJSON' => 'nullable|string',
            'credential.response.attestationObject' => 'nullable|string',
        ]);

        try {
            $origin = $request->header('Origin') ?? $request->root();
            $credential = $this->passkeyService->verifyRegistration($user, $validated, $origin);

            // Consume reauth grant once enrollment succeeds
            $this->passkeyService->consumeReauthGrant($user, 'passkey_enrollment');

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $credential->id,
                    'credential_id' => $credential->credential_id,
                    'name' => $credential->name,
                    'created_at' => $credential->created_at?->toIso8601String(),
                ],
                'message' => 'Passkey registered successfully.',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_PAYLOAD',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'code' => 'REGISTRATION_FAILED',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Generate options for passkey authentication ceremony (login).
     * POST /api/v1/auth/passkeys/authentication/options
     */
    public function authenticationOptions(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $options = $this->passkeyService->generateAuthenticationOptions($email ? (string) $email : null);

        return response()->json([
            'status' => 'success',
            'data' => $options,
            'message' => 'Passkey authentication options generated successfully.',
        ]);
    }

    /**
     * Verify passkey assertion and issue authenticated session tokens.
     * POST /api/v1/auth/passkeys/authentication/verify
     */
    public function authenticationVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => 'required|string',
            'device_name' => 'nullable|string|max:255',
            'id' => 'nullable|string',
            'response' => 'nullable|array',
            'response.clientDataJSON' => 'required_with:response|string',
            'response.authenticatorData' => 'required_with:response|string',
            'response.signature' => 'required_with:response|string',
            'credential' => 'nullable|array',
            'credential.id' => 'nullable|string',
            'credential.response' => 'nullable|array',
            'credential.response.clientDataJSON' => 'nullable|string',
            'credential.response.authenticatorData' => 'nullable|string',
            'credential.response.signature' => 'nullable|string',
        ]);

        try {
            $origin = $request->header('Origin') ?? $request->root();
            $user = $this->passkeyService->verifyAuthentication($validated, $origin);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_PAYLOAD',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTHENTICATION_FAILED',
                'message' => $e->getMessage(),
            ], 401);
        }

        // Resolve ApiClient
        $client = $request->attributes->get('api_client');
        if (! $client) {
            $clientKey = $request->header('X-Client-Key') ?? $request->header('X-App-Key') ?? $request->input('client_key');
            if ($clientKey) {
                $client = ApiClient::where('client_key', $clientKey)->active()->first();
            }
            if (! $client) {
                $client = ApiClient::where('client_key', 'client_web_admin_cb2026')->first() ?? ApiClient::first();
            }
            if (! $client) {
                $client = ApiClient::create([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'name' => 'Default Platform Client',
                    'client_key' => 'client_web_admin_cb2026',
                    'client_type' => 'first_party',
                    'is_active' => true,
                ]);
            }
        }

        /** @var DeviceDetectorService $detector */
        $detector = app(DeviceDetectorService::class);
        $rawDevice = $request->device_name ?? $detector->parseDeviceName($request->userAgent());
        $deviceName = substr($rawDevice ?: 'Web Browser', 0, 100);

        $sessionPermissions = method_exists($user, 'getAllPermissions')
            ? $user->getAllPermissions()->pluck('name')->values()->toArray()
            : [];

        // Create enterprise UserSession
        $userSession = UserSession::create([
            'user_id' => $user->id,
            'api_client_id' => $client->id,
            'tenant_id' => $user->tenant_id,
            'device_uuid' => $request->header('X-Device-Id') ?? $request->input('device_uuid'),
            'device_name' => $deviceName,
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 512),
            'permissions' => array_values($sessionPermissions),
            'expires_at' => now()->addDays(30),
        ]);

        // Issue rotating refresh token
        $rootPair = RefreshToken::issueRoot($userSession);
        $refreshToken = $rootPair['credential'];

        // Mint access JWT with sid = user_sessions.uuid and passkey assurance flag
        $jwtToken = $this->jwtService->generateTokenForUser($user, [
            'sid' => $userSession->uuid,
            'client_id' => $client->uuid,
            'amr' => ['passkey', 'user_verified'],
        ]);

        if ($user->tenant_id) {
            $tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
            if ($tenant) {
                $user->tenant_name = $tenant->name;
                $user->company_name = $tenant->name;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Passkey authentication successful.',
            'access_token' => $jwtToken,
            'refresh_token' => $refreshToken,
            'session_id' => $userSession->uuid,
            'session_uuid' => $userSession->uuid,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtService->getTtlSeconds(),
            'user' => new UserResource($user),
        ], 200, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * List registered passkeys for caller (display metadata only, no private keys).
     * GET /api/v1/auth/passkeys
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $passkeys = PasskeyCredential::where('user_id', $user->id)
            ->active()
            ->orderBy('created_at', 'desc')
            ->get(['id', 'credential_id', 'name', 'backup_eligible', 'backup_state', 'last_used_at', 'created_at']);

        return response()->json([
            'status' => 'success',
            'data' => $passkeys,
            'total' => $passkeys->count(),
            'message' => 'Passkeys retrieved successfully.',
        ]);
    }

    /**
     * Rename a passkey label.
     * PATCH /api/v1/auth/passkeys/{credential}
     */
    public function update(Request $request, string $credential): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $passkey = PasskeyCredential::where('user_id', $user->id)
            ->where(function ($q) use ($credential) {
                if (is_numeric($credential)) {
                    $q->where('id', (int) $credential);
                } else {
                    $q->where('credential_id', $credential);
                }
            })
            ->active()
            ->first();

        if (! $passkey) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'Passkey credential not found.',
            ], 404);
        }

        $passkey->update(['name' => trim($validated['name'])]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $passkey->id,
                'credential_id' => $passkey->credential_id,
                'name' => $passkey->name,
            ],
            'message' => 'Passkey name updated successfully.',
        ]);
    }

    /**
     * Revoke / Delete a passkey.
     * DELETE /api/v1/auth/passkeys/{credential}
     */
    public function destroy(Request $request, string $credential): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Deletion of credentials requires recent reauthentication
        if (! $this->passkeyService->hasRecentReauth($user, 'credential_management')) {
            return response()->json([
                'status' => 'error',
                'code' => 'REAUTHENTICATION_REQUIRED',
                'message' => 'Recent reauthentication is required before deleting a passkey.',
            ], 403);
        }

        $passkey = PasskeyCredential::where('user_id', $user->id)
            ->where(function ($q) use ($credential) {
                if (is_numeric($credential)) {
                    $q->where('id', (int) $credential);
                } else {
                    $q->where('credential_id', $credential);
                }
            })
            ->active()
            ->first();

        if (! $passkey) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'Passkey credential not found.',
            ], 404);
        }

        // Safe recovery path enforcement: ensure user still has another login factor (password or another active passkey)
        $remainingPasskeys = PasskeyCredential::where('user_id', $user->id)
            ->where('id', '!=', $passkey->id)
            ->active()
            ->count();

        $hasPassword = ! empty($user->password)
            && $user->password !== '!nopassword!'
            && ! str_starts_with((string) $user->password, '!disabled!');

        if (! $hasPassword && $remainingPasskeys === 0) {
            return response()->json([
                'status' => 'error',
                'code' => 'LAST_CREDENTIAL_CANNOT_BE_REMOVED',
                'message' => 'Cannot remove your last remaining authentication credential. Please set a password or enroll another passkey first.',
            ], 422);
        }

        $passkey->update(['revoked_at' => now()]);
        $this->passkeyService->consumeReauthGrant($user, 'credential_management');

        return response()->json([
            'status' => 'success',
            'message' => 'Passkey revoked successfully.',
        ]);
    }

    /**
     * Request options for step-up reauthentication.
     * POST /api/v1/auth/reauthenticate/options
     */
    public function reauthOptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $purpose = $request->input('purpose', 'security_action');
        $passkeyOptions = $this->passkeyService->generateAuthenticationOptions($user->email);

        return response()->json([
            'status' => 'success',
            'data' => [
                'purpose' => $purpose,
                'supported_methods' => ['password', 'passkey'],
                'passkey_options' => $passkeyOptions['options'],
                'challenge_id' => $passkeyOptions['challenge_id'],
            ],
            'message' => 'Reauthentication options generated.',
        ]);
    }

    /**
     * Verify reauthentication via password or passkey and issue short-lived step-up grant.
     * POST /api/v1/auth/reauthenticate/verify
     */
    public function reauthVerify(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $purpose = $request->input('purpose', 'security_action');

        // 1. Password verification path
        if ($request->filled('password')) {
            if (! Hash::check((string) $request->input('password'), $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'The provided password is incorrect.',
                ], 422);
            }

            $grantToken = $this->passkeyService->createReauthGrant($user, $purpose, 600);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'purpose' => $purpose,
                    'grant_token' => $grantToken,
                    'expires_in' => 600,
                ],
                'message' => 'Reauthentication verified successfully.',
            ]);
        }

        // 2. Passkey assertion verification path
        if ($request->filled('challenge_id')) {
            try {
                $origin = $request->header('Origin') ?? $request->root();
                $authUser = $this->passkeyService->verifyAuthentication($request->all(), $origin);

                if ((int) $authUser->id !== (int) $user->id) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'USER_MISMATCH',
                        'message' => 'Passkey assertion did not match the authenticated account.',
                    ], 403);
                }

                $grantToken = $this->passkeyService->createReauthGrant($user, $purpose, 600);

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'purpose' => $purpose,
                        'grant_token' => $grantToken,
                        'expires_in' => 600,
                    ],
                    'message' => 'Reauthentication verified successfully via passkey.',
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'REAUTHENTICATION_FAILED',
                    'message' => $e->getMessage(),
                ], 401);
            }
        }

        return response()->json([
            'status' => 'error',
            'code' => 'METHOD_REQUIRED',
            'message' => 'Password or valid passkey assertion required for reauthentication.',
        ], 422);
    }

    /**
     * Android Digital Asset Links (Public).
     * GET /.well-known/assetlinks.json
     *
     * @unauthenticated
     */
    public function assetLinks(): JsonResponse
    {
        $androidConfig = config('webauthn.android', []);
        $fingerprints = $androidConfig['cert_fingerprints'] ?? [];

        return response()->json([
            [
                'relation' => [
                    'delegate_permission/common.handle_all_urls',
                    'delegate_permission/common.get_login_creds',
                ],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => $androidConfig['package_name'] ?? 'com.codebridge.pos',
                    'sha256_cert_fingerprints' => array_values($fingerprints),
                ],
            ],
        ], 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Apple Universal Links & WebCredentials (Public).
     * GET /.well-known/apple-app-site-association
     *
     * @unauthenticated
     */
    public function appleAppSiteAssociation(): JsonResponse
    {
        $appleConfig = config('webauthn.apple', []);
        $apps = $appleConfig['apps'] ?? [];

        return response()->json([
            'webcredentials' => [
                'apps' => array_values($apps),
            ],
        ], 200, ['Content-Type' => 'application/json']);
    }
}

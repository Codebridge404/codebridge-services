<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * [BD-0001] Central Authentication, SSO, 2FA & Password/PIN Security Controller.
 *
 * @ticket BD-0001
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/AuthController.php
 * @related-model App\Models\User, App\Models\UserRole
 * @entry-point POST /api/v1/auth/login, POST /api/v1/auth/logout, GET /api/v1/me
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Sanitizes login input against null-byte injection.
 * 2. Checks account lockout status (5 failed attempts = 15 min lock).
 * 3. Verifies BCrypt hash and resets failed attempt counters.
 * 4. Issues signed Bearer JWT token and Sanctum personal access token.
 * 5. Returns authenticated user profile, roles, and accessible outlets.
 */
class AuthController extends Controller
{
    /**
     * Standard Email/Phone + Password Login.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required',
            'password' => 'required',
            'device_name' => 'nullable|string',
        ]);

        $loginInput = str_replace("\0", '', trim($request->email));

        $query = User::where('email', $loginInput);
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'phone')) {
            $query->orWhere('phone', $loginInput);
        }
        $user = $query->first();

        // Check if account is locked out
        if ($user && $user->isLockedOut()) {
            $remainingSeconds = now()->diffInSeconds($user->lockout_until);
            return response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_LOCKED_OUT',
                'message' => "Account is temporarily locked out due to multiple failed login attempts. Please try again in {$remainingSeconds} seconds.",
                'retry_after_seconds' => $remainingSeconds,
            ], 429);
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            if ($user) {
                $user->increment('failed_login_attempts');
                if ($user->failed_login_attempts >= 5) {
                    $user->update([
                        'lockout_until' => now()->addMinutes(15),
                        'failed_login_attempts' => 0,
                    ]);
                    // Log audit event safely
                    if (\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
                        $auditPayload = [
                            'user_id' => $user->id,
                            'action' => 'auth.brute_force_lockout',
                            'ip_address' => $request->ip(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        if (\Illuminate\Support\Facades\Schema::hasColumn('audit_logs', 'module')) {
                            $auditPayload['module'] = 'Authentication';
                            $auditPayload['description'] = "Account {$user->email} locked out for 15 minutes after 5 consecutive failed login attempts.";
                        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('audit_logs', 'new_values')) {
                            $auditPayload['new_values'] = json_encode([
                                'description' => "Account {$user->email} locked out for 15 minutes after 5 consecutive failed login attempts.",
                            ]);
                        }
                        DB::table('audit_logs')->insert($auditPayload);
                    }
                }
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials (email or phone number) are incorrect.'],
            ]);
        }

        // Reset failed login attempts on successful login
        if ($user->failed_login_attempts > 0 || $user->lockout_until) {
            $user->update([
                'failed_login_attempts' => 0,
                'lockout_until' => null,
            ]);
        }

        // Generate Sanctum token with client device telemetry
        $deviceName = $request->device_name ?? $this->parseDeviceName($request->userAgent());
        $tokenResult = $user->createToken($deviceName);
        $plainToken = $tokenResult->plainTextToken;

        // Populate device telemetry in personal_access_tokens
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

        // Generate Stateless Enterprise JWT Token
        $jwtService = app(\App\Services\JwtService::class);
        $jwtToken = $jwtService->generateTokenForUser($user);

        return response()->json([
            'status' => 'success',
            'user' => $user,
            'token' => $jwtToken,
            'access_token' => $jwtToken,
            'token_type' => 'Bearer',
            'expires_in' => $jwtService->getTtlSeconds(),
            'sanctum_token' => $plainToken,
        ]);
    }

    /**
     * Cashier Fast PIN Quick-Switch (Physical Register Quick-Switch).
     * POST /api/v1/auth/quick-switch
     */
    public function quickSwitch(Request $request)
    {
        $request->validate([
            'pin_code' => 'required|string|min:4|max:8',
            'outlet_id' => 'nullable|string',
            'device_name' => 'nullable|string',
        ]);

        $pin = trim($request->pin_code);

        $query = User::where('pin_code', $pin)
            ->where(function ($q) {
                $q->where('is_active', true)
                  ->orWhereNull('is_active');
            });

        if ($request->outlet_id) {
            $query->where(function ($q) use ($request) {
                $q->where('outlet_id', $request->outlet_id)
                  ->orWhereNull('outlet_id');
            });
        }

        $user = $query->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_PIN',
                'message' => 'Invalid staff PIN code. Please check and try again.',
            ], 422);
        }

        if ($user->isLockedOut()) {
            $remaining = now()->diffInSeconds($user->lockout_until);
            return response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_LOCKED_OUT',
                'message' => "This cashier account is temporarily locked out. Retry in {$remaining}s.",
                'retry_after_seconds' => $remaining,
            ], 429);
        }

        $deviceName = $request->device_name ?? 'POS Register Terminal (' . $this->parseDeviceName($request->userAgent()) . ')';
        $tokenResult = $user->createToken($deviceName);
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

        // Generate Stateless Enterprise JWT Token
        $jwtService = app(\App\Services\JwtService::class);
        $jwtToken = $jwtService->generateTokenForUser($user);

        return response()->json([
            'status' => 'success',
            'message' => "Welcome back, {$user->name}!",
            'user' => $user,
            'token' => $jwtToken,
            'access_token' => $jwtToken,
            'token_type' => 'Bearer',
            'expires_in' => $jwtService->getTtlSeconds(),
            'sanctum_token' => $plainToken,
        ]);
    }

    /**
     * Active Devices & Sessions Listing.
     * GET /api/v1/auth/sessions
     */
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
                    'ip_address' => $token->ip_address ?? '127.0.0.1',
                    'user_agent' => $token->user_agent,
                    'is_current' => $token->id === $currentTokenId,
                    'last_used_at' => $token->last_used_at ?? $token->created_at,
                    'created_at' => $token->created_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $tokens,
        ]);
    }

    /**
     * Remote Session Revocation.
     * DELETE /api/v1/auth/sessions/{id}
     */
    public function revokeSession(Request $request, $id)
    {
        $user = $request->user();

        $deleted = DB::table('personal_access_tokens')
            ->where('id', $id)
            ->where('tokenable_id', $user->id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'status' => 'error',
                'message' => 'Session not found or already terminated.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Session terminated successfully.',
        ]);
    }

    /**
     * Terminate all devices except current.
     * POST /api/v1/auth/logout-all-devices
     */
    public function logoutAllDevices(Request $request)
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $query = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id);

        if ($currentTokenId && !$request->boolean('include_current')) {
            $query->where('id', '!=', $currentTokenId);
        }

        $revokedCount = $query->delete();

        return response()->json([
            'status' => 'success',
            'message' => "Successfully terminated {$revokedCount} active session(s).",
            'revoked_count' => $revokedCount,
        ]);
    }

    /**
     * Verify Staff Invitation Link.
     * GET /api/v1/auth/verify-invite?token=...
     */
    public function verifyInvite(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing invitation token.',
            ], 422);
        }

        $invitation = DB::table('user_invitations')
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$invitation) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid, expired, or already accepted invitation link.',
            ], 404);
        }

        $tenant = $invitation->tenant_id ? DB::table('tenants')->where('id', $invitation->tenant_id)->first() : null;
        $outlet = $invitation->outlet_id ? DB::table('outlets')->where('id', $invitation->outlet_id)->first() : null;

        return response()->json([
            'status' => 'success',
            'data' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'tenant_name' => $tenant->name ?? 'CodeBridges Store Organization',
                'outlet_name' => $outlet->name ?? 'Primary Store',
                'expires_at' => $invitation->expires_at,
            ],
        ]);
    }

    /**
     * Accept Invitation & Complete Staff Setup.
     * POST /api/v1/auth/accept-invite
     */
    public function acceptInvite(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:6',
            'pin_code' => 'nullable|string|min:4|max:8',
            'phone' => 'nullable|string|max:30',
        ]);

        $invitation = DB::table('user_invitations')
            ->where('token', $validated['token'])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$invitation) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired invitation link.',
            ], 422);
        }

        // Attach or create the user
        $existingUser = DB::table('users')->where('email', $invitation->email)->first();
        if ($existingUser) {
            DB::table('users')->where('id', $existingUser->id)->update([
                'tenant_id' => $invitation->tenant_id,
                'outlet_id' => $invitation->outlet_id,
                'name' => $validated['name'] ?? $existingUser->name,
                'role' => $invitation->role,
                'pin_code' => $validated['pin_code'] ?? $existingUser->pin_code ?? '1234',
                'is_active' => true,
                'updated_at' => now(),
            ]);
            $user = User::find($existingUser->id);
        } else {
            $user = User::create([
                'tenant_id' => $invitation->tenant_id,
                'outlet_id' => $invitation->outlet_id,
                'name' => $validated['name'],
                'email' => $invitation->email,
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'pin_code' => $validated['pin_code'] ?? '1234',
                'role' => $invitation->role,
                'is_active' => true,
            ]);
        }

        // Mark invitation as accepted
        DB::table('user_invitations')->where('id', $invitation->id)->update([
            'accepted_at' => now(),
            'updated_at' => now(),
        ]);

        $tokenResult = $user->createToken('POS Web Portal');

        return response()->json([
            'status' => 'success',
            'message' => 'Staff account setup completed successfully. Welcome aboard!',
            'user' => $user,
            'token' => $tokenResult->plainTextToken,
        ], 201);
    }

    /**
     * Two-Factor Authentication Management.
     */
    public function toggle2fa(Request $request)
    {
        $user = $request->user();
        $enable = $request->boolean('enable');

        $user->update([
            'two_factor_enabled' => $enable,
            'two_factor_secret' => $enable ? strtoupper(Str::random(16)) : null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $enable ? 'Two-Factor Authentication enabled.' : 'Two-Factor Authentication disabled.',
            'two_factor_enabled' => $enable,
            'secret' => $enable ? $user->two_factor_secret : null,
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
            'password' => 'required|string|min:6',
            'role' => 'nullable|string',
        ]);

        $email = $request->email;
        $phone = $request->phone;

        if (!$email && !$phone) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please provide either an email address or a phone number.'
            ], 422);
        }

        if ($email && User::where('email', $email)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This email address is already registered.'
            ], 422);
        }

        if ($phone && User::where('phone', $phone)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This phone number is already registered.'
            ], 422);
        }

        if (!$email && $phone) {
            $cleanedPhone = preg_replace('/[^0-9]/', '', $phone);
            $email = $cleanedPhone . '@phone.codebridges.com';
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $email,
            'password' => Hash::make($request->password),
            'role' => $request->role ?? 'user',
            'phone' => $phone,
        ]);

        $token = $user->createToken('pos_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'User account registered successfully.',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Get currently authenticated user details.
     * GET /api/v1/me
     */
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

        return response()->json([
            'status' => 'success',
            'user' => $user,
        ]);
    }

    /**
     * Update user profile attributes and avatar.
     * PUT|POST /api/v1/auth/profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'phone'         => 'sometimes|nullable|string|max:30',
            'pin_code'      => 'sometimes|nullable|string|max:10',
            'avatar_url'    => 'sometimes|nullable|string',
            'remove_avatar' => 'sometimes|nullable|boolean',
            'avatar'        => 'sometimes|nullable|file|mimes:jpeg,png,jpg,webp,gif,svg|max:10240',
        ]);

        $updateData = [];
        if (isset($validated['name'])) $updateData['name'] = trim($validated['name']);
        if (array_key_exists('phone', $validated)) $updateData['phone'] = $validated['phone'];
        if (array_key_exists('pin_code', $validated)) $updateData['pin_code'] = $validated['pin_code'];

        // Profile Image CRUD
        // 1. Delete / Remove Avatar
        if ($request->boolean('remove_avatar') || ($request->has('avatar_url') && empty($request->avatar_url) && !$request->hasFile('avatar'))) {
            $updateData['avatar_url'] = null;
        }
        // 2. Create / Update Avatar from File Upload
        elseif ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $uploadedUrl = null;

            if (class_exists(\App\Services\CloudinaryService::class)) {
                $cloudinary = new \App\Services\CloudinaryService();
                if ($cloudinary->isConfigured()) {
                    $uploadedUrl = $cloudinary->upload($file, 'codebridges/avatars');
                }
            }

            if (!$uploadedUrl) {
                $ext = $file->getClientOriginalExtension() ?: 'png';
                $filename = 'avatar_' . $user->id . '_' . time() . '.' . $ext;
                $file->storeAs('avatars', $filename, 'public');
                $uploadedUrl = '/storage/avatars/' . $filename;
            }

            $updateData['avatar_url'] = $uploadedUrl;
        }
        // 3. Create / Update Avatar from Direct URL or Base64
        elseif ($request->has('avatar_url') && !empty($request->avatar_url)) {
            $url = $request->avatar_url;
            if (str_starts_with($url, 'data:image/') && class_exists(\App\Services\CloudinaryService::class)) {
                $cloudinary = new \App\Services\CloudinaryService();
                if ($cloudinary->isConfigured()) {
                    $cloudUrl = $cloudinary->upload($url, 'codebridges/avatars');
                    if ($cloudUrl) {
                        $url = $cloudUrl;
                    }
                }
            }
            $updateData['avatar_url'] = $url;
        }

        $updateData['updated_at'] = now();

        DB::table('users')->where('id', $user->id)->update($updateData);

        $freshUser = User::find($user->id);
        if ($freshUser && $freshUser->tenant_id) {
            $tenant = DB::table('tenants')->where('id', $freshUser->tenant_id)->first();
            if ($tenant) {
                $freshUser->tenant_name = $tenant->name;
                $freshUser->company_name = $tenant->name;
                $freshUser->tenant_logo_url = $tenant->logo_url ?? null;
                $freshUser->logo_url = $tenant->logo_url ?? null;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully.',
            'user' => $freshUser,
        ]);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The current password you provided is incorrect.',
            ], 422);
        }

        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($validated['new_password']),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Account password updated successfully.',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully',
        ]);
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
}

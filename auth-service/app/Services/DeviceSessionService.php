<?php

namespace App\Services;

use App\Models\Auth\RefreshToken;
use App\Models\Auth\User;
use App\Models\Auth\UserDevice;
use App\Models\Auth\UserSession;
use App\Support\SchemaGuard;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Unified Device Identity & Multi-Session Governance Service.
 *
 * Manages persistent per-user device installations (via HttpOnly cookie for Web
 * and validated X-Device-Id for native/Flutter apps), concurrent-safe device
 * registration, login IP history vs. latest IP tracking, and grouped device
 * listing/revocation.
 */
class DeviceSessionService
{
    public const DEVICE_COOKIE_NAME = 'cb_device_id';

    public function __construct(
        protected DeviceDetectorService $detector,
        protected JwtService $jwtService,
    ) {}

    /**
     * Resolve and validate the incoming client installation identifier,
     * or generate a new 256-bit cryptographically random identifier.
     *
     * @return array{identifier: string, issue_cookie: bool}
     */
    public function resolveDeviceIdentifier(Request $request): array
    {
        $headerId = $request->header('X-Device-Id')
            ?? $request->input('device_uuid')
            ?? $request->input('device_id');

        if (is_string($headerId) && $this->isValidIdentifierFormat($headerId)) {
            return [
                'identifier' => trim($headerId),
                'issue_cookie' => false,
            ];
        }

        $cookieId = $request->cookie(self::DEVICE_COOKIE_NAME);
        if (is_string($cookieId) && $this->isValidIdentifierFormat($cookieId)) {
            return [
                'identifier' => trim($cookieId),
                'issue_cookie' => true,
            ];
        }

        return [
            'identifier' => bin2hex(random_bytes(32)),
            'issue_cookie' => true,
        ];
    }

    /**
     * Validate installation identifier format (printable ASCII, 16..128 chars).
     */
    public function isValidIdentifierFormat(string $value): bool
    {
        $trimmed = trim($value);
        $len = strlen($trimmed);

        return $len >= 16 && $len <= 128 && preg_match('/^[A-Za-z0-9._:-]+$/', $trimmed) === 1;
    }

    /**
     * Build a persistent HttpOnly device installation cookie.
     */
    public function buildDeviceCookie(string $rawIdentifier, Request $request): Cookie
    {
        $secure = $request->isSecure() || (bool) config('session.secure', false);
        $sameSite = (string) config('session.same_site', 'lax');
        $domain = config('session.domain');

        return cookie(
            name: self::DEVICE_COOKIE_NAME,
            value: $rawIdentifier,
            minutes: 60 * 24 * 365,
            path: '/',
            domain: is_string($domain) && $domain !== '' ? $domain : null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: $sameSite ?: 'lax'
        );
    }

    /**
     * Infer coarse device type from User-Agent / device name.
     */
    public function inferDeviceType(?string $userAgent, string $deviceName): string
    {
        $ua = strtolower((string) $userAgent);
        $name = strtolower($deviceName);

        if (str_contains($ua, 'ipad') || str_contains($name, 'ipad') || str_contains($name, 'tablet')) {
            return 'tablet';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'android') || str_contains($ua, 'mobile') || str_contains($name, 'iphone')) {
            return 'mobile';
        }
        if (str_contains($name, 'pos') || str_contains($name, 'kiosk') || str_contains($name, 'terminal')) {
            return 'pos';
        }

        return 'desktop';
    }

    /**
     * Concurrent-safe find-or-create for a user's persistent device installation.
     */
    public function findOrCreateDevice(
        User $user,
        string $rawIdentifier,
        Request $request,
        ?string $explicitDeviceName = null
    ): UserDevice {
        $identifierHash = hash('sha256', $rawIdentifier);
        $userAgent = (string) ($request->userAgent() ?? '');
        $deviceName = substr($explicitDeviceName ?: $this->detector->parseDeviceName($userAgent), 0, 100);
        $browser = $this->detector->parseBrowser($userAgent);
        $platform = $this->detector->parsePlatform($userAgent);
        $location = $this->detector->parseLocation($request);
        $deviceType = $this->inferDeviceType($userAgent, $deviceName);
        $ip = $request->ip();
        $now = Carbon::now();

        $attributes = [
            'device_name' => $deviceName,
            'device_type' => $deviceType,
            'browser' => $browser,
            'platform' => $platform,
            'last_ip' => $ip,
            'last_seen_at' => $now,
        ];
        if (! empty($location)) {
            $attributes['last_location'] = $location;
        }

        $device = UserDevice::where('user_id', $user->id)
            ->where('identifier_hash', $identifierHash)
            ->first();

        if (! $device) {
            try {
                $device = UserDevice::create(array_merge($attributes, [
                    'user_id' => $user->id,
                    'identifier_hash' => $identifierHash,
                ]));

                return $device;
            } catch (UniqueConstraintViolationException|QueryException $e) {
                $device = UserDevice::where('user_id', $user->id)
                    ->where('identifier_hash', $identifierHash)
                    ->first();

                if (! $device) {
                    throw $e;
                }
            }
        }

        $device->update($attributes);

        return $device;
    }

    /**
     * Create a new authenticated session linked to the persistent UserDevice,
     * issuing rotating refresh token, access JWT, and optional Sanctum PAT.
     *
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $amr
     * @return array{
     *     device: UserDevice,
     *     session: UserSession,
     *     access_token: string,
     *     refresh_token: string,
     *     pat: mixed,
     *     cookie: ?Cookie
     * }
     */
    public function createAuthenticatedSession(
        User $user,
        object $client,
        Request $request,
        ?int $tenantId,
        array $permissions,
        array $amr = ['pwd'],
        bool $createPat = true
    ): array {
        $resolved = $this->resolveDeviceIdentifier($request);
        $explicitName = $request->input('device_name');
        $device = $this->findOrCreateDevice(
            $user,
            $resolved['identifier'],
            $request,
            is_string($explicitName) && $explicitName !== '' ? $explicitName : null
        );

        $userAgent = substr((string) ($request->userAgent() ?? ''), 0, 512);
        $ip = $request->ip();
        $now = Carbon::now();

        $pat = null;
        if ($createPat) {
            $patExpiresAt = $now->copy()->addSeconds($this->jwtService->getRefreshTtlSeconds());
            $tokenResult = $user->createToken($device->device_name, ['*'], $patExpiresAt);
            $pat = $tokenResult->accessToken;
            $this->jwtService->bindSession($pat);

            $tokenMetadata = [
                'ip_address' => $ip,
                'user_agent' => substr($userAgent, 0, 500),
                'device_name' => $device->device_name,
                'last_used_at' => $now,
            ];
            if (SchemaGuard::hasColumn('personal_access_tokens', 'browser')) {
                $tokenMetadata['browser'] = $device->browser;
            }
            if (SchemaGuard::hasColumn('personal_access_tokens', 'platform')) {
                $tokenMetadata['platform'] = $device->platform;
            }
            if (SchemaGuard::hasColumn('personal_access_tokens', 'location') && ! empty($device->last_location)) {
                $tokenMetadata['location'] = $device->last_location;
            }

            try {
                DB::table('personal_access_tokens')
                    ->where('id', $pat->id)
                    ->update($tokenMetadata);
            } catch (\Throwable) {
                try {
                    DB::table('personal_access_tokens')
                        ->where('id', $pat->id)
                        ->update([
                            'ip_address' => $ip,
                            'user_agent' => substr($userAgent, 0, 500),
                            'device_name' => $device->device_name,
                            'last_used_at' => $now,
                        ]);
                } catch (\Throwable) {
                    // Best effort
                }
            }
        }

        $userSession = UserSession::create([
            'user_id' => $user->id,
            'api_client_id' => $client->id,
            'tenant_id' => $tenantId,
            'user_device_id' => $device->id,
            'personal_access_token_id' => $pat?->id,
            'device_uuid' => $device->uuid,
            'device_name' => $device->device_name,
            'login_ip' => $ip,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'browser' => $device->browser,
            'platform' => $device->platform,
            'location' => $device->last_location,
            'permissions' => array_values($permissions),
            'expires_at' => $now->copy()->addDays(30),
            'last_seen_at' => $now,
        ]);

        $rootPair = RefreshToken::issueRoot($userSession);

        $jwtToken = $this->jwtService->generateTokenForUser($user, [
            'sid' => $userSession->uuid,
            'client_id' => $client->uuid,
            'amr' => $amr,
        ]);

        $cookie = $resolved['issue_cookie']
            ? $this->buildDeviceCookie($resolved['identifier'], $request)
            : null;

        return [
            'device' => $device,
            'session' => $userSession,
            'access_token' => $jwtToken,
            'refresh_token' => $rootPair['credential'],
            'pat' => $pat,
            'cookie' => $cookie,
        ];
    }

    /**
     * Revoke all active sessions and credentials belonging to a UserDevice.
     */
    public function revokeDevice(UserDevice $device, string $reason = 'device_logout'): int
    {
        $sessions = UserSession::where('user_device_id', $device->id)
            ->whereNull('revoked_at')
            ->get();

        $revoked = 0;
        foreach ($sessions as $session) {
            $session->revoke($reason);
            $revoked++;
        }

        return $revoked;
    }

    /**
     * Build grouped device list for GET /api/v1/auth/sessions, preserving
     * backward-compatible flat fields for existing UI and tests while grouping
     * login sessions under each recognized device.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDevicesWithSessions(User $user, ?UserSession $currentSession, ?int $currentPatId): array
    {
        $devices = UserDevice::where('user_id', $user->id)
            ->with(['sessions' => function ($q) {
                $q->orderByDesc('last_seen_at')->orderByDesc('id');
            }])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get();

        $items = [];
        $linkedPatIds = [];

        foreach ($devices as $device) {
            $allSessions = $device->sessions;
            foreach ($allSessions as $s) {
                if ($s->personal_access_token_id) {
                    $linkedPatIds[] = (int) $s->personal_access_token_id;
                }
            }

            $activeSessions = $allSessions->filter(fn (UserSession $s) => $s->isActive());
            if ($activeSessions->isEmpty()) {
                continue;
            }

            $isCurrentDevice = $currentSession !== null
                && (int) $currentSession->user_device_id === (int) $device->id;

            $formattedSessions = $allSessions->map(function (UserSession $s) use ($currentSession) {
                $isActive = $s->isActive();
                $status = $s->revoked_at ? 'revoked' : ($isActive ? 'active' : 'expired');

                return [
                    'id' => $s->uuid,
                    'uuid' => $s->uuid,
                    'login_ip' => $s->login_ip ?? $s->ip_address,
                    'ip_address' => $s->ip_address,
                    'is_current' => $currentSession !== null && (int) $currentSession->id === (int) $s->id,
                    'is_active' => $isActive,
                    'status' => $status,
                    'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                    'created_at' => $s->created_at?->toIso8601String(),
                    'expires_at' => $s->expires_at?->toIso8601String(),
                    'revoked_at' => $s->revoked_at?->toIso8601String(),
                ];
            })->values()->all();

            $entry = [
                'id' => $device->uuid,
                'device_uuid' => $device->uuid,
                'name' => $device->device_name,
                'device_name' => $device->device_name,
                'device_type' => $device->device_type,
                'browser' => $device->browser ?? 'Web Browser',
                'platform' => $device->platform ?? 'Unknown OS',
                'ip_address' => $device->last_ip ?? '127.0.0.1',
                'last_ip' => $device->last_ip ?? '127.0.0.1',
                'user_agent' => $activeSessions->first()?->user_agent,
                'is_current' => $isCurrentDevice,
                'is_active' => true,
                'active_sessions_count' => $activeSessions->count(),
                'last_used_at' => $device->last_seen_at?->toIso8601String()
                    ?? $device->created_at?->toIso8601String(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String()
                    ?? $device->created_at?->toIso8601String(),
                'created_at' => $device->created_at?->toIso8601String(),
                'sessions' => $formattedSessions,
            ];

            if (! empty($device->last_location)) {
                $entry['location'] = $device->last_location;
            }

            $items[] = $entry;
        }

        // Legacy compatibility: orphan active UserSessions created without UserDevice
        $orphanSessions = UserSession::where('user_id', $user->id)
            ->whereNull('user_device_id')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get();

        foreach ($orphanSessions as $orphan) {
            if ($orphan->personal_access_token_id) {
                $linkedPatIds[] = (int) $orphan->personal_access_token_id;
            }

            $isCurrent = $currentSession !== null && (int) $currentSession->id === (int) $orphan->id;
            $items[] = [
                'id' => $orphan->uuid,
                'device_uuid' => $orphan->device_uuid ?? $orphan->uuid,
                'name' => $orphan->device_name ?? 'Web Client',
                'device_name' => $orphan->device_name ?? 'Web Client',
                'device_type' => $this->inferDeviceType($orphan->user_agent, (string) ($orphan->device_name ?? '')),
                'browser' => $orphan->browser ?? $this->detector->parseBrowser($orphan->user_agent),
                'platform' => $orphan->platform ?? $this->detector->parsePlatform($orphan->user_agent),
                'ip_address' => $orphan->ip_address ?? '127.0.0.1',
                'last_ip' => $orphan->ip_address ?? '127.0.0.1',
                'user_agent' => $orphan->user_agent,
                'is_current' => $isCurrent,
                'is_active' => true,
                'active_sessions_count' => 1,
                'last_used_at' => ($orphan->last_seen_at ?? $orphan->created_at)?->toIso8601String(),
                'last_seen_at' => ($orphan->last_seen_at ?? $orphan->created_at)?->toIso8601String(),
                'created_at' => $orphan->created_at?->toIso8601String(),
                'sessions' => [[
                    'id' => $orphan->uuid,
                    'uuid' => $orphan->uuid,
                    'login_ip' => $orphan->login_ip ?? $orphan->ip_address,
                    'ip_address' => $orphan->ip_address,
                    'is_current' => $isCurrent,
                    'is_active' => true,
                    'status' => 'active',
                    'last_seen_at' => $orphan->last_seen_at?->toIso8601String(),
                    'created_at' => $orphan->created_at?->toIso8601String(),
                    'expires_at' => $orphan->expires_at?->toIso8601String(),
                    'revoked_at' => null,
                ]],
            ];
        }

        // Legacy compatibility: unlinked PersonalAccessTokens (e.g., LegacyTokenCompatibilityTest)
        $legacyTokensQuery = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->whereIn('tokenable_type', [get_class($user), 'App\Models\User', 'user']);

        if (! empty($linkedPatIds)) {
            $legacyTokensQuery->whereNotIn('id', $linkedPatIds);
        }

        $legacyTokens = $legacyTokensQuery->orderByDesc('last_used_at')->orderByDesc('id')->get();
        foreach ($legacyTokens as $token) {
            if (! empty($token->expires_at) && Carbon::parse($token->expires_at)->isPast()) {
                continue;
            }
            if (! empty($token->session_expires_at) && Carbon::parse($token->session_expires_at)->isPast()) {
                continue;
            }

            $browser = $token->browser ?? $this->detector->parseBrowser($token->user_agent);
            $platform = $token->platform ?? $this->detector->parsePlatform($token->user_agent);
            $deviceName = $token->device_name ?? $this->detector->parseDeviceName($token->user_agent);
            $isCurrent = $currentPatId !== null && (string) $token->id === (string) $currentPatId;

            $entry = [
                'id' => (string) $token->id,
                'name' => $token->name,
                'device_name' => $deviceName,
                'device_type' => $this->inferDeviceType($token->user_agent, (string) $deviceName),
                'browser' => $browser,
                'platform' => $platform,
                'ip_address' => $token->ip_address ?? '127.0.0.1',
                'last_ip' => $token->ip_address ?? '127.0.0.1',
                'user_agent' => $token->user_agent,
                'is_current' => $isCurrent,
                'is_active' => true,
                'active_sessions_count' => 1,
                'last_used_at' => $token->last_used_at
                    ? Carbon::parse($token->last_used_at)->toIso8601String()
                    : ($token->created_at ? Carbon::parse($token->created_at)->toIso8601String() : null),
                'last_seen_at' => $token->last_used_at
                    ? Carbon::parse($token->last_used_at)->toIso8601String()
                    : ($token->created_at ? Carbon::parse($token->created_at)->toIso8601String() : null),
                'created_at' => $token->created_at
                    ? Carbon::parse($token->created_at)->toIso8601String()
                    : null,
                'sessions' => [],
            ];

            if (! empty($token->location)) {
                $entry['location'] = $token->location;
            }

            $items[] = $entry;
        }

        return $items;
    }
}

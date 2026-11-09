<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AuthServiceClient
 *
 * Facilitates inter-service HTTP communication and event dispatching
 * from Operations Service to Auth Service (Database-per-service pattern).
 */
/**
 * [BD-0107] Inter-Service HTTP Client & Tenant Token Validator Service
 *
 * @ticket BD-0107
 * @component operations-service
 * @file codebridge-services/operations-service/app/Services/AuthServiceClient.php
 * @related-model Illuminate\Support\Facades\Http, auth-service endpoints
 * @entry-point AuthServiceClient::validateToken(), getTenantQuota(), verifyUser()
 * @routes
 *  - Service Layer: App\Services\AuthServiceClient
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Microservice URL Resolution): Resolves internal auth-service endpoint URL via Docker network or environment config.
 * Step 2 (Bearer Token Propagation): Passes incoming user JWT token or service-to-service API key in Authorization header.
 * Step 3 (Remote Token Validation): Calls auth-service /api/v1/auth/verify to validate user session and permissions.
 * Step 4 (Tenant Context Caching): Caches verified tenant quotas and permissions locally in Redis for 60 seconds.
 * Step 5 (Circuit Breaker Fallback): Handles network timeouts gracefully and reports auth service connectivity health.
 */
class AuthServiceClient
{
    protected string $baseUrl;
    protected int $timeout;
    protected ?string $appKey;

    public function __construct()
    {
        $configuredUrl = config('services.auth.url', 'http://auth-service:8000/api/v1');
        $this->baseUrl = rtrim((string) $configuredUrl, '/');
        $this->timeout = (int) config('services.auth.timeout', 5);
        $configuredAppKey = config('services.auth.app_key');
        $this->appKey = is_string($configuredAppKey) && trim($configuredAppKey) !== ''
            ? trim($configuredAppKey)
            : null;
    }

    /**
     * Resolve and verify the caller's tenant-scoped authorization context from Auth Service.
     * Consumes the Auth-owned GET /api/v1/auth/authorize-context contract.
     *
     * @return array<string, mixed>|null Returns null only on transport/server outage so callers can fail closed or degrade safely.
     */
    public function authorizeContext(
        string $token,
        ?string $requestedTenantId = null,
        ?string $appKey = null
    ): ?array {
        $tenantScope = ($requestedTenantId !== null && trim($requestedTenantId) !== '')
            ? trim($requestedTenantId)
            : null;
        $effectiveAppKey = ($appKey !== null && trim($appKey) !== '')
            ? trim($appKey)
            : $this->appKey;

        $jwtPayload = $this->extractJwtClaimsForCache($token);
        $configuredTtl = max(0, (int) config('services.auth.context_cache_ttl', 30));
        $effectiveTtl = $configuredTtl;

        if ($jwtPayload !== null && isset($jwtPayload['exp']) && is_numeric($jwtPayload['exp'])) {
            $remaining = (int) $jwtPayload['exp'] - time();
            $effectiveTtl = $remaining > 0 ? min($configuredTtl, $remaining) : 0;
        }

        $tenantKey = $tenantScope !== null ? sha1($tenantScope) : 'default';
        $appKeyFp = $effectiveAppKey !== null ? substr(hash('sha256', $effectiveAppKey), 0, 16) : 'none';
        $cacheKey = 'authz_ctx:' . hash('sha256', $token) . ':' . $tenantKey . ':' . $appKeyFp;

        if ($effectiveTtl > 0) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && !empty($cached['authorized'])) {
                if ($jwtPayload !== null && isset($jwtPayload['exp']) && (int) $jwtPayload['exp'] <= time()) {
                    Cache::forget($cacheKey);
                } elseif ($revocationFailure = $this->checkLocalRevocation($jwtPayload)) {
                    Cache::forget($cacheKey);
                    return $revocationFailure;
                } else {
                    return $cached;
                }
            }
        }

        try {
            $request = Http::timeout($this->timeout)
                ->withToken($token)
                ->acceptJson();

            if ($effectiveAppKey !== null) {
                $request = $request->withHeaders([
                    'X-App-Key' => $effectiveAppKey,
                ]);
            }

            if ($tenantScope !== null) {
                $request = $request->withHeaders([
                    'X-Tenant-Id' => $tenantScope,
                ]);
            }

            $response = $request->get("{$this->baseUrl}/auth/authorize-context");

            if ($response->successful() && $response->json('authorized') === true) {
                if ($revocationFailure = $this->checkLocalRevocation($jwtPayload)) {
                    Cache::forget($cacheKey);
                    return $revocationFailure;
                }

                $data = $response->json('data');
                $result = [
                    'authorized' => true,
                    'status' => 200,
                    'data' => is_array($data) ? $data : [],
                ];

                if ($effectiveTtl > 0) {
                    Cache::put($cacheKey, $result, $effectiveTtl);
                }

                return $result;
            }

            Cache::forget($cacheKey);

            if ($response->clientError()) {
                return [
                    'authorized' => false,
                    'status' => $response->status(),
                    'code' => (string) ($response->json('code') ?? ($response->status() === 401 ? 'UNAUTHENTICATED' : 'TENANT_ACCESS_DENIED')),
                    'message' => (string) ($response->json('message') ?? 'Authorization denied by Auth service.'),
                ];
            }

            Log::warning("AuthServiceClient: authorize-context returned server status {$response->status()}");
            return null;
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            Log::warning("AuthServiceClient: authorize-context request failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Decode unverified JWT payload claims strictly for TTL clamping and live cache revocation checks.
     *
     * @return array<string, mixed>|null
     */
    protected function extractJwtClaimsForCache(string $token): ?array
    {
        if (substr_count($token, '.') !== 2) {
            return null;
        }

        $parts = explode('.', $token);
        $segment = $parts[1] ?? '';
        if ($segment === '') {
            return null;
        }

        $remainder = strlen($segment) % 4;
        if ($remainder) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * Re-verify live token/session revocation stores before honoring a cached authorization context.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    protected function checkLocalRevocation(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $revocationCache = null;
        if (config('cache.stores.revocation.enabled', false) || config('cache.default') === 'redis') {
            try {
                $revocationCache = Cache::store('revocation');
            } catch (\Throwable) {
                $revocationCache = null;
            }
        }

        $jti = $payload['jti'] ?? null;
        if (is_string($jti) && $jti !== '') {
            if (Cache::get("revoked_jwt:{$jti}") || ($revocationCache && $this->safeRevocationGet($revocationCache, "revoked_jwt:{$jti}"))) {
                return [
                    'authorized' => false,
                    'status' => 401,
                    'code' => 'TOKEN_REVOKED',
                    'message' => 'Token has been revoked. Please sign in again.',
                ];
            }
        }

        $sid = $payload['sid'] ?? null;
        if (is_string($sid) && $sid !== '') {
            if (Cache::get("revoked_sid:{$sid}") || ($revocationCache && $this->safeRevocationGet($revocationCache, "revoked_sid:{$sid}"))) {
                return [
                    'authorized' => false,
                    'status' => 401,
                    'code' => 'SESSION_REVOKED',
                    'message' => 'Session has been revoked. Please sign in again.',
                ];
            }
        }

        $sub = $payload['sub'] ?? null;
        if ($sub !== null && $sub !== '') {
            $iat = (int) ($payload['iat'] ?? 0);
            $revokedAt = $this->getMaxNumericRevocationValue("revoked_user_sessions:{$sub}", $revocationCache);
            if ($revokedAt !== null && $iat <= $revokedAt) {
                return [
                    'authorized' => false,
                    'status' => 401,
                    'code' => 'SESSION_REVOKED',
                    'message' => 'User session has been revoked. Please sign in again.',
                ];
            }

            if (isset($payload['session_version'])) {
                $currentVersion = (int) ($this->getMaxNumericRevocationValue("session_version:{$sub}", $revocationCache) ?? 0);
                if ((int) $payload['session_version'] < $currentVersion) {
                    return [
                        'authorized' => false,
                        'status' => 401,
                        'code' => 'SESSION_SUPERSEDED',
                        'message' => 'Session has expired or was revoked by a newer login.',
                    ];
                }
            }
        }

        return null;
    }

    protected function safeRevocationGet(\Illuminate\Contracts\Cache\Repository $store, string $key): mixed
    {
        try {
            return $store->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getMaxNumericRevocationValue(string $key, ?\Illuminate\Contracts\Cache\Repository $revocationCache): ?int
    {
        $local = Cache::get($key);
        $remote = $revocationCache ? $this->safeRevocationGet($revocationCache, $key) : null;

        if ($local === null && $remote === null) {
            return null;
        }

        return max(
            $local !== null ? (int) $local : PHP_INT_MIN,
            $remote !== null ? (int) $remote : PHP_INT_MIN
        );
    }

    /**
     * Verify a Bearer token with Auth Service (Auth authorize-context contract + cryptographic JWT fallback).
     */
    public function verifyToken(string $token, ?string $requestedTenantId = null, ?string $appKey = null): ?array
    {
        if (config('services.auth.verify_via_http', false)) {
            $contract = $this->authorizeContext($token, $requestedTenantId, $appKey);
            if ($contract === null || empty($contract['authorized'])) {
                return null;
            }

            return $contract['data'] ?? null;
        }

        // Fast-path: Stateless cryptographic JWT verification
        if (substr_count($token, '.') === 2) {
            $claims = app(JwtService::class)->verify($token);
            if ($claims && !empty($claims['sub'])) {
                return [
                    'id' => (int) $claims['sub'],
                    'name' => $claims['name'] ?? 'User',
                    'email' => $claims['email'] ?? '',
                    'role' => $claims['role'] ?? 'cashier',
                    'tenant_id' => $claims['tenant_id'] ?? null,
                    'outlet_id' => $claims['outlet_id'] ?? null,
                    'permissions' => $claims['permissions'] ?? [],
                ];
            }
        }

        // Fallback: HTTP call to Auth Service authorize-context
        $contract = $this->authorizeContext($token, $requestedTenantId, $appKey);
        if ($contract !== null && !empty($contract['authorized'])) {
            return $contract['data'] ?? null;
        }

        return null;
    }

    /**
     * Verify a 4-digit supervisor PIN for high-risk POS operations
     */
    public function verifySupervisorPin(
        string $pin,
        ?string $token = null,
        ?string $appKey = null,
        ?string $tenantId = null
    ): bool {
        try {
            $request = Http::timeout($this->timeout)->acceptJson();

            if ($token) {
                $request = $request->withToken($token);
            }

            $effectiveAppKey = ($appKey !== null && trim($appKey) !== '')
                ? trim($appKey)
                : $this->appKey;
            if ($effectiveAppKey !== null) {
                $request = $request->withHeaders(['X-App-Key' => $effectiveAppKey]);
            }

            if ($tenantId !== null && trim($tenantId) !== '') {
                $request = $request->withHeaders(['X-Tenant-Id' => trim($tenantId)]);
            }

            $response = $request->post("{$this->baseUrl}/auth/verify-pin", [
                'pin_code' => $pin,
            ]);

            return $response->successful() && ($response->json('status') === 'success' || $response->json('valid') === true);
        } catch (\Throwable $e) {
            Log::error("AuthServiceClient: supervisor PIN verification error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Fetch outlet metadata from Auth Service
     */
    public function getOutlet(int $outletId, ?string $token = null): ?array
    {
        try {
            $request = Http::timeout($this->timeout)->acceptJson();
            if ($token) {
                $request = $request->withToken($token);
            }

            $response = $request->get("{$this->baseUrl}/outlets/{$outletId}");

            return $response->successful() ? ($response->json('data') ?? $response->json()) : null;
        } catch (\Throwable $e) {
            Log::warning("AuthServiceClient: failed to fetch outlet {$outletId}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Publish an operational event or webhook notification to Auth Service or Event Broker
     */
    public function dispatchEvent(string $eventName, array $payload, ?string $token = null): bool
    {
        try {
            $request = Http::timeout($this->timeout)->acceptJson();
            if ($token) {
                $request = $request->withToken($token);
            }

            $response = $request->post("{$this->baseUrl}/events/operations", [
                'event' => $eventName,
                'payload' => $payload,
                'timestamp' => now()->toIso8601String(),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::info("AuthServiceClient: event {$eventName} dispatched asynchronously / logged: {$e->getMessage()}");
            return false;
        }
    }
}

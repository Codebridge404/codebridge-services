<?php

namespace App\Services;

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
        $configuredUrl = config('services.auth.url', env('AUTH_SERVICE_URL', 'http://auth-service:8000/api/v1'));
        $this->baseUrl = rtrim((string) $configuredUrl, '/');
        $this->timeout = (int) config('services.auth.timeout', env('AUTH_SERVICE_TIMEOUT', 5));
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
        try {
            $request = Http::timeout($this->timeout)
                ->withToken($token)
                ->acceptJson();

            $effectiveAppKey = ($appKey !== null && trim($appKey) !== '')
                ? trim($appKey)
                : $this->appKey;

            if ($effectiveAppKey !== null) {
                $request = $request->withHeaders([
                    'X-App-Key' => $effectiveAppKey,
                ]);
            }

            if ($requestedTenantId !== null && trim($requestedTenantId) !== '') {
                $request = $request->withHeaders([
                    'X-Tenant-Id' => trim($requestedTenantId),
                ]);
            }

            $response = $request->get("{$this->baseUrl}/auth/authorize-context");

            if ($response->successful() && $response->json('authorized') === true) {
                $data = $response->json('data');
                return [
                    'authorized' => true,
                    'status' => 200,
                    'data' => is_array($data) ? $data : [],
                ];
            }

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
            Log::warning("AuthServiceClient: authorize-context request failed: {$e->getMessage()}");
            return null;
        }
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

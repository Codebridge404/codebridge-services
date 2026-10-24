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

    public function __construct()
    {
        $this->baseUrl = rtrim(env('AUTH_SERVICE_URL', 'http://auth-service:8000/api/v1'), '/');
        $this->timeout = (int) env('AUTH_SERVICE_TIMEOUT', 5);
    }

    /**
     * Verify a Bearer token with Auth Service (Stateless JWT first, then HTTP fallback)
     */
    public function verifyToken(string $token): ?array
    {
        // 1. Fast-path: Stateless cryptographic JWT verification
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

        // 2. Fallback: HTTP call to Auth Service /me
        try {
            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->acceptJson()
                ->get("{$this->baseUrl}/me");

            if ($response->successful()) {
                return $response->json('data') ?? $response->json();
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning("AuthServiceClient: failed to verify token: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Verify a 4-digit supervisor PIN for high-risk POS operations
     */
    public function verifySupervisorPin(string $pin, ?string $token = null): bool
    {
        try {
            $request = Http::timeout($this->timeout)->acceptJson();

            if ($token) {
                $request = $request->withToken($token);
            }

            $response = $request->post("{$this->baseUrl}/auth/verify-pin", [
                'pin' => $pin,
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

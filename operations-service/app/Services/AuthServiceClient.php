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
     * Verify a Bearer token with Auth Service
     */
    public function verifyToken(string $token): ?array
    {
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

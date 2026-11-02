<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0085] Brute Force Protection & Sliding-Window Throttling Middleware
 *
 * @ticket BD-0085
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Middleware/RateLimitAuthMiddleware.php
 * @related-model Illuminate\Support\Facades\RateLimiter
 * @entry-point RateLimitAuthMiddleware::handle()
 * @routes
 *  - Middleware: rate_limit_auth
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Client Fingerprinting): Builds unique rate limit key combining client IP address and target account identity.
 * Step 2 (Sliding Window Counter): Increments Redis or cache-backed hit counter within current rate limit window.
 * Step 3 (Threshold Evaluation): Verifies if request count exceeds maximum threshold (e.g. 5 attempts per 60 seconds).
 * Step 4 (HTTP 429 Throttle Response): Rejects excessive attempts with HTTP 429 Too Many Requests and Retry-After header.
 * Step 5 (Counter Decay & Reset): Automatically decays counter on successful authentication or window expiration.
 */
class RateLimitAuthMiddleware
{
    /**
     * Handle rate limiting for sensitive authentication endpoints (brute-force defense).
     *
     * @param  \Closure(Request): (Response)  $next
     * @param  int  $maxAttempts
     * @param  int  $decayMinutes
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 5, int $decayMinutes = 15): Response
    {
        $ip = $request->ip();
        $identifier = $request->input('email') ?? $request->input('phone') ?? $request->input('login') ?? $request->input('pin_code') ?? 'guest';
        $throttleKey = 'auth_attempt:' . sha1($ip . '|' . $identifier);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = max(1, RateLimiter::availableIn($throttleKey));

            return response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_LOCKED_OUT',
                'message' => 'Too many login attempts. Please try again later.',
                'retry_after_seconds' => $seconds,
            ], 429, [
                'Retry-After' => (string) $seconds,
                'Cache-Control' => 'no-store',
            ]);
        }

        $response = $next($request);

        // If response indicates a 401, 403, or 422 authentication error, record the hit
        if ($response->getStatusCode() === 401 
            || (($response->getStatusCode() === 422 || $response->getStatusCode() === 403) && $request->is('*/auth/login', '*/auth/quick-switch', '*/auth/verify-pin'))) {
            RateLimiter::hit($throttleKey, $decayMinutes * 60);
        } elseif ($response->isSuccessful()) {
            RateLimiter::clear($throttleKey);
        }

        return $response;
    }
}

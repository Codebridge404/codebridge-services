<?php

namespace App\Http\Middleware;

use App\Support\LogRedactor;
use App\Support\TelemetryEmitter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0112] Operations HTTP Audit & Request Activity Logging Middleware
 *
 * Process Capabilities:
 * 1. Assigns and propagates X-Request-ID and X-Trace-ID correlation headers across request & response.
 * 2. Recursively redacts passwords, PINs, tokens, CVVs, cards, and secrets in body, nested payloads, and URL queries.
 * 3. Measures execution duration and records structured telemetry independently of LOG_LEVEL=error.
 */
class LogRequestActivity
{
    /**
     * Handle an incoming request and log activity in real-time.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        if (!$request->headers->has('X-Request-ID')) {
            $request->headers->set('X-Request-ID', (string) Str::uuid());
        }
        $requestId = (string) $request->header('X-Request-ID');

        if (!$request->headers->has('X-Trace-ID')) {
            $request->headers->set('X-Trace-ID', substr(md5($requestId), 0, 32));
        }
        $traceId = (string) $request->header('X-Trace-ID');

        TelemetryEmitter::beginRequest($requestId, $traceId);

        $response = $next($request);

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Trace-ID', $traceId);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $statusCode = $response->getStatusCode();

        $sanitizedInput = LogRedactor::sanitizeArray($request->all());
        $redactedTarget = LogRedactor::sanitizePathAndQuery($request);

        $user = $request->user();
        $userInfo = $user ? "User #{$user->id} [{$user->role}]" : "Guest";
        $tenantId = $request->attributes->get('auth_tenant_id')
            ?? $user?->tenant_id
            ?? ($request->header('X-Tenant-ID') ?: null);

        Log::info("HTTP ACTIVITY | {$request->method()} {$redactedTarget} | Status: {$statusCode} | {$duration}ms | {$userInfo} | IP: {$request->ip()}", [
            'request_id' => $requestId,
            'trace_id' => $traceId,
            'input' => count($sanitizedInput) > 0 ? $sanitizedInput : null,
        ]);

        TelemetryEmitter::recordHttpRequest(
            method: $request->method(),
            path: $redactedTarget,
            statusCode: $statusCode,
            durationMs: $duration,
            tenantId: $tenantId,
            requestId: $requestId,
            traceId: $traceId
        );

        return $response;
    }
}

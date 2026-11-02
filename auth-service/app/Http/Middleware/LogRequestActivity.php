<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0084] HTTP Request Activity & Forensic Audit Logger Middleware
 *
 * Process Capabilities:
 * 1. Measures full request lifecycle latency (duration_ms).
 * 2. Assigns/propagates X-Request-ID and X-Trace-ID correlation headers.
 * 3. Recursively sanitizes credentials, PINs, and token secrets.
 * 4. Logs all HTTP transactions into `api_request_logs` table.
 * 5. Automatically records business mutations (POST, PUT, PATCH, DELETE) into `audit_logs` table.
 * 6. Streamlines telemetry into standard Laravel log channels.
 */
class LogRequestActivity
{
    /**
     * Sensitive parameters to redact from logged payloads.
     */
    protected array $sensitiveKeys = [
        'code',
        'hash',
        'signature',
        'challenge_token',
        'bot_token',
        'recovery_code',
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'pin_code',
        'pin',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'two_factor_secret',
        'credit_card',
        'card_number',
        'cvv',
        'authorization',
    ];

    /**
     * Handle an incoming request and record activity logs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        // Assign correlation request ID and trace ID if absent
        if (!$request->headers->has('X-Request-ID')) {
            $request->headers->set('X-Request-ID', (string) Str::uuid());
        }
        $requestId = $request->header('X-Request-ID');
        $traceId = $request->header('X-Trace-ID') ?? substr(md5((string) $requestId), 0, 32);

        $response = $next($request);

        $durationMs = (int) max(1, round((microtime(true) - $startTime) * 1000));

        // Sanitize input payload
        $sanitizedInput = $this->sanitizeInput($request->all());

        // Extract user and tenant context
        $user = $request->user() ?? \Illuminate\Support\Facades\Auth::user();
        $userId = $user?->id ?? ($request->attributes->has('auth_user_id') ? (int) $request->attributes->get('auth_user_id') : null);
        $tenantId = $user?->tenant_id
            ?? ($request->filled('tenant_id') ? (int) $request->input('tenant_id') : null)
            ?? ($request->attributes->has('auth_tenant_id') ? (int) $request->attributes->get('auth_tenant_id') : null);

        // Extract response status code, error code, error message, and dynamic user context
        $statusCode = $response->getStatusCode();
        $errorCode = null;
        $errorMessage = null;

        if (method_exists($response, 'getData')) {
            try {
                $data = $response->getData(true);
                if (is_array($data)) {
                    if ($statusCode >= 400) {
                        $errorCode = $data['code'] ?? null;
                        $errorMessage = $data['message'] ?? null;
                    } else {
                        // Extract user_id and tenant_id from successful auth response payloads (e.g., login, register, quickSwitch)
                        if (!$userId) {
                            $userPayload = $data['data']['user'] ?? $data['user'] ?? null;
                            if (is_array($userPayload) && isset($userPayload['id'])) {
                                $userId = (int) $userPayload['id'];
                                $tenantId = $tenantId ?? ($userPayload['tenant_id'] ?? null);
                            } elseif (isset($data['data']['id']) && is_numeric($data['data']['id'])) {
                                $userId = (int) $data['data']['id'];
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Ignore non-json responses
            }
        }

        // Fallback: Check route parameters only for explicit user/email verification routes
        if (!$userId && str_contains($request->path(), 'email/verify') && $request->route('id') && is_numeric($request->route('id'))) {
            $candidateId = (int) $request->route('id');
            if (\App\Models\User::where('id', $candidateId)->exists()) {
                $userId = $candidateId;
            }
        }

        // Verify that userId exists in users table before logging to prevent foreign key violations
        if ($userId && !$user) {
            $user = \App\Models\User::find($userId);
            if (!$user) {
                $userId = null;
            } elseif (!$tenantId) {
                $tenantId = $user->tenant_id;
            }
        }

        // 1. Write to api_request_logs table
        $this->logApiRequest(
            request: $request,
            statusCode: $statusCode,
            durationMs: $durationMs,
            userId: $userId,
            tenantId: $tenantId,
            requestId: $requestId,
            traceId: $traceId,
            requestData: $sanitizedInput,
            errorCode: $errorCode,
            errorMessage: $errorMessage
        );

        // 2. Automatically record audit log for successful mutations if not already explicitly logged
        $method = strtoupper($request->method());
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $statusCode < 400) {
            if (!$request->attributes->get('_audit_logged', false)) {
                $this->logAuditMutation(
                    request: $request,
                    user: $user,
                    userId: $userId,
                    tenantId: $tenantId,
                    requestId: $requestId,
                    traceId: $traceId,
                    payload: $sanitizedInput
                );
            }
        }

        // 3. Log activity stream
        $userInfo = $user ? "User #{$user->id} [{$user->role}]" : "Guest";
        Log::info("HTTP ACTIVITY | {$method} {$request->route()?->uri()} | Status: {$statusCode} | {$durationMs}ms | {$userInfo} | IP: {$request->ip()}");

        return $response;
    }

    /**
     * Recursively redact sensitive keys from payloads.
     */
    protected function sanitizeInput(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $this->sensitiveKeys, true)) {
                $sanitized[$key] = '********';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeInput($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Record detailed HTTP request metrics to api_request_logs table.
     */
    protected function logApiRequest(
        Request $request,
        int $statusCode,
        int $durationMs,
        ?int $userId,
        ?int $tenantId,
        string $requestId,
        string $traceId,
        array $requestData,
        ?string $errorCode,
        ?string $errorMessage
    ): void {
        try {
            if (!Schema::hasTable('api_request_logs')) {
                return;
            }

            $route = $request->route();

            ApiRequestLog::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'request_id' => $requestId,
                'cf_ray' => $request->header('CF-Ray'),
                'trace_id' => $traceId,
                'span_id' => substr(md5(microtime()), 0, 16),
                'service_name' => config('app.name', 'auth-service'),
                'environment' => config('app.env', 'production'),
                'instance_name' => gethostname() ?: 'auth-node-1',
                'method' => strtoupper($request->method()),
                'path' => substr(preg_replace('#(email/verify/[^/]+/)[^/]+#', '$1[redacted]', $request->path()), 0, 500),
                'route_name' => $route?->getName(),
                'route_params' => $this->sanitizeInput($route?->parameters() ?? []),
                'query_params' => $this->sanitizeInput($request->query()),
                'request_data' => count($requestData) > 0 ? $requestData : null,
                'status_code' => $statusCode,
                'error_code' => $errorCode,
                'error_message' => $errorMessage ? substr($errorMessage, 0, 500) : null,
                'duration_ms' => $durationMs,
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record api_request_log: ' . $e->getMessage());
        }
    }

    /**
     * Automatically record a business audit log for state-mutating HTTP requests.
     */
    protected function logAuditMutation(
        Request $request,
        $user,
        ?int $userId,
        ?int $tenantId,
        string $requestId,
        string $traceId,
        array $payload
    ): void {
        try {
            if (!Schema::hasTable('audit_logs')) {
                return;
            }

            $path = trim($request->path(), '/');
            $action = strtolower($request->method()) . ' ' . $path;
            $module = 'system';

            $segments = explode('/', $path);
            if (isset($segments[2])) { // e.g. api/v1/users -> users
                $module = $segments[2];
            }

            $auditData = [
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => $action,
                'module' => $module,
                'request_id' => $requestId,
                'trace_id' => $traceId,
                'entity_type' => $module,
                'entity_id' => (string) ($request->route('id') ?? $request->route('user') ?? ''),
                'payload' => count($payload) > 0 ? $payload : null,
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'details' => "HTTP {$request->method()} mutation on /{$path}",
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('audit_logs', 'user_name')) {
                $auditData['user_name'] = $user?->name ?? 'System';
            }

            AuditLog::create($auditData);
        } catch (\Throwable $e) {
            Log::warning('Failed to record auto audit_log: ' . $e->getMessage());
        }
    }
}

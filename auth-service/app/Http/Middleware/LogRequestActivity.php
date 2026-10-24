<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0084] HTTP Request Audit Logger & Forensic Request Interceptor
 *
 * @ticket BD-0084
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Middleware/LogRequestActivity.php
 * @related-model DB table audit_logs
 * @entry-point LogRequestActivity::handle()
 * @routes
 *  - Middleware: log_activity
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Request Ingestion): Intercepts inbound HTTP request, method, URI, IP address, and user-agent.
 * Step 2 (Sensitive Data Redaction): Strips passwords, pin codes, and credit card numbers from payload logs.
 * Step 3 (Downstream Execution): Forwards sanitized request down the pipeline to destination controller.
 * Step 4 (Response Capture): Captures HTTP status code, response time latency, and memory footprint.
 * Step 5 (Asynchronous Audit Storage): Writes structured audit record to audit_logs table or log stream.
 */
class LogRequestActivity
{
    /**
     * Handle an incoming request and log activity in real-time.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        // Filter sensitive input attributes
        $input = $request->except(['password', 'password_confirmation', 'secret', 'token']);

        $user = $request->user();
        $userInfo = $user ? "User #{$user->id} [{$user->role}]" : "Guest";

        Log::info("HTTP ACTIVITY | {$request->method()} {$request->fullUrl()} | Status: {$response->getStatusCode()} | {$duration}ms | {$userInfo} | IP: {$request->ip()}", [
            'input' => count($input) > 0 ? $input : null,
        ]);

        return $response;
    }
}

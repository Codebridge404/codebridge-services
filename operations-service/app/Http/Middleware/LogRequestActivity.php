<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

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

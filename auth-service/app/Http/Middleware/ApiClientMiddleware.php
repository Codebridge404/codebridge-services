<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enterprise API Client Verification Middleware.
 * Validates consuming platform client identities (Web Admin, POS Flutter Terminal, Mobile Runner).
 */
class ApiClientMiddleware
{
    /**
     * Handle an incoming request with an X-Client-Key or X-App-Key header.
     *
     * @param  \Closure(Request): (Response)  $next
     * @param  string  $mode  'optional' or 'required'
     */
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $rawKey = $request->header('X-Client-Key')
            ?? $request->header('X-App-Key')
            ?? $request->header('X-Client-Id')
            ?? $request->input('client_key');

        if (empty($rawKey)) {
            if ($mode === 'required') {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('security_events')) {
                        \App\Models\SecurityEvent::record(
                            eventType: 'app_key_missing',
                            ruleCode: 'SEC_CLIENT_KEY_MISSING',
                            summary: 'Missing API Client Key in required mode',
                            severity: 'low',
                            outcome: 'blocked',
                            confidence: 'confirmed',
                            request: $request,
                            statusCode: 401
                        );
                    }
                } catch (\Throwable) {}

                return response()->json([
                    'status' => 'error',
                    'code' => 'CLIENT_KEY_MISSING',
                    'message' => 'Missing API Client Key. Please provide X-Client-Key or X-App-Key header.',
                ], 401);
            }

            // Optional mode fallback: resolve default platform client
            $defaultHashedKey = hash('sha256', 'client_web_admin_cb2026');
            $defaultClient = ApiClient::where('client_key', $defaultHashedKey)->first() ?? ApiClient::first();
            if ($defaultClient) {
                $request->attributes->set('api_client', $defaultClient);
                $request->attributes->set('api_client_id', $defaultClient->id);
                $request->attributes->set('api_client_uuid', $defaultClient->uuid);
            }

            return $next($request);
        }

        $rawKey = trim((string) $rawKey);
        $hashedKey = hash('sha256', $rawKey);

        $client = ApiClient::where('client_key', $hashedKey)
            ->orWhere('uuid', $rawKey)
            ->first();

        if (!$client) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('security_events')) {
                    \App\Models\SecurityEvent::record(
                        eventType: 'app_key_invalid',
                        ruleCode: 'SEC_INVALID_CLIENT_KEY',
                        summary: 'Invalid API client key',
                        severity: 'medium',
                        outcome: 'blocked',
                        confidence: 'confirmed',
                        request: $request,
                        metadata: ['key_prefix' => substr($rawKey, 0, 8)],
                        statusCode: 401
                    );
                }
            } catch (\Throwable) {}

            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_CLIENT_KEY',
                'message' => 'Invalid API client key.',
            ], 401);
        }

        if (!$client->is_active) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('security_events')) {
                    \App\Models\SecurityEvent::record(
                        eventType: 'app_key_disabled',
                        ruleCode: 'SEC_CLIENT_INACTIVE',
                        summary: 'Deactivated API client attempted access',
                        severity: 'medium',
                        outcome: 'blocked',
                        confidence: 'confirmed',
                        request: $request,
                        statusCode: 401
                    );
                }
            } catch (\Throwable) {}

            return response()->json([
                'status' => 'error',
                'code' => 'CLIENT_INACTIVE',
                'message' => 'API client is deactivated.',
            ], 401);
        }

        $request->attributes->set('api_client', $client);
        $request->attributes->set('api_client_id', $client->id);
        $request->attributes->set('api_client_uuid', $client->uuid);

        return $next($request);
    }
}


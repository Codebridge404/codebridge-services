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
                return response()->json([
                    'status' => 'error',
                    'code' => 'CLIENT_KEY_MISSING',
                    'message' => 'Missing API Client Key. Please provide X-Client-Key or X-App-Key header.',
                ], 401);
            }

            // Optional mode fallback: resolve default platform client
            $defaultClient = ApiClient::where('client_key', 'client_web_admin_cb2026')->first() ?? ApiClient::first();
            if ($defaultClient) {
                $request->attributes->set('api_client', $defaultClient);
                $request->attributes->set('api_client_id', $defaultClient->id);
                $request->attributes->set('api_client_uuid', $defaultClient->uuid);
            }

            return $next($request);
        }

        $rawKey = trim((string) $rawKey);

        $client = ApiClient::where('client_key', $rawKey)
            ->orWhere('uuid', $rawKey)
            ->first();

        if (!$client) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_CLIENT_KEY',
                'message' => 'Invalid API client key.',
            ], 401);
        }

        if (!$client->is_active) {
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


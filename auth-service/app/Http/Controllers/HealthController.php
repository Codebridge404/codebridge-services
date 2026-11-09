<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class HealthController extends Controller
{
    public function web(): View
    {
        return view('welcome');
    }

    public function health(): JsonResponse
    {
        $checks = [
            'database' => false,
            'cache' => false,
        ];

        try {
            DB::select('SELECT 1');
            $checks['database'] = true;

            $probeKey = 'health:' . Str::uuid();
            Cache::put($probeKey, 'ready', 10);
            $cacheReady = Cache::get($probeKey) === 'ready';
            Cache::forget($probeKey);

            if (!$cacheReady) {
                throw new \RuntimeException('Cache unavailable');
            }
            $checks['cache'] = true;
        } catch (\Throwable $e) {
            Log::error('auth-service health probe failed', [
                'database' => $checks['database'],
                'cache' => $checks['cache'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'unhealthy',
                'service' => 'auth-service',
                'checks' => $checks,
            ], 503);
        }

        return response()->json([
            'status' => 'healthy',
            'service' => 'auth-service',
            'version' => config('app.version', '1.0.0'),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}


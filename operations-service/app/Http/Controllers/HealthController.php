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

        $runtime = $this->collectRuntimeMetrics();

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
            Log::error('operations-service health probe failed', [
                'database' => $checks['database'],
                'cache' => $checks['cache'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'unhealthy',
                'service' => 'operations-service',
                'checks' => $checks,
                'runtime' => $runtime,
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }

        return response()->json([
            'status' => 'healthy',
            'service' => 'operations-service',
            'version' => config('app.version', '1.0.0'),
            'checks' => $checks,
            'runtime' => $runtime,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Collect real container CPU %, memory, uptime, and runtime versions from /proc and cgroup v2.
     *
     * @return array<string, mixed>
     */
    protected function collectRuntimeMetrics(): array
    {
        $cores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                $matched = preg_match_all('/^processor\s*:/m', $cpuinfo);
                if ($matched > 0) {
                    $cores = $matched;
                }
            }
        }

        $uptimeSeconds = null;
        if (file_exists('/proc/1')) {
            $pid1Start = @filemtime('/proc/1');
            if ($pid1Start !== false && $pid1Start > 0) {
                $uptimeSeconds = max(0, time() - $pid1Start);
            }
        }

        $cpuPercent = null;
        if (is_readable('/sys/fs/cgroup/cpu.stat')) {
            $stat = @file_get_contents('/sys/fs/cgroup/cpu.stat');
            if ($stat !== false && preg_match('/^usage_usec\s+(\d+)/m', $stat, $m)) {
                $usageUsec = (float) $m[1];
                $now = microtime(true);
                try {
                    $prev = Cache::get('ops:telemetry:cpu_prev');
                    if (is_array($prev) && isset($prev['ts'], $prev['usec'])) {
                        $elapsed = $now - (float) $prev['ts'];
                        $delta = $usageUsec - (float) $prev['usec'];
                        if ($elapsed >= 0.05 && $elapsed <= 120.0 && $delta >= 0) {
                            $cpuPercent = round(min(100.0, ($delta / ($elapsed * 1_000_000.0 * $cores)) * 100.0), 2);
                        }
                    }
                    Cache::put('ops:telemetry:cpu_prev', ['ts' => $now, 'usec' => $usageUsec], 120);
                } catch (\Throwable) {
                }

                if ($cpuPercent === null && $uptimeSeconds !== null && $uptimeSeconds > 0) {
                    $cpuPercent = round(min(100.0, ($usageUsec / ($uptimeSeconds * 1_000_000.0 * $cores)) * 100.0), 2);
                }
            }
        }

        if ($cpuPercent === null) {
            $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0.0]) : [0.0];
            $cpuPercent = round(min(100.0, max(0.0, (((float) ($load[0] ?? 0.0)) / $cores) * 100.0)), 2);
        }

        $containerMemMb = null;
        if (is_readable('/sys/fs/cgroup/memory.current')) {
            $raw = trim((string) @file_get_contents('/sys/fs/cgroup/memory.current'));
            if (is_numeric($raw)) {
                $containerMemMb = round(((float) $raw) / 1048576, 1);
            }
        }

        $memLimitMb = null;
        if (is_readable('/sys/fs/cgroup/memory.max')) {
            $rawMax = trim((string) @file_get_contents('/sys/fs/cgroup/memory.max'));
            if (is_numeric($rawMax) && (float) $rawMax > 0) {
                $memLimitMb = round(((float) $rawMax) / 1048576, 1);
            }
        }
        if ($memLimitMb === null && is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo !== false && preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $meminfo, $m)) {
                $memLimitMb = round(((float) $m[1]) / 1024, 1);
            }
        }

        return [
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'cpu_percent' => $cpuPercent,
            'cpu_cores' => $cores,
            'container_memory_mb' => $containerMemMb,
            'memory_limit_mb' => $memLimitMb,
            'php_memory_mb' => round(memory_get_usage(true) / 1048576, 2),
            'uptime_seconds' => $uptimeSeconds,
        ];
    }
}


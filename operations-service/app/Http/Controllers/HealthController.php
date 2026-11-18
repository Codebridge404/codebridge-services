<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function health(Request $request): JsonResponse
    {
        $includeInternalTelemetry = $request->header('X-Internal-Telemetry') === '1';

        $checks = [
            'database' => false,
            'cache' => false,
        ];

        $runtime = $includeInternalTelemetry ? $this->collectRuntimeMetrics() : null;
        $dbStart = microtime(true);
        $dbLatencyMs = 0.0;

        try {
            DB::select('SELECT 1');
            $dbLatencyMs = max(0.01, round((microtime(true) - $dbStart) * 1000, 2));
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

            $errorPayload = [
                'status' => 'unhealthy',
                'service' => 'operations-service',
                'checks' => $checks,
                'timestamp' => now()->toIso8601String(),
            ];

            if ($includeInternalTelemetry) {
                $errorPayload['runtime'] = $runtime;
                $errorPayload['database'] = $checks['database']
                    ? $this->collectDatabaseTelemetry($dbLatencyMs)
                    : $this->offlineDatabasePayload();
                $errorPayload['entityCounts'] = $checks['database'] ? $this->collectEntityCounts() : $this->emptyEntityCounts();
                $errorPayload['queueMetrics'] = $checks['database'] ? $this->collectQueueMetrics() : ['pending_jobs' => 0, 'failed_jobs' => 0];
            }

            return response()->json($errorPayload, 503);
        }

        $payload = [
            'status' => 'healthy',
            'service' => 'operations-service',
            'version' => config('app.version', '1.0.0'),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($includeInternalTelemetry) {
            $payload['runtime'] = $runtime;
            $payload['database'] = $this->collectDatabaseTelemetry($dbLatencyMs);
            $payload['entityCounts'] = $this->collectEntityCounts();
            $payload['queueMetrics'] = $this->collectQueueMetrics();
        }

        return response()->json($payload);
    }

    /**
     * Collect real database telemetry owned by operations-service (MySQL or SQLite).
     *
     * @return array<string, mixed>
     */
    protected function collectDatabaseTelemetry(float $latencyMs): array
    {
        $conn = DB::connection();
        $driver = $conn->getDriverName();
        $dbName = $conn->getDatabaseName() ?: 'codebridge_operations';

        $version = $driver;
        $sizeMb = 0.0;
        $threadsConnected = 1;
        $threadsRunning = 1;
        $uptimeSeconds = null;
        $tables = [];

        if ($driver === 'sqlite') {
            try {
                $row = $conn->select('SELECT sqlite_version() as v');
                if (!empty($row)) {
                    $version = 'SQLite ' . ($row[0]->v ?? '');
                }
            } catch (\Throwable) {
            }
        } else {
            try {
                $verRow = $conn->select('SELECT VERSION() as v');
                if (!empty($verRow)) {
                    $version = 'MySQL ' . ($verRow[0]->v ?? '');
                }
            } catch (\Throwable) {
            }

            try {
                $statusRows = $conn->select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected', 'Threads_running')");
                foreach ($statusRows as $row) {
                    $var = $row->Variable_name ?? '';
                    $val = (int) ($row->Value ?? 0);
                    if ($var === 'Uptime') {
                        $uptimeSeconds = $val;
                    } elseif ($var === 'Threads_connected') {
                        $threadsConnected = $val;
                    } elseif ($var === 'Threads_running') {
                        $threadsRunning = $val;
                    }
                }
            } catch (\Throwable) {
            }

            try {
                $sizeRow = $conn->select('SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.TABLES WHERE table_schema = DATABASE()');
                if (!empty($sizeRow)) {
                    $sizeMb = (float) ($sizeRow[0]->size_mb ?? 0);
                }
            } catch (\Throwable) {
            }

            try {
                $rawTables = $conn->select('SELECT TABLE_NAME, TABLE_ROWS, ROUND((data_length + index_length) / 1024, 1) AS size_kb, UPDATE_TIME FROM information_schema.TABLES WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT 30');
                foreach ($rawTables as $t) {
                    $tables[] = [
                        'name' => $t->TABLE_NAME ?? $t->table_name ?? 'unknown',
                        'rows' => (int) ($t->TABLE_ROWS ?? $t->table_rows ?? 0),
                        'size_kb' => (float) ($t->size_kb ?? 0),
                        'updated_at' => $t->UPDATE_TIME ?? $t->update_time ?? now()->toIso8601String(),
                    ];
                }
            } catch (\Throwable) {
            }
        }

        return [
            'name' => $dbName,
            'connected' => true,
            'version' => $version,
            'latency' => $latencyMs,
            'sizeMb' => $sizeMb,
            'threadsConnected' => $threadsConnected,
            'threadsRunning' => $threadsRunning,
            'uptimeSeconds' => $uptimeSeconds,
            'tables' => $tables,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function offlineDatabasePayload(): array
    {
        return [
            'name' => DB::connection()->getDatabaseName() ?: 'codebridge_operations',
            'connected' => false,
            'version' => 'Unavailable',
            'latency' => 0.0,
            'sizeMb' => 0.0,
            'threadsConnected' => 0,
            'threadsRunning' => 0,
            'uptimeSeconds' => null,
            'tables' => [],
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function emptyEntityCounts(): array
    {
        return [
            'products' => 0,
            'sales' => 0,
            'categories' => 0,
            'brands' => 0,
            'discounts' => 0,
            'coupons' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function collectEntityCounts(): array
    {
        $counts = $this->emptyEntityCounts();
        $schema = DB::getSchemaBuilder();

        foreach (array_keys($counts) as $table) {
            try {
                if ($schema->hasTable($table)) {
                    $counts[$table] = DB::table($table)->count();
                }
            } catch (\Throwable) {
            }
        }

        return $counts;
    }

    /**
     * @return array{pending_jobs: int, failed_jobs: int}
     */
    protected function collectQueueMetrics(): array
    {
        $schema = DB::getSchemaBuilder();

        return [
            'pending_jobs' => $schema->hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'failed_jobs' => $schema->hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
        ];
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


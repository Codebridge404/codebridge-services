<?php

namespace App\Support;

/**
 * Structured single-line JSON telemetry emitter to stderr.
 * Emits slow queries and slow/failed requests independently of LOG_LEVEL=error.
 */
final class TelemetryEmitter
{
    private static ?string $requestId = null;
    private static ?string $traceId = null;
    private static int $queryCount = 0;
    private static float $dbTimeMs = 0.0;

    /** @var callable|null */
    private static $captureCallback = null;

    public static function reset(): void
    {
        self::$requestId = null;
        self::$traceId = null;
        self::$queryCount = 0;
        self::$dbTimeMs = 0.0;
    }

    public static function beginRequest(?string $requestId = null, ?string $traceId = null): void
    {
        self::$requestId = $requestId;
        self::$traceId = $traceId;
        self::$queryCount = 0;
        self::$dbTimeMs = 0.0;
    }

    public static function finishRequest(): void
    {
        self::reset();
    }

    public static function captureUsing(?callable $callback): void
    {
        self::$captureCallback = $callback;
    }

    private static function configAvailable(): bool
    {
        return function_exists('app') && app()->bound('config');
    }

    public static function slowQueryThresholdMs(): float
    {
        if (!self::configAvailable()) {
            return 100.0;
        }

        return max(0.0, (float) config('telemetry.slow_query_ms', 100.0));
    }

    public static function slowRequestThresholdMs(): float
    {
        if (!self::configAvailable()) {
            return 250.0;
        }

        return max(0.0, (float) config('telemetry.slow_request_ms', 250.0));
    }

    public static function recordQuery(string $connection, string $sql, float $durationMs, int $bindingsCount = 0): void
    {
        self::$queryCount++;
        self::$dbTimeMs += $durationMs;

        if (!self::configAvailable() || !config('telemetry.enabled', true)) {
            return;
        }

        $threshold = self::slowQueryThresholdMs();
        if ($durationMs < $threshold) {
            return;
        }

        self::emit('slow_query', [
            'connection' => $connection,
            'duration_ms' => round($durationMs, 2),
            'threshold_ms' => $threshold,
            'bindings_count' => $bindingsCount,
            'sql' => LogRedactor::normalizeSql($sql),
            'request_id' => self::$requestId,
            'trace_id' => self::$traceId,
        ]);
    }

    public static function recordHttpRequest(
        string $method,
        string $path,
        int $statusCode,
        float $durationMs,
        int|string|null $tenantId = null,
        ?string $requestId = null,
        ?string $traceId = null
    ): void {
        if (!self::configAvailable() || !config('telemetry.enabled', true)) {
            self::finishRequest();
            return;
        }

        $threshold = self::slowRequestThresholdMs();
        $slowRequest = $durationMs >= $threshold;
        $emitAll = (bool) config('telemetry.emit_all_requests', false);

        if ($slowRequest || $statusCode >= 500 || $emitAll) {
            self::emit('http_request', [
                'method' => strtoupper($method),
                'path' => LogRedactor::redactString($path),
                'status_code' => $statusCode,
                'duration_ms' => round($durationMs, 2),
                'threshold_ms' => $threshold,
                'slow_request' => $slowRequest,
                'db_query_count' => self::$queryCount,
                'db_time_ms' => round(self::$dbTimeMs, 2),
                'tenant_id' => $tenantId,
                'request_id' => $requestId ?? self::$requestId,
                'trace_id' => $traceId ?? self::$traceId,
            ]);
        }

        self::finishRequest();
    }

    public static function emit(string $event, array $payload): void
    {
        if (!self::configAvailable()) {
            return;
        }

        $envelope = array_merge([
            'ts' => now()->toIso8601String(),
            'service' => (string) config('app.name', 'codebridge'),
            'env' => (string) config('app.env', 'production'),
            'event' => $event,
        ], LogRedactor::sanitizeArray($payload));

        $line = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($line === false) {
            return;
        }

        if (self::$captureCallback !== null) {
            (self::$captureCallback)($envelope, $line);
        }

        $stream = (string) config('telemetry.stream', 'php://stderr');
        if ($stream === '') {
            return;
        }

        $isTesting = app()->runningUnitTests();
        $forceTestStderr = (bool) config('telemetry.force_stderr_in_tests', false);
        if ($isTesting && $stream === 'php://stderr' && !$forceTestStderr) {
            return;
        }

        $handle = @fopen($stream, 'ab');
        if (is_resource($handle)) {
            @fwrite($handle, $line . "\n");
            @fclose($handle);
        }
    }
}

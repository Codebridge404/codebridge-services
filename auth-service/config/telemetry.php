<?php

return [
    'enabled' => filter_var(env('TELEMETRY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'stream' => env('TELEMETRY_STREAM', 'php://stderr'),
    'force_stderr_in_tests' => filter_var(env('TELEMETRY_FORCE_STDERR_IN_TESTS', false), FILTER_VALIDATE_BOOLEAN),
    'slow_query_ms' => (float) env('TELEMETRY_SLOW_QUERY_MS', 100.0),
    'slow_request_ms' => (float) env('TELEMETRY_SLOW_REQUEST_MS', 250.0),
    'emit_all_requests' => filter_var(env('TELEMETRY_EMIT_ALL_REQUESTS', false), FILTER_VALIDATE_BOOLEAN),
];

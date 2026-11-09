<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Support\TelemetryEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepSixTelemetryAndLatencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_telemetry_emits_json_for_slow_queries_and_requests_under_log_level_error_without_secret_leaks(): void
    {
        $tempTelemetry = storage_path('framework/testing/auth_telemetry_' . uniqid('', true) . '.jsonl');
        File::ensureDirectoryExists(dirname($tempTelemetry));

        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $prevLogChannel = config('logging.default');
        $prevStderrLevel = config('logging.channels.stderr.level');
        $prevSingleLevel = config('logging.channels.single.level');
        $prevStream = config('telemetry.stream');
        $prevSlowQuery = config('telemetry.slow_query_ms');
        $prevSlowReq = config('telemetry.slow_request_ms');
        $prevEmitAll = config('telemetry.emit_all_requests');

        Config::set('logging.default', 'stderr');
        Config::set('logging.channels.stderr.level', 'error');
        Config::set('logging.channels.single.level', 'error');
        Config::set('telemetry.enabled', true);
        Config::set('telemetry.stream', $tempTelemetry);
        Config::set('telemetry.slow_query_ms', 0.0);
        Config::set('telemetry.slow_request_ms', 0.0);
        Config::set('telemetry.emit_all_requests', false);

        try {
            $res = $this->withHeaders([
                'X-Request-ID' => 'req-step6-auth-001',
                'X-Trace-ID' => 'trace-step6-auth-001',
            ])->getJson('/api/v1/me?token=step6_url_secret&company_code=CB01');

            $res->assertOk();
            $res->assertHeader('X-Request-ID', 'req-step6-auth-001');
            $res->assertHeader('X-Trace-ID', 'trace-step6-auth-001');

            $this->assertFileExists($tempTelemetry);
            $raw = trim(File::get($tempTelemetry));
            $this->assertNotEmpty($raw);
            $this->assertStringNotContainsString('step6_url_secret', $raw);
            $this->assertStringContainsString('company_code=CB01', $raw);

            $lines = explode("\n", $raw);
            $events = [];
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                $this->assertIsArray($decoded, 'Every telemetry line must be valid single-line JSON.');
                $events[] = $decoded;
            }

            $slowQueries = array_values(array_filter($events, fn (array $e) => ($e['event'] ?? null) === 'slow_query'));
            $httpRequests = array_values(array_filter($events, fn (array $e) => ($e['event'] ?? null) === 'http_request'));

            $this->assertNotEmpty($slowQueries, 'DB::listen must emit slow_query telemetry events when threshold exceeded.');
            $this->assertNotEmpty($httpRequests, 'LogRequestActivity must emit http_request telemetry even when LOG_LEVEL=error.');

            $lastHttp = end($httpRequests);
            $this->assertSame('GET', $lastHttp['method']);
            $this->assertSame('req-step6-auth-001', $lastHttp['request_id']);
            $this->assertSame('trace-step6-auth-001', $lastHttp['trace_id']);
            $this->assertGreaterThan(0, $lastHttp['db_query_count']);
            $this->assertIsNumeric($lastHttp['duration_ms']);

            // Verify recordQuery scrubs inline literal strings
            TelemetryEmitter::recordQuery('mysql', "SELECT * FROM users WHERE email = 'leak@example.com' AND pin_code = '9999'", 250.0, 2);
            $updatedRaw = File::get($tempTelemetry);
            $this->assertStringNotContainsString('leak@example.com', $updatedRaw);
            $this->assertStringNotContainsString('9999', $updatedRaw);

            // Fast (< slow_request_ms) 200 request with emit_all_requests=false must NOT emit, while 500 ALWAYS emits
            Config::set('telemetry.slow_request_ms', 5000.0);
            File::put($tempTelemetry, '');

            TelemetryEmitter::recordHttpRequest('GET', '/api/v1/me', 200, 12.4, $admin->tenant_id, 'req-fast', 'trace-fast');
            $this->assertSame('', trim(File::get($tempTelemetry)));

            TelemetryEmitter::recordHttpRequest('POST', '/api/v1/auth/login?token=hidden', 500, 14.2, $admin->tenant_id, 'req-err', 'trace-err');
            $errEvent = json_decode(trim(File::get($tempTelemetry)), true);
            $this->assertSame('http_request', $errEvent['event'] ?? null);
            $this->assertSame(500, $errEvent['status_code'] ?? null);
            $this->assertStringNotContainsString('hidden', $errEvent['path'] ?? '');
        } finally {
            Config::set('logging.default', $prevLogChannel);
            Config::set('logging.channels.stderr.level', $prevStderrLevel);
            Config::set('logging.channels.single.level', $prevSingleLevel);
            Config::set('telemetry.stream', $prevStream);
            Config::set('telemetry.slow_query_ms', $prevSlowQuery);
            Config::set('telemetry.slow_request_ms', $prevSlowReq);
            Config::set('telemetry.emit_all_requests', $prevEmitAll);
            TelemetryEmitter::reset();
            if (File::exists($tempTelemetry)) {
                File::delete($tempTelemetry);
            }
        }
    }

    public function test_warm_cache_hot_endpoints_meet_p95_under_200ms_budget(): void
    {
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $outletId = DB::table('outlets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $admin->tenant_id,
            'name' => 'Benchmark Branch',
            'code' => 'BM-01',
            'business_type' => 'cafe_qsr',
            'is_active' => true,
            'is_kds_enabled' => true,
            'is_cfd_enabled' => true,
            'terminal_settings' => json_encode(['receiptCopies' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Warm caches once prior to timed benchmark loop
        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/auth/authorize-context')->assertOk();
        $this->getJson("/api/v1/outlets/{$outletId}/business-profile")->assertOk();

        $endpoints = [
            '/api/v1/me',
            '/api/v1/auth/authorize-context',
            "/api/v1/outlets/{$outletId}/business-profile",
        ];

        foreach ($endpoints as $endpoint) {
            $durations = [];
            for ($i = 0; $i < 20; $i++) {
                $t0 = microtime(true);
                $this->getJson($endpoint)->assertOk();
                $durations[] = (microtime(true) - $t0) * 1000.0;
            }

            sort($durations);
            $p95Index = (int) ceil(0.95 * count($durations)) - 1;
            $p95Ms = $durations[$p95Index];

            $this->assertLessThan(
                200.0,
                $p95Ms,
                sprintf('Auth endpoint %s exceeded 200ms p95 budget (observed %.2fms).', $endpoint, $p95Ms)
            );
        }
    }
}


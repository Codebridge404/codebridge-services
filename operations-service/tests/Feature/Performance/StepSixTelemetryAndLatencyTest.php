<?php

namespace Tests\Feature\Performance;

use App\Models\Product;
use App\Models\User;
use App\Support\TelemetryEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepSixTelemetryAndLatencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_telemetry_emits_json_for_slow_queries_and_requests_under_log_level_error_without_secret_leaks(): void
    {
        $tempTelemetry = storage_path('framework/testing/ops_telemetry_' . uniqid('', true) . '.jsonl');
        File::ensureDirectoryExists(dirname($tempTelemetry));

        Product::create([
            'tenant_id' => null,
            'name' => 'Public Telemetry Water',
            'sku' => 'TEL-H2O',
            'selling_price' => 1.00,
            'is_active' => true,
        ]);

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
                'X-Request-ID' => 'req-step6-ops-001',
                'X-Trace-ID' => 'trace-step6-ops-001',
            ])->getJson('/api/v1/products?token=ops_step6_secret&company_code=OPS01');

            $res->assertOk();
            $res->assertHeader('X-Request-ID', 'req-step6-ops-001');
            $res->assertHeader('X-Trace-ID', 'trace-step6-ops-001');

            $this->assertFileExists($tempTelemetry);
            $raw = trim(File::get($tempTelemetry));
            $this->assertNotEmpty($raw);
            $this->assertStringNotContainsString('ops_step6_secret', $raw);
            $this->assertStringContainsString('company_code=OPS01', $raw);

            $lines = explode("\n", $raw);
            $events = [];
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                $this->assertIsArray($decoded, 'Every telemetry line must be valid single-line JSON.');
                $events[] = $decoded;
            }

            $slowQueries = array_values(array_filter($events, fn (array $e) => ($e['event'] ?? null) === 'slow_query'));
            $httpRequests = array_values(array_filter($events, fn (array $e) => ($e['event'] ?? null) === 'http_request'));

            $this->assertNotEmpty($slowQueries, 'DB::listen must emit slow_query telemetry events.');
            $this->assertNotEmpty($httpRequests, 'LogRequestActivity must emit http_request telemetry even when LOG_LEVEL=error.');

            $lastHttp = end($httpRequests);
            $this->assertSame('GET', $lastHttp['method']);
            $this->assertSame('req-step6-ops-001', $lastHttp['request_id']);
            $this->assertSame('trace-step6-ops-001', $lastHttp['trace_id']);
            $this->assertGreaterThan(0, $lastHttp['db_query_count']);
            $this->assertIsNumeric($lastHttp['duration_ms']);

            // Verify SQL string/hex literals are stripped by LogRedactor::normalizeSql()
            TelemetryEmitter::recordQuery('mysql', "SELECT * FROM products WHERE sku = 'SECRET-SKU' AND note = 0xdeadbeef", 180.0, 2);
            $updatedRaw = File::get($tempTelemetry);
            $this->assertStringNotContainsString('SECRET-SKU', $updatedRaw);
            $this->assertStringNotContainsString('deadbeef', $updatedRaw);
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
        $outletId = DB::table('outlets')->insertGetId([
            'tenant_id' => 'tenant-bench-1',
            'name' => 'Benchmark Cafe',
            'code' => 'BM-OPS-1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catId = DB::table('categories')->insertGetId([
            'tenant_id' => 'tenant-bench-1',
            'name' => 'Coffee',
            'slug' => 'coffee-bench-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $brandId = DB::table('brands')->insertGetId([
            'tenant_id' => 'tenant-bench-1',
            'name' => 'Roastery',
            'slug' => 'roastery-bench-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 1; $i <= 10; $i++) {
            Product::create([
                'tenant_id' => 'tenant-bench-1',
                'category_id' => $catId,
                'brand_id' => $brandId,
                'name' => "Benchmark Drink {$i}",
                'sku' => "BM-DRK-{$i}",
                'selling_price' => 3.50 + ($i * 0.25),
                'cost_price' => 1.00,
                'is_active' => true,
            ]);
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->tenant_id = 'tenant-bench-1';
        Sanctum::actingAs($admin, ['*']);

        // Warm caches once before measuring p95
        $this->getJson("/api/v1/products?outlet_id={$outletId}")->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/currencies/exchange-rate/active')->assertOk();

        $endpoints = [
            "/api/v1/products?outlet_id={$outletId}",
            '/api/v1/categories',
            '/api/v1/currencies/exchange-rate/active',
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
                sprintf('Operations endpoint %s exceeded 200ms p95 budget (observed %.2fms).', $endpoint, $p95Ms)
            );
        }
    }
}


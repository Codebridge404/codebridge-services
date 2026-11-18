<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InfrastructureTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
        ]);
        Sanctum::actingAs($superAdmin, ['*']);

        return $superAdmin;
    }

    public function test_non_super_admin_cannot_access_infrastructure_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/super-admin/infrastructure/telemetry')->assertStatus(403);
        $this->getJson('/api/v1/super-admin/infrastructure/storage')->assertStatus(403);
        $this->postJson('/api/v1/super-admin/infrastructure/storage/test')->assertStatus(403);
    }

    public function test_telemetry_returns_real_host_cpu_memory_and_database_metrics(): void
    {
        Storage::fake('s3');
        $this->actingAsSuperAdmin();

        $res = $this->getJson('/api/v1/super-admin/infrastructure/telemetry');

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'version',
                    'hostMetrics' => [
                        'cpu_percent',
                        'container_cpu_percent',
                        'cpu_cores',
                        'load_average',
                        'php_memory_mb',
                        'php_peak_memory_mb',
                        'disk_total_gb',
                        'disk_used_gb',
                        'disk_free_gb',
                    ],
                    'services',
                    'authDb' => ['name', 'connected', 'version', 'latency', 'sizeMb', 'threadsConnected', 'tables'],
                    'operationsDb',
                    'entityCounts' => ['tenants', 'users', 'outlets', 'roles'],
                    'queueMetrics',
                    'timestamp',
                ],
            ]);

        $data = $res->json('data');
        $this->assertGreaterThanOrEqual(1, $data['hostMetrics']['cpu_cores']);
        $this->assertGreaterThanOrEqual(0, $data['hostMetrics']['cpu_percent']);
        $this->assertLessThanOrEqual(100, $data['hostMetrics']['cpu_percent']);
        $this->assertSame(1, $data['entityCounts']['users']);

        // Confirm no hardcoded fake uptime "99.99%" or "99.98%" remains on any service node
        foreach ($data['services'] as $svc) {
            $this->assertNotSame('99.99%', $svc['uptime']);
            $this->assertNotSame('99.98%', $svc['uptime']);
        }
    }

    public function test_storage_config_reports_real_configured_bucket_and_actual_file_counts(): void
    {
        Storage::fake('s3');
        config([
            'filesystems.disks.s3.endpoint' => 'http://minio:9000',
            'filesystems.disks.s3.bucket' => 'codebridge-auth-live',
            'filesystems.disks.s3.region' => 'ap-southeast-1',
            'filesystems.disks.s3.key' => 'AKIA_SUPER_SECRET_KEY_VALUE',
        ]);

        Storage::disk('s3')->put('avatars/u1.webp', str_repeat('a', 1024));
        Storage::disk('s3')->put('logos/t1.webp', str_repeat('b', 2048));

        $this->actingAsSuperAdmin();

        $res = $this->getJson('/api/v1/super-admin/infrastructure/storage');

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.provider', 'minio')
            ->assertJsonPath('data.endpoint', 'http://minio:9000')
            ->assertJsonPath('data.default_bucket', 'codebridge-auth-live')
            ->assertJsonPath('data.region', 'ap-southeast-1')
            ->assertJsonPath('data.files_count', 2)
            ->assertJsonPath('data.used_bytes', 3072)
            ->assertJsonPath('data.access_key_masked', 'AKIA********');

        // Ensure raw key is never leaked
        $this->assertStringNotContainsString('SUPER_SECRET', $res->getContent());
    }

    public function test_storage_connection_test_performs_real_write_read_delete_and_cleans_up(): void
    {
        Storage::fake('s3');
        config([
            'filesystems.disks.s3.endpoint' => 'http://minio:9000',
            'filesystems.disks.s3.bucket' => 'codebridge-auth',
        ]);

        $this->actingAsSuperAdmin();

        $res = $this->postJson('/api/v1/super-admin/infrastructure/storage/test');

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.read_write_test', 'PASSED')
            ->assertJsonPath('data.bucket_accessible', true)
            ->assertJsonPath('data.bucket', 'codebridge-auth');

        // Verify temporary probe file was deleted cleanly
        $this->assertEmpty(Storage::disk('s3')->allFiles());
    }

    public function test_storage_connection_test_blocks_ssrf_custom_endpoints(): void
    {
        Storage::fake('s3');
        config([
            'filesystems.disks.s3.endpoint' => 'http://minio:9000',
            'filesystems.disks.s3.bucket' => 'codebridge-auth',
        ]);

        $this->actingAsSuperAdmin();

        $res = $this->postJson('/api/v1/super-admin/infrastructure/storage/test', [
            'endpoint' => 'http://169.254.169.254:80',
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.read_write_test', 'FAILED')
            ->assertJsonPath('data.bucket_accessible', false);
    }

    public function test_storage_connection_test_fails_when_real_endpoint_is_unconfigured_or_offline(): void
    {
        config([
            'filesystems.disks.s3.endpoint' => 'http://127.0.0.1:59999',
            'filesystems.disks.s3.bucket' => 'codebridge-auth',
        ]);

        $this->actingAsSuperAdmin();

        $res = $this->postJson('/api/v1/super-admin/infrastructure/storage/test');

        $res->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'offline')
            ->assertJsonPath('data.read_write_test', 'FAILED')
            ->assertJsonPath('data.bucket_accessible', false);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\QueueMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QueueMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_super_admin_cannot_access_queue_monitor_or_retry(): void
    {
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/super-admin/infrastructure/queues')->assertForbidden();
        $this->postJson('/api/v1/super-admin/infrastructure/queues/auth-service/retry/1')->assertForbidden();
    }

    public function test_super_admin_can_view_sanitized_failed_jobs_and_scheduler_heartbeat_states(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        // Insert a failed job containing a sensitive token in payload/exception
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\SendSecurityAlertJob',
                'data' => ['secret_token' => 'super_secret_should_never_leak'],
            ]),
            'exception' => "RuntimeException: Mail server unreachable\nStack trace line 2",
            'failed_at' => now(),
        ]);

        // Healthy scheduler heartbeat
        Cache::put(QueueMonitorService::AUTH_SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), 3600);

        $res = $this->getJson('/api/v1/super-admin/infrastructure/queues')
            ->assertOk()
            ->assertJsonPath('data.services.0.name', 'auth-service')
            ->assertJsonPath('data.services.0.queue.failed_jobs', 1)
            ->assertJsonPath('data.services.0.queue.recent_failed_jobs.0.job_name', 'App\\Jobs\\SendSecurityAlertJob')
            ->assertJsonPath('data.services.0.scheduler.status', 'healthy')
            ->assertJsonPath('data.services.1.name', 'operations-service')
            ->assertJsonPath('data.services.1.reachable', false)
            ->assertJsonPath('data.services.1.queue.worker_status', 'unknown')
            ->assertJsonPath('data.services.1.scheduler.status', 'unknown');

        // Verify sensitive job payload is never exposed
        $this->assertStringNotContainsString('super_secret_should_never_leak', $res->getContent());

        // Stale scheduler heartbeat (> 5 minutes old)
        Cache::put(QueueMonitorService::AUTH_SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String(), 3600);
        $this->getJson('/api/v1/super-admin/infrastructure/queues')
            ->assertOk()
            ->assertJsonPath('data.services.0.scheduler.status', 'stale');
    }

    public function test_super_admin_can_retry_auth_failed_job_and_audit_log_is_recorded(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        $jobUuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $jobUuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\TestRetryJob']),
            'exception' => 'RuntimeException: Transient failure',
            'failed_at' => now(),
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => [$jobUuid]])
            ->andReturn(0);

        $this->postJson("/api/v1/super-admin/infrastructure/queues/auth-service/retry/{$jobUuid}")
            ->assertOk()
            ->assertJsonPath('data.service', 'auth-service')
            ->assertJsonPath('data.job_uuid', $jobUuid);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'queue.job_retry',
            'entity_id' => "auth-service:{$jobUuid}",
        ]);
    }

    public function test_retry_validates_service_name_and_proxies_operations_service_when_configured(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        $this->postJson('/api/v1/super-admin/infrastructure/queues/unknown-svc/retry/1')
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_SERVICE');

        $this->postJson('/api/v1/super-admin/infrastructure/queues/auth-service/retry/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'JOB_NOT_FOUND');

        // Unconfigured operations-service returns 503 honestly
        $this->postJson('/api/v1/super-admin/infrastructure/queues/operations-service/retry/55')
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTERNAL_MONITOR_UNAVAILABLE');

        // Configured operations-service internal URL proxies cleanly
        putenv('OPERATIONS_INTERNAL_QUEUES_URL=http://operations-internal.test/api/v1/internal/queues');
        Http::fake([
            'http://operations-internal.test/api/v1/internal/queues/retry/55' => Http::response([
                'status' => 'success',
                'message' => 'Retried in operations-service.',
            ], 200),
        ]);

        $this->postJson('/api/v1/super-admin/infrastructure/queues/operations-service/retry/55')
            ->assertOk()
            ->assertJsonPath('data.service', 'operations-service');

        putenv('OPERATIONS_INTERNAL_QUEUES_URL=');
    }
}

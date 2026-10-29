<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoggingAndAuditSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test incoming HTTP requests are recorded into api_request_logs with redacted credentials.
     */
    public function test_api_request_logs_captures_requests_and_redacts_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@codebridge.dev',
            'password' => 'SuperSecretPassword123!',
        ]);

        $this->assertDatabaseHas('api_request_logs', [
            'method' => 'POST',
            'path' => 'api/v1/auth/login',
        ]);

        $log = ApiRequestLog::where('path', 'api/v1/auth/login')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNotEmpty($log->request_id);
        $this->assertNotEmpty($log->trace_id);
        $this->assertNotNull($log->duration_ms);

        // Security check: passwords must never be stored in plain text
        $requestData = $log->request_data;
        $this->assertEquals('admin@codebridge.dev', $requestData['email']);
        $this->assertEquals('********', $requestData['password']);
    }

    /**
     * Test successful login generates an explicit audit log.
     */
    public function test_login_generates_explicit_audit_log(): void
    {
        $tenant = Tenant::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Demo Org',
            'slug' => 'demo-org-audit',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'email' => 'staff@demo.dev',
            'password' => 'SecretPass123!',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@demo.dev',
            'password' => 'SecretPass123!',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'module' => 'auth',
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);

        $audit = AuditLog::where('action', 'auth.login')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertEquals($user->name, $audit->user_name);
        $this->assertStringContainsString('logged in successfully', $audit->details);
    }

    /**
     * Test successful mutations automatically record audit logs if not explicitly handled.
     */
    public function test_mutations_automatically_record_audit_logs(): void
    {
        $tenant = Tenant::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Audit Auto Org',
            'slug' => 'audit-auto-org',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/departments', [
            'name' => 'Engineering Ops',
            'code' => 'ENG-OPS',
            'tenant_id' => $tenant->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'departments',
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }
}

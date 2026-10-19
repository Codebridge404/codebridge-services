<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test tenant quota and modules require authentication.
     */
    public function test_tenant_settings_require_authentication(): void
    {
        $this->getJson('/api/v1/tenants/quota-usage')->assertStatus(401);
        $this->getJson('/api/v1/tenants/profile')->assertStatus(401);
        $this->getJson('/api/v1/tenants/modules')->assertStatus(401);
    }

    /**
     * Test authenticated organization owner can view tenant profile and quota.
     */
    public function test_tenant_owner_can_view_quota_and_modules(): void
    {
        $tenantId = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'email' => 'acme@codebridge.dev',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'organization',
            'tenant_id' => $tenantId,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/tenants/quota-usage')->assertStatus(200);
        $this->getJson('/api/v1/tenants/profile')->assertStatus(200);
        $this->getJson('/api/v1/tenants/modules')->assertStatus(200);
    }
}

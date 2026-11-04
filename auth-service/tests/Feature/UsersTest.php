<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test unauthenticated access to users is blocked.
     */
    public function test_unauthenticated_user_access_is_blocked(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
        $this->getJson('/api/v1/users/lookup')->assertStatus(401);
    }

    /**
     * Test admin can access users directory and lookup.
     */
    public function test_admin_can_access_users_list_and_lookup(): void
    {
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/users')->assertStatus(200);
        $this->getJson('/api/v1/users/lookup')->assertStatus(200);
    }

    /**
     * Test inviting staff enforces role and outlet belonging to the invited tenant.
     */
    public function test_inviting_staff_enforces_tenant_ownership_for_role_and_outlet(): void
    {
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
        ]);
        $tenantId = $admin->tenant_id;

        // Create tenant-owned outlet
        $outletUuid = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('outlets')->insert([
            'uuid' => $outletUuid,
            'tenant_id' => $tenantId,
            'name' => 'Branch 1',
            'code' => 'BR-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create other tenant and outlet
        $otherTenantId = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherOutletUuid = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('outlets')->insert([
            'uuid' => $otherOutletUuid,
            'tenant_id' => $otherTenantId,
            'name' => 'Foreign Branch',
            'code' => 'FOR-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create tenant-owned role
        $roleId = \Illuminate\Support\Facades\DB::table('roles')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Barista',
            'slug' => 'barista-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create role belonging to another tenant
        $otherRoleId = \Illuminate\Support\Facades\DB::table('roles')->insertGetId([
            'tenant_id' => $otherTenantId,
            'name' => 'Foreign Role',
            'slug' => 'foreign-role-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['*']);

        // 1. Foreign role must be rejected
        $this->postJson('/api/v1/users/invite', [
            'email' => 'new.staff@example.com',
            'role_id' => $otherRoleId,
        ])->assertStatus(422);

        // 2. Foreign outlet must be rejected
        $this->postJson('/api/v1/users/invite', [
            'email' => 'new.staff@example.com',
            'role_id' => $roleId,
            'outlet_uuid' => $otherOutletUuid,
        ])->assertStatus(422);

        // 3. Valid invitation within tenant succeeds
        $res = $this->postJson('/api/v1/users/invite', [
            'email' => 'new.staff@example.com',
            'role_id' => $roleId,
            'outlet_uuid' => $outletUuid,
        ])->assertStatus(201);

        $res->assertJsonPath('data.email', 'new.staff@example.com');
        $res->assertJsonPath('data.role_id', $roleId);
        $res->assertJsonPath('data.outlet_uuid', $outletUuid);

        // Verify stored invitation in user_invitations
        $invitation = \Illuminate\Support\Facades\DB::table('user_invitations')
            ->where('email', 'new.staff@example.com')
            ->first();
        $this->assertNotNull($invitation);
        $this->assertEquals($tenantId, $invitation->tenant_id);
        $this->assertEquals($roleId, $invitation->role_id);
        $this->assertEquals($outletUuid, $invitation->outlet_uuid);
        $this->assertEquals($admin->id, $invitation->invited_by);
        $this->assertNotNull($invitation->token_hash);
    }
}

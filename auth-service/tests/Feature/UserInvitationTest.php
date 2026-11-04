<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_access_to_invitations_is_blocked(): void
    {
        $this->getJson('/api/v1/invitations')->assertStatus(401);
        $this->postJson('/api/v1/invitations', [])->assertStatus(401);
    }

    public function test_admin_can_list_and_filter_invitations(): void
    {
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin']);
        $tenantId = $admin->tenant_id;

        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Cashier',
            'slug' => 'cashier-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UserInvitation::create([
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'email' => 'staff1@example.com',
            'token_hash' => hash('sha256', Str::random(40)),
            'invited_by' => $admin->id,
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($admin, ['*']);

        $res = $this->getJson('/api/v1/invitations');
        $res->assertStatus(200);
        $res->assertJsonPath('status', 'success');
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.email', 'staff1@example.com');
        $res->assertJsonPath('data.0.status', 'pending');
    }

    public function test_admin_can_create_invitation_with_tenant_role_and_outlet(): void
    {
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin']);
        $tenantId = $admin->tenant_id;

        $outletUuid = (string) Str::uuid();
        DB::table('outlets')->insert([
            'uuid' => $outletUuid,
            'tenant_id' => $tenantId,
            'name' => 'Toul Kork Outlet',
            'code' => 'TK-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Barista',
            'slug' => 'barista-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/invitations', [
            'email' => 'new.barista@example.com',
            'role_id' => $roleId,
            'outlet_uuid' => $outletUuid,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.email', 'new.barista@example.com');
        $response->assertJsonPath('data.role_id', $roleId);
        $response->assertJsonPath('data.outlet_uuid', $outletUuid);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.token_hash'));

        // Database verification
        $this->assertDatabaseHas('user_invitations', [
            'tenant_id' => $tenantId,
            'email' => 'new.barista@example.com',
            'role_id' => $roleId,
            'outlet_uuid' => $outletUuid,
            'invited_by' => $admin->id,
        ]);
    }

    public function test_invitation_creation_rejects_foreign_role_and_foreign_outlet(): void
    {
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin']);
        $tenantId = $admin->tenant_id;

        $otherTenantId = DB::table('tenants')->insertGetId([
            'name' => 'Other Tenant',
            'slug' => 'other-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherRole = DB::table('roles')->insertGetId([
            'tenant_id' => $otherTenantId,
            'name' => 'Foreign Role',
            'slug' => 'foreign-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherOutlet = (string) Str::uuid();
        DB::table('outlets')->insert([
            'uuid' => $otherOutlet,
            'tenant_id' => $otherTenantId,
            'name' => 'Foreign Outlet',
            'code' => 'FO-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Foreign role rejected
        $this->postJson('/api/v1/invitations', [
            'email' => 'test@example.com',
            'role_id' => $otherRole,
        ])->assertStatus(422);

        // Foreign outlet rejected
        $this->postJson('/api/v1/invitations', [
            'email' => 'test@example.com',
            'role' => 'employee',
            'outlet_uuid' => $otherOutlet,
        ])->assertStatus(422);
    }

    public function test_admin_can_show_resend_and_destroy_invitation(): void
    {
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin']);
        $tenantId = $admin->tenant_id;

        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Cashier',
            'slug' => 'cashier-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invitation = UserInvitation::create([
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'email' => 'renew.staff@example.com',
            'token_hash' => hash('sha256', Str::random(40)),
            'invited_by' => $admin->id,
            'expires_at' => now()->addDays(1),
        ]);

        Sanctum::actingAs($admin, ['*']);

        // 1. Show
        $showRes = $this->getJson("/api/v1/invitations/{$invitation->id}");
        $showRes->assertStatus(200);
        $showRes->assertJsonPath('data.email', 'renew.staff@example.com');

        // 2. Resend
        $resendRes = $this->postJson("/api/v1/invitations/{$invitation->id}/resend");
        $resendRes->assertStatus(200);
        $resendRes->assertJsonPath('status', 'success');
        $this->assertNotEquals($invitation->token_hash, $resendRes->json('data.token_hash'));

        // 3. Destroy
        $deleteRes = $this->deleteJson("/api/v1/invitations/{$invitation->id}");
        $deleteRes->assertStatus(200);
        $this->assertDatabaseMissing('user_invitations', ['id' => $invitation->id]);
    }
}


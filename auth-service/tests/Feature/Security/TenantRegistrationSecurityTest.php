<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantRegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createVictimWorkspace(): array
    {
        $cashierRole = Role::firstOrCreate(
            ['slug' => 'cashier'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Cashier', 'is_system' => true]
        );
        $adminRole = Role::firstOrCreate(
            ['slug' => 'admin'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Admin', 'is_system' => true]
        );

        $victimTenant = Tenant::create([
            'name' => 'Victim Enterprise',
            'slug' => 'victim-enterprise-' . Str::random(4),
            'status' => 'active',
        ]);

        $victim = User::factory()->inWorkspace()->create([
            'name' => 'Victim Owner',
            'email' => 'victim.owner@example.com',
            'tenant_id' => $victimTenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $victimTenant->update(['owner_user_id' => $victim->id]);

        return [$victimTenant, $victim, $adminRole, $cashierRole];
    }

    public function test_unauthenticated_tenant_registration_with_valid_app_key_and_existing_email_returns_401_and_mutates_nothing(): void
    {
        [$victimTenant, $victim] = $this->createVictimWorkspace();

        $beforeTenants = DB::table('tenants')->count();
        $beforeMemberships = DB::table('tenant_users')->count();
        $beforeRoles = DB::table('user_roles')->where('user_id', $victim->id)->get()->toArray();
        $beforeOutlets = DB::table('outlets')->count();
        $beforeTokens = DB::table('personal_access_tokens')->count();

        // Valid X-App-Key is attached by TestCase::setUp(), no Authorization / session token
        $response = $this->postJson('/api/v1/tenants/register', [
            'name' => 'Hijacked Workspace',
            'email' => $victim->email,
            'client_tier' => 'business_runner',
        ]);

        $response->assertStatus(401)
            ->assertJsonMissingPath('access_token')
            ->assertJsonMissingPath('refresh_token')
            ->assertJsonMissingPath('token');

        $this->assertSame($beforeTenants, DB::table('tenants')->count());
        $this->assertSame($beforeMemberships, DB::table('tenant_users')->count());
        $this->assertSame($beforeOutlets, DB::table('outlets')->count());
        $this->assertSame($beforeTokens, DB::table('personal_access_tokens')->count());
        $this->assertEquals(
            $beforeRoles,
            DB::table('user_roles')->where('user_id', $victim->id)->get()->toArray()
        );
    }

    public function test_pos_scoped_session_cannot_register_a_new_tenant(): void
    {
        $user = User::factory()->create([
            'email' => 'cashier.pos@example.com',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['pos:access']);

        $beforeTenants = DB::table('tenants')->count();

        $response = $this->postJson('/api/v1/tenants/register', [
            'name' => 'POS Terminal Tenant',
            'email' => 'pos@example.com',
        ]);

        $response->assertStatus(403);
        $this->assertSame($beforeTenants, DB::table('tenants')->count());
    }

    public function test_authenticated_user_cannot_provision_tenant_on_behalf_of_another_user(): void
    {
        [$victimTenant, $victim] = $this->createVictimWorkspace();

        $attacker = User::factory()->create([
            'email' => 'attacker@example.com',
            'is_active' => true,
        ]);

        Sanctum::actingAs($attacker, ['*']);

        $beforeTenants = DB::table('tenants')->count();

        $payloads = [
            ['name' => 'Spoof Owner Id', 'owner_user_id' => $victim->id],
            ['name' => 'Spoof Owner Alias', 'owner_id' => $victim->id],
            ['name' => 'Spoof User Id', 'user_id' => $victim->id],
            ['name' => 'Spoof Owner Email', 'owner_email' => $victim->email],
        ];

        foreach ($payloads as $payload) {
            $response = $this->postJson('/api/v1/tenants/register', $payload);
            $response->assertStatus(403)
                ->assertJsonMissingPath('access_token')
                ->assertJsonMissingPath('refresh_token');
        }

        $this->assertSame($beforeTenants, DB::table('tenants')->count());
    }

    public function test_authenticated_user_submitting_existing_users_email_owns_new_tenant_without_affecting_other_user(): void
    {
        [$victimTenant, $victim] = $this->createVictimWorkspace();

        $attacker = User::factory()->create([
            'name' => 'Legit Provisioner',
            'email' => 'provisioner@example.com',
            'is_active' => true,
        ]);

        $victimMembershipsBefore = DB::table('tenant_users')
            ->where('user_id', $victim->id)
            ->orderBy('id')
            ->get()
            ->toArray();
        $victimRolesBefore = DB::table('user_roles')
            ->where('user_id', $victim->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        Sanctum::actingAs($attacker, ['*']);

        $response = $this->postJson('/api/v1/tenants/register', [
            'name' => 'Provisioner Cafe',
            'email' => $victim->email, // Treated strictly as contact email
            'client_tier' => 'business_runner',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.id', $attacker->id);

        $newTenantId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $newTenantId);
        $this->assertDatabaseHas('tenants', [
            'id' => $newTenantId,
            'owner_user_id' => $attacker->id,
            'email' => $victim->email,
        ]);

        $claims = app(JwtService::class)->verify((string) $response->json('access_token'));
        $this->assertNotNull($claims);
        $this->assertSame((string) $attacker->id, (string) $claims['sub']);
        $this->assertSame($newTenantId, (int) $claims['tenant_id']);

        $this->assertEquals(
            $victimMembershipsBefore,
            DB::table('tenant_users')->where('user_id', $victim->id)->orderBy('id')->get()->toArray()
        );
        $this->assertEquals(
            $victimRolesBefore,
            DB::table('user_roles')->where('user_id', $victim->id)->orderBy('id')->get()->toArray()
        );
    }

    public function test_tenant_registration_preserves_existing_roles_in_other_tenants(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Existing Coffee A',
            'slug' => 'existing-coffee-a-' . Str::random(4),
            'status' => 'active',
        ]);

        $cashierRole = Role::firstOrCreate(
            ['slug' => 'cashier'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Cashier', 'is_system' => true]
        );

        $multiTenantUser = User::factory()->inWorkspace()->create([
            'name' => 'Multi Workspace User',
            'email' => 'multi.workspace@example.com',
            'tenant_id' => $tenantA->id,
            'role' => 'cashier',
            'is_active' => true,
        ]);

        Sanctum::actingAs($multiTenantUser, ['*']);

        $response = $this->postJson('/api/v1/tenants/register', [
            'name' => 'Second Coffee B',
            'client_tier' => 'business_runner',
        ]);

        $response->assertStatus(201);
        $tenantBId = (int) $response->json('data.id');
        $this->assertNotEquals($tenantA->id, $tenantBId);

        // Role in Tenant A must remain active and intact
        $this->assertDatabaseHas('user_roles', [
            'tenant_id' => $tenantA->id,
            'user_id' => $multiTenantUser->id,
            'role_id' => $cashierRole->id,
            'is_active' => true,
        ]);

        // Membership in Tenant A remains active
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenantA->id,
            'user_id' => $multiTenantUser->id,
            'status' => 'active',
        ]);

        // New organization role & membership exist in Tenant B
        $orgRoleId = Role::where('slug', 'organization')->value('id');
        $this->assertDatabaseHas('user_roles', [
            'tenant_id' => $tenantBId,
            'user_id' => $multiTenantUser->id,
            'role_id' => $orgRoleId,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenantBId,
            'user_id' => $multiTenantUser->id,
            'status' => 'active',
        ]);
    }

    public function test_tenant_registration_rolls_back_all_rows_on_failure(): void
    {
        $user = User::factory()->create([
            'email' => 'rollback.owner@example.com',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $beforeTenants = DB::table('tenants')->count();
        $beforeSubs = DB::table('tenant_subscriptions')->count();
        $beforeOutlets = DB::table('outlets')->count();
        $beforeMemberships = DB::table('tenant_users')->count();
        $beforeUserRoles = DB::table('user_roles')->count();

        // Force a failure midway through provisioning (after tenants, subscriptions, outlets insert)
        DB::unprepared("
            CREATE TRIGGER fail_register_mid_tx
            AFTER INSERT ON outlets
            BEGIN
                SELECT RAISE(ABORT, 'simulated provisioning failure');
            END;
        ");

        try {
            $response = $this->postJson('/api/v1/tenants/register', [
                'name' => 'Rollback Workspace',
                'client_tier' => 'business_runner',
            ]);

            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame($beforeTenants, DB::table('tenants')->count());
            $this->assertSame($beforeSubs, DB::table('tenant_subscriptions')->count());
            $this->assertSame($beforeOutlets, DB::table('outlets')->count());
            $this->assertSame($beforeMemberships, DB::table('tenant_users')->count());
            $this->assertSame($beforeUserRoles, DB::table('user_roles')->count());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_register_mid_tx;');
        }
    }

    public function test_pos_scoped_jwt_cannot_register_a_new_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Existing POS Tenant',
            'slug' => 'existing-pos-tenant-' . Str::random(4),
            'status' => 'active',
        ]);

        $user = User::factory()->inWorkspace()->create([
            'email' => 'pos.jwt@example.com',
            'tenant_id' => $tenant->id,
            'role' => 'cashier',
            'is_active' => true,
        ]);

        $posJwt = app(JwtService::class)->generateTokenForUser($user, [
            'is_pos_session' => true,
            'scope' => 'pos',
        ]);

        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');

        $beforeTenants = DB::table('tenants')->count();

        $response = $this->withHeader('Authorization', 'Bearer ' . $posJwt)
            ->postJson('/api/v1/tenants/register', [
                'name' => 'Unauthorized POS Tenant',
                'email' => 'pos2@example.com',
            ]);

        $response->assertStatus(403)
            ->assertJsonMissingPath('access_token')
            ->assertJsonMissingPath('refresh_token');
        $this->assertSame($beforeTenants, DB::table('tenants')->count());
    }

    public function test_deactivated_and_locked_accounts_cannot_register_a_new_tenant(): void
    {
        $deactivated = User::factory()->create([
            'email' => 'deactivated.reg@example.com',
            'is_active' => false,
        ]);
        $activeThenLocked = User::factory()->create([
            'email' => 'locked.reg@example.com',
            'is_active' => true,
        ]);

        $beforeTenants = DB::table('tenants')->count();

        Sanctum::actingAs($deactivated, ['*']);
        $this->postJson('/api/v1/tenants/register', [
            'name' => 'Deactivated Workspace',
        ])->assertStatus(401)->assertJsonMissingPath('access_token');

        Cache::put('auth:login_lockout:' . $activeThenLocked->id, true, 600);
        try {
            Sanctum::actingAs($activeThenLocked, ['*']);
            $this->postJson('/api/v1/tenants/register', [
                'name' => 'Locked Workspace',
            ])->assertStatus(401)->assertJsonMissingPath('access_token');
        } finally {
            Cache::forget('auth:login_lockout:' . $activeThenLocked->id);
        }

        $this->assertSame($beforeTenants, DB::table('tenants')->count());
    }
}

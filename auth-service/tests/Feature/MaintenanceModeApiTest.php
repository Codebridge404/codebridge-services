<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use App\Services\MaintenanceModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaintenanceModeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_get_and_update_maintenance_schedule(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        // Default disabled state
        $this->getJson('/api/v1/super-admin/maintenance')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.status', 'inactive');

        // Schedule future maintenance window in Asia/Phnom_Penh (+07:00)
        $startsAt = now('Asia/Phnom_Penh')->addHours(2)->toIso8601String();
        $endsAt = now('Asia/Phnom_Penh')->addHours(3)->toIso8601String();

        $this->putJson('/api/v1/super-admin/maintenance', [
            'enabled' => true,
            'message' => 'Scheduled maintenance tonight from 11:00 PM to 11:30 PM.',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'block_mutations' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.block_mutations', true)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.message', 'Scheduled maintenance tonight from 11:00 PM to 11:30 PM.');

        // Public status endpoint exposes banner without admin privileges
        $this->getJson('/api/v1/maintenance/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.status', 'scheduled');

        // Cached in Redis/Cache
        $this->assertNotNull(Cache::get(MaintenanceModeService::CACHE_KEY));
    }

    public function test_non_super_admin_cannot_access_super_admin_maintenance_endpoints(): void
    {
        $tenant = Tenant::create(['name' => 'Cafe A', 'slug' => 'cafe-a-maint']);
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/super-admin/maintenance')->assertForbidden();
        $this->putJson('/api/v1/super-admin/maintenance', [
            'enabled' => true,
            'block_mutations' => true,
        ])->assertForbidden();
    }

    public function test_active_maintenance_blocks_non_super_admin_mutations_with_503_but_allows_reads_and_super_admin(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $tenant = Tenant::create(['name' => 'Cafe B', 'slug' => 'cafe-b-maint']);
        $tenantAdmin = User::factory()->inWorkspace()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);

        // Activate maintenance window covering current time
        Sanctum::actingAs($superAdmin, ['*']);
        $this->putJson('/api/v1/super-admin/maintenance', [
            'enabled' => true,
            'message' => 'Database upgrade in progress.',
            'starts_at' => now('Asia/Phnom_Penh')->subMinutes(5)->toIso8601String(),
            'ends_at' => now('Asia/Phnom_Penh')->addMinutes(25)->toIso8601String(),
            'block_mutations' => true,
        ])->assertOk()->assertJsonPath('data.status', 'active');

        // Normal admin: GET is allowed
        Sanctum::actingAs($tenantAdmin, ['*']);
        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/maintenance/status')->assertOk()->assertJsonPath('data.status', 'active');

        // Normal admin: POST/PUT/DELETE mutations are blocked with 503
        $this->postJson('/api/v1/users', [
            'name' => 'Blocked Staff',
            'email' => 'blocked.staff@example.com',
            'password' => 'Password123!',
            'role' => 'cashier',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'MAINTENANCE_MODE')
            ->assertJsonStructure(['message', 'maintenance' => ['message', 'starts_at', 'ends_at']]);

        // Super admin: mutations remain allowed
        Sanctum::actingAs($superAdmin, ['*']);
        $this->postJson('/api/v1/api-clients', [
            'name' => 'Maintenance Allowed Client',
            'client_type' => 'web',
        ])->assertStatus(201);
    }

    public function test_scheduled_or_non_blocking_maintenance_does_not_block_mutations(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $tenant = Tenant::create(['name' => 'Cafe C', 'slug' => 'cafe-c-maint']);
        $tenantAdmin = User::factory()->inWorkspace()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);

        // Enabled + active window, but block_mutations = false (banner only)
        Sanctum::actingAs($superAdmin, ['*']);
        $this->putJson('/api/v1/super-admin/maintenance', [
            'enabled' => true,
            'message' => 'Banner only',
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
            'block_mutations' => false,
        ])->assertOk();

        Sanctum::actingAs($tenantAdmin, ['*']);
        $this->postJson('/api/v1/api-clients', [
            'name' => 'Allowed During Banner',
            'client_type' => 'web',
        ])->assertStatus(201);
    }

    public function test_validates_ends_at_must_be_after_starts_at_and_falls_back_to_db_when_cache_cleared(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        $this->putJson('/api/v1/super-admin/maintenance', [
            'enabled' => true,
            'starts_at' => '2026-11-25T23:30:00+07:00',
            'ends_at' => '2026-11-25T23:00:00+07:00',
            'block_mutations' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);

        // Save valid config, flush cache, and verify DB fallback still loads it
        $this->putJson('/api/v1/super-admin/maintenance', [
            'enabled' => true,
            'message' => 'Persisted in DB',
            'block_mutations' => true,
        ])->assertOk();

        Cache::forget(MaintenanceModeService::CACHE_KEY);

        $this->getJson('/api/v1/maintenance/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.message', 'Persisted in DB');
    }
}

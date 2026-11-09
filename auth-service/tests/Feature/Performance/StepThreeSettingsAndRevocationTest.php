<?php

namespace Tests\Feature\Performance;

use App\Models\ApiClient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSession;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepThreeSettingsAndRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_session_revoke_propagates_revoked_sid_and_blocks_jwt_immediately(): void
    {
        $tenant = Tenant::create([
            'name' => 'Revocation Org',
            'slug' => 'revocation-org-' . Str::random(5),
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $client = ApiClient::firstOrFail();
        $sessionUuid = (string) Str::uuid();

        $session = UserSession::create([
            'uuid' => $sessionUuid,
            'user_id' => $user->id,
            'api_client_id' => $client->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Step3Test/1.0',
            'expires_at' => Carbon::now()->addHour(),
        ]);

        /** @var JwtService $jwtService */
        $jwtService = app(JwtService::class);
        $token = $jwtService->generateTokenForUser($user, ['sid' => $sessionUuid]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/me')
            ->assertOk();

        $session->revoke('remote_revocation');

        $this->assertTrue($jwtService->isSessionUuidRevoked($sessionUuid));
        $this->assertNotNull(Cache::get("revoked_sid:{$sessionUuid}"));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_REVOKED');
    }

    public function test_outlet_settings_caching_enforces_tenant_isolation_and_invalidates_on_update(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Tenant Alpha',
            'slug' => 'tenant-alpha-' . Str::random(5),
            'status' => 'active',
        ]);
        $tenantB = Tenant::create([
            'name' => 'Tenant Beta',
            'slug' => 'tenant-beta-' . Str::random(5),
            'status' => 'active',
        ]);

        $outletAId = DB::table('outlets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'name' => 'Alpha Flagship',
            'code' => 'ALP-01',
            'business_type' => 'retail_mart',
            'is_active' => true,
            'is_kds_enabled' => false,
            'is_cfd_enabled' => false,
            'terminal_settings' => json_encode(['receiptCopies' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outletBId = DB::table('outlets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'name' => 'Beta Branch',
            'code' => 'BET-01',
            'business_type' => 'retail_mart',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminA = User::factory()->create([
            'role' => 'admin',
            'tenant_id' => $tenantA->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($adminA, ['*']);

        // Warm caches for Tenant A's outlet
        $profileWarm = $this->getJson("/api/v1/outlets/{$outletAId}/business-profile")
            ->assertOk()
            ->assertJsonPath('data.business_type', 'retail_mart');

        $terminalWarm = $this->getJson("/api/v1/outlets/{$outletAId}/terminal-settings")
            ->assertOk()
            ->assertJsonPath('data.terminal_settings.receiptCopies', 1);

        // Second requests should serve from cache with zero outlets SELECT queries
        DB::flushQueryLog();
        DB::enableQueryLog();
        $profileCached = $this->getJson("/api/v1/outlets/{$outletAId}/business-profile")->assertOk();
        $terminalCached = $this->getJson("/api/v1/outlets/{$outletAId}/terminal-settings")->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($profileWarm->json('data'), $profileCached->json('data'));
        $this->assertSame($terminalWarm->json('data'), $terminalCached->json('data'));
        $outletSelects = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'from "outlets"') || str_contains(strtolower($q['query']), 'from `outlets`'));
        $this->assertCount(0, $outletSelects);

        // Cross-tenant lookup returns 404 and never poisons cache
        $this->getJson("/api/v1/outlets/{$outletBId}/business-profile")->assertStatus(404);

        // Update business profile invalidates cached outlet responses immediately
        $this->patchJson("/api/v1/outlets/{$outletAId}/business-profile", [
            'business_type' => 'cafe_qsr',
        ])->assertOk();

        $this->getJson("/api/v1/outlets/{$outletAId}/business-profile")
            ->assertOk()
            ->assertJsonPath('data.business_type', 'cafe_qsr');

        $this->patchJson("/api/v1/outlets/{$outletAId}/terminal-settings", [
            'terminal_settings' => ['receiptCopies' => 2],
        ])->assertOk();

        $this->getJson("/api/v1/outlets/{$outletAId}/terminal-settings")
            ->assertOk()
            ->assertJsonPath('data.terminal_settings.receiptCopies', 2);
    }

    public function test_meeting_settings_caching_seeds_once_and_invalidates_on_batch_update_and_reset(): void
    {
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Warm call seeds defaults and populates cache
        $first = $this->getJson('/api/v1/meetings/system-settings')->assertOk();
        $this->assertNotEmpty($first->json('data'));

        // Second call serves from cache with zero SELECTs against meeting_system_settings
        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = $this->getJson('/api/v1/meetings/system-settings')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($first->json('data'), $second->json('data'));
        $settingSelects = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'meeting_system_settings'));
        $this->assertCount(0, $settingSelects);

        // Batch update immediately invalidates cache
        $this->putJson('/api/v1/meetings/system-settings/batch', [
            'settings' => [
                ['setting_key' => 'booking.max_advance_days', 'setting_value' => '90'],
            ],
        ])->assertOk();

        $updated = $this->getJson('/api/v1/meetings/system-settings')->assertOk();
        $byKey = collect($updated->json('data'))->keyBy('setting_key');
        $this->assertSame('90', (string) ($byKey->get('booking.max_advance_days')['setting_value'] ?? ''));

        // Reset invalidates cache and restores defaults
        $reset = $this->postJson('/api/v1/meetings/system-settings/reset')->assertOk();
        $resetByKey = collect($reset->json('data'))->keyBy('setting_key');
        $this->assertSame('60', (string) ($resetByKey->get('booking.max_advance_days')['setting_value'] ?? ''));

        $afterResetGet = $this->getJson('/api/v1/meetings/system-settings')->assertOk();
        $afterByKey = collect($afterResetGet->json('data'))->keyBy('setting_key');
        $this->assertSame('60', (string) ($afterByKey->get('booking.max_advance_days')['setting_value'] ?? ''));
    }
}

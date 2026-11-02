<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiKeysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test API keys endpoint requires authentication.
     */
    public function test_api_keys_requires_authentication(): void
    {
        $this->getJson('/api/v1/api-keys')->assertStatus(401);
    }

    /**
     * Test authenticated user can access API keys.
     */
    public function test_authenticated_user_can_view_api_keys(): void
    {
        $user = User::factory()->inWorkspace()->create(['role' => 'admin']);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/api-keys')->assertStatus(200);
    }

    /**
     * Test merchant API key creation, hashing, and revocation adhering to ERD schema.
     */
    public function test_merchant_api_key_creation_and_revocation_conforming_to_erd(): void
    {
        $tenantId = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId([
            'name' => 'API Key Org',
            'slug' => 'apikey-org',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenantId,
        ]);

        Sanctum::actingAs($user, ['*']);

        $res = $this->postJson('/api/v1/api-keys', [
            'name' => 'POS Terminal Sync Key',
            'permissions' => ['sales.read', 'catalog.read'],
            'expires_in_days' => 90,
        ])->assertStatus(201);

        $keyId = $res->json('data.id');
        $this->assertNotEmpty($res->json('plain_text_key'));
        $this->assertDatabaseHas('api_keys', [
            'id' => $keyId,
            'name' => 'POS Terminal Sync Key',
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'is_active' => 1,
        ]);

        // Revoke
        $this->deleteJson("/api/v1/api-keys/{$keyId}")->assertStatus(200);
        $this->assertDatabaseMissing('api_keys', ['id' => $keyId]);
    }
}

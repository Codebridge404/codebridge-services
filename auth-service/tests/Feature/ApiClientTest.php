<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_clients_requires_authentication(): void
    {
        $this->getJson('/api/v1/api-clients')->assertStatus(401);
    }

    public function test_authenticated_admin_can_list_api_clients(): void
    {
        $user = User::factory()->inWorkspace()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        ApiClient::create([
            'name' => 'Web Dashboard',
            'client_key' => 'client_web_123',
            'client_type' => 'web',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/api-clients')->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Web Dashboard']);
    }

    public function test_admin_can_create_api_client(): void
    {
        $user = User::factory()->inWorkspace()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/api-clients', [
            'name' => 'Flutter Kiosk Terminal',
            'client_type' => 'pos',
            'client_key' => 'kiosk-pin-terminal',
        ])->assertStatus(201);

        $this->assertDatabaseHas('api_clients', [
            'name' => 'Flutter Kiosk Terminal',
            'client_key' => 'kiosk-pin-terminal',
            'client_type' => 'pos',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_api_client_with_auto_generated_key(): void
    {
        $user = User::factory()->inWorkspace()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/api-clients', [
            'name' => 'Auto Key POS Kiosk',
            'client_type' => 'pos',
        ])->assertStatus(201);

        $clientKey = $response->json('data.client_key');
        $this->assertNotEmpty($clientKey);
        $this->assertStringStartsWith('ck_pos_', $clientKey);

        $this->assertDatabaseHas('api_clients', [
            'name' => 'Auto Key POS Kiosk',
            'client_key' => $clientKey,
            'client_type' => 'pos',
        ]);
    }

    public function test_model_auto_creates_client_key_on_eloquent_create(): void
    {
        $client = ApiClient::create([
            'name' => 'Direct Eloquent Model Client',
            'client_type' => 'mobile',
        ]);

        $this->assertNotEmpty($client->client_key);
        $this->assertStringStartsWith('ck_mobile_', $client->client_key);
    }

    public function test_admin_can_regenerate_client_key(): void
    {
        $user = User::factory()->inWorkspace()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $client = ApiClient::create([
            'name' => 'Regenerate Target App',
            'client_type' => 'web',
            'client_key' => 'old_key_123',
        ]);

        $response = $this->postJson("/api/v1/api-clients/{$client->id}/regenerate-key")
            ->assertStatus(200);

        $newKey = $response->json('data.client_key');
        $this->assertNotEquals('old_key_123', $newKey);
        $this->assertStringStartsWith('ck_web_', $newKey);

        $this->assertDatabaseHas('api_clients', [
            'id' => $client->id,
            'client_key' => $newKey,
        ]);
    }

    public function test_admin_can_view_update_toggle_and_delete_api_client(): void
    {
        $user = User::factory()->inWorkspace()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $client = ApiClient::create([
            'name' => 'Mobile Courier',
            'client_key' => 'client_mobile_999',
            'client_type' => 'mobile',
            'is_active' => true,
        ]);

        // Show
        $this->getJson("/api/v1/api-clients/{$client->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Mobile Courier');

        // Update
        $this->putJson("/api/v1/api-clients/{$client->id}", [
            'name' => 'Mobile Courier Runner',
        ])->assertStatus(200);

        $this->assertDatabaseHas('api_clients', [
            'id' => $client->id,
            'name' => 'Mobile Courier Runner',
        ]);

        // Toggle
        $this->putJson("/api/v1/api-clients/{$client->id}/toggle")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('api_clients', [
            'id' => $client->id,
            'is_active' => false,
        ]);

        // Delete
        $this->deleteJson("/api/v1/api-clients/{$client->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('api_clients', [
            'id' => $client->id,
        ]);
    }

    public function test_validation_errors_on_invalid_store_payload(): void
    {
        $user = User::factory()->inWorkspace()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        // Missing required fields
        $this->postJson('/api/v1/api-clients', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'client_type']);

        // Invalid client_type
        $this->postJson('/api/v1/api-clients', [
            'name' => 'Invalid Type Client',
            'client_type' => 'quantum_computer',
        ])->assertStatus(422)->assertJsonValidationErrors(['client_type']);

        // Non-unique client_key
        ApiClient::create([
            'name' => 'Existing Unique Client',
            'client_type' => 'pos',
            'client_key' => 'unique_key_conflict_123',
        ]);

        $this->postJson('/api/v1/api-clients', [
            'name' => 'Duplicate Key Client',
            'client_type' => 'web',
            'client_key' => 'unique_key_conflict_123',
        ])->assertStatus(422)->assertJsonValidationErrors(['client_key']);
    }

    public function test_cannot_delete_api_client_with_associated_sessions(): void
    {
        $user = User::factory()->inWorkspace()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $client = ApiClient::create([
            'name' => 'Active Session Client',
            'client_type' => 'pos',
            'client_key' => 'active_session_client_key',
        ]);

        // Attach a user session
        \App\Models\UserSession::create([
            'user_id' => $user->id,
            'api_client_id' => $client->id,
            'tenant_id' => $user->tenant_id,
            'permissions' => ['*'],
            'expires_at' => now()->addDays(7),
        ]);

        $this->deleteJson("/api/v1/api-clients/{$client->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['status' => 'error'])
            ->assertJsonFragment(['code' => 'VALIDATION_ERROR']);
    }
}


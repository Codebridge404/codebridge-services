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
}

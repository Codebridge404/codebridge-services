<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureApiClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_without_x_app_key_returns_401_app_key_required(): void
    {
        $response = $this->withoutHeader('X-App-Key')
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@sunnycafe.kh',
                'password' => 'Password123!',
            ]);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 'APP_KEY_REQUIRED',
                'message' => 'A valid X-App-Key header is required.',
            ]);
    }

    public function test_request_with_empty_or_oversized_x_app_key_returns_401(): void
    {
        $response = $this->withHeader('X-App-Key', '')
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@sunnycafe.kh',
                'password' => 'Password123!',
            ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'APP_KEY_REQUIRED');

        $oversized = str_repeat('a', 105);
        $response2 = $this->withHeader('X-App-Key', $oversized)
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@sunnycafe.kh',
                'password' => 'Password123!',
            ]);

        $response2->assertStatus(401)
            ->assertJsonPath('code', 'APP_KEY_REQUIRED');
    }

    public function test_request_with_invalid_x_app_key_returns_401_invalid_app_key(): void
    {
        $response = $this->withHeader('X-App-Key', 'invalid_unregistered_key')
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@sunnycafe.kh',
                'password' => 'Password123!',
            ]);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 'INVALID_APP_KEY',
                'message' => 'Application key is invalid or disabled.',
            ]);
    }

    public function test_request_with_disabled_client_key_returns_401_invalid_app_key(): void
    {
        $client = ApiClient::create([
            'name' => 'Disabled Kiosk',
            'client_type' => 'pos',
            'client_key' => 'ck_disabled_kiosk_key',
            'is_active' => false,
        ]);

        $response = $this->withHeader('X-App-Key', $client->client_key)
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@sunnycafe.kh',
                'password' => 'Password123!',
            ]);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 'INVALID_APP_KEY',
                'message' => 'Application key is invalid or disabled.',
            ]);
    }

    public function test_request_with_valid_x_app_key_succeeds_and_injects_attributes(): void
    {
        $client = ApiClient::create([
            'name' => 'Valid POS Terminal',
            'client_type' => 'pos',
            'client_key' => 'ck_pos_valid_terminal_key',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'terminal.cashier@sunnycafe.kh',
            'password' => \Illuminate\Support\Facades\Hash::make('Password123!'),
        ]);

        $response = $this->withHeader('X-App-Key', $client->client_key)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type']);
    }

    public function test_request_with_alias_x_client_key_succeeds(): void
    {
        $client = ApiClient::create([
            'name' => 'Mobile Courier App',
            'client_type' => 'mobile',
            'client_key' => 'ck_mobile_alias_key',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'courier@sunnycafe.kh',
            'password' => \Illuminate\Support\Facades\Hash::make('Password123!'),
        ]);

        $response = $this->withoutHeader('X-App-Key')
            ->withHeader('X-Client-Key', $client->client_key)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type']);
    }

    public function test_cors_preflight_options_bypasses_x_app_key(): void
    {
        $response = $this->withoutHeader('X-App-Key')
            ->options('/api/v1/auth/login');

        // OPTIONS request should not return 401 APP_KEY_REQUIRED
        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_health_check_bypasses_x_app_key(): void
    {
        $response = $this->withoutHeader('X-App-Key')
            ->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('service', 'auth-service');
    }
}

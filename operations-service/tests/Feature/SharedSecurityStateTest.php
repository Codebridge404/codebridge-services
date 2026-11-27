<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckMaintenanceMode;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharedSecurityStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_suspended_tenant_in_shared_cache_is_immediately_rejected_with_403(): void
    {
        Cache::put('tenant_status:55', 'suspended', 300);

        $secret = (string) config('jwt.secret');
        $token = JWT::encode([
            'iss' => config('jwt.issuer', 'codebridge-auth-service'),
            'aud' => config('jwt.audience', 'codebridge-api'),
            'sub' => '701',
            'name' => 'Coffee Shop Cashier',
            'email' => 'cashier@coffeeshop.kh',
            'role' => 'accountant',
            'tenant_id' => '55',
            'token_use' => 'access',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $secret, 'HS256');

        $this->withToken($token)
            ->getJson('/api/v1/expenses')
            ->assertStatus(403)
            ->assertJsonPath('code', 'TENANT_SUSPENDED');
    }

    public function test_maintenance_mode_in_shared_cache_blocks_mutations_with_503_and_allows_reads_and_super_admin(): void
    {
        Cache::put(CheckMaintenanceMode::CACHE_KEY, [
            'enabled' => true,
            'block_mutations' => true,
            'message' => 'Scheduled maintenance',
            'starts_at' => null,
            'ends_at' => null,
        ], 300);

        $accountant = User::factory()->make([
            'role' => 'accountant',
            'tenant_id' => 55,
        ]);
        $accountant->id = 702;

        Sanctum::actingAs($accountant, ['*']);

        // Reads remain allowed
        $this->getJson('/api/v1/expenses')->assertOk();

        // Mutations are blocked with 503 MAINTENANCE_MODE
        $this->postJson('/api/v1/expenses', [])
            ->assertStatus(503)
            ->assertJsonPath('code', 'MAINTENANCE_MODE');

        // Super admin bypasses maintenance block (validation 422, not 503)
        $superAdmin = User::factory()->make([
            'role' => 'super_admin',
            'tenant_id' => 55,
        ]);
        $superAdmin->id = 703;
        Sanctum::actingAs($superAdmin, ['*']);

        $res = $this->postJson('/api/v1/expenses', []);
        $this->assertNotSame(503, $res->getStatusCode());
    }
}

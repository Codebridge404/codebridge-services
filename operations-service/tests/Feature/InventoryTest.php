<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test unauthenticated access to inventory queries returns 401.
     */
    public function test_unauthenticated_access_is_blocked(): void
    {
        $this->getJson('/api/v1/inventory/balances')->assertStatus(401);
        $this->getJson('/api/v1/inventory/movements')->assertStatus(401);
        $this->getJson('/api/v1/purchase-orders')->assertStatus(401);
    }

    /**
     * Test authenticated inventory manager can view inventory queries.
     */
    public function test_authenticated_user_can_view_inventory_balances_and_orders(): void
    {
        $user = User::factory()->create([
            'role' => 'inventory_clerk',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/inventory/balances')->assertStatus(200);
        $this->getJson('/api/v1/inventory/movements')->assertStatus(200);
        $this->getJson('/api/v1/inventory/expired')->assertStatus(200);
        $this->getJson('/api/v1/inventory/transfers')->assertStatus(200);
        $this->getJson('/api/v1/purchase-orders')->assertStatus(200);
    }
}

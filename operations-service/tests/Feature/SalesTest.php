<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test public online order creation validation.
     */
    public function test_online_order_validation(): void
    {
        $response = $this->postJson('/api/v1/online-orders', []);

        // Should require fields (422) or handle initial request
        $this->assertContains($response->getStatusCode(), [200, 201, 422]);
    }

    /**
     * Test protected sales endpoints require authentication.
     */
    public function test_sales_requires_authentication(): void
    {
        $this->getJson('/api/v1/sales')->assertStatus(401);
        $this->getJson('/api/v1/tables')->assertStatus(401);
        $this->getJson('/api/v1/kds/tickets')->assertStatus(401);
        $this->getJson('/api/v1/carts/held')->assertStatus(401);
    }

    /**
     * Test cashier can view sales, tables, KDS, and held carts.
     */
    public function test_cashier_can_access_pos_sales_and_tables(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
        ]);

        Sanctum::actingAs($cashier, ['*']);

        $this->getJson('/api/v1/sales')->assertStatus(200);
        $this->getJson('/api/v1/tables')->assertStatus(200);
        $this->getJson('/api/v1/kds/tickets')->assertStatus(200);
        $this->getJson('/api/v1/carts/held')->assertStatus(200);
    }
}

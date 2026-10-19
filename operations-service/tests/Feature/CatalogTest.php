<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test public catalog endpoints.
     */
    public function test_public_catalog_endpoints(): void
    {
        $this->getJson('/api/v1/products')->assertStatus(200);
        $this->getJson('/api/v1/categories')->assertStatus(200);
        $this->getJson('/api/v1/brands')->assertStatus(200);
        $this->getJson('/api/v1/discounts')->assertStatus(200);
    }

    /**
     * Test unauthenticated mutation is unauthorized.
     */
    public function test_unauthenticated_user_cannot_create_product(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => 'Premium Coffee',
            'price' => 3.50,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test authenticated admin can create a product.
     */
    public function test_admin_can_create_category(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/categories', [
            'name' => 'Beverages',
            'slug' => 'beverages',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 201]);
    }
}

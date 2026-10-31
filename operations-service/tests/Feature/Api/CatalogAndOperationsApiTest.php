<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogAndOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test products listing returns array of products.
     */
    public function test_products_index_endpoint(): void
    {
        Product::create([
            'name' => 'Iced Americano',
            'sku' => 'BEV-AME-001',
            'selling_price' => 2.50,
            'cost_price' => 0.80,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
            ])
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => ['id', 'name', 'price', 'sku'],
                ],
            ]);
    }

    /**
     * Test single product detail endpoint.
     */
    public function test_product_show_endpoint(): void
    {
        $product = Product::create([
            'name' => 'Hot Latte',
            'sku' => 'BEV-LAT-002',
            'selling_price' => 3.00,
            'cost_price' => 1.00,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $product->id,
                    'name' => 'Hot Latte',
                    'selling_price' => 3.00,
                ],
            ]);

        // Non-existent product returns 404
        $this->getJson('/api/v1/products/99999')->assertStatus(404);
    }

    /**
     * Test manager can create a category.
     */
    public function test_manager_can_create_category(): void
    {
        $manager = User::factory()->create([
            'role' => 'outlet_manager',
        ]);
        Sanctum::actingAs($manager, ['*']);

        $response = $this->postJson('/api/v1/categories', [
            'name' => 'Espresso Drinks',
            'slug' => 'espresso-drinks',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 201]);

        $this->assertDatabaseHas('categories', [
            'name' => 'Espresso Drinks',
        ]);
    }

    /**
     * Test categories listing returns list of categories.
     */
    public function test_categories_index_endpoint(): void
    {
        Category::create([
            'name' => 'Bakery',
            'slug' => 'bakery',
        ]);

        $response = $this->getJson('/api/v1/categories');

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
            ]);
    }
}

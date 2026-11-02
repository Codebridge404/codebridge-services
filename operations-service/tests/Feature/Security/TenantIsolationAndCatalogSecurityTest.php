<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationAndCatalogSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test a user of Tenant A cannot spoof X-Tenant-Id to act on Tenant B.
     */
    public function test_client_cannot_spoof_x_tenant_id(): void
    {
        $tenantAUser = User::factory()->create([
            'role' => 'admin',
        ]);
        $tenantAUser->tenant_id = 'tenant-A';
        Sanctum::actingAs($tenantAUser, ['*']);

        // Attempt to pass X-Tenant-Id: tenant-B
        $response = $this->withHeaders([
            'X-Tenant-Id' => 'tenant-B',
        ])->postJson('/api/v1/products', [
            'name' => 'Spoofed Product',
            'sku' => 'SPOOF-001',
            'selling_price' => 10.00,
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test Tenant A cannot update a product belonging to Tenant B even if ID is known.
     */
    public function test_tenant_a_cannot_update_product_of_tenant_b(): void
    {
        $productB = Product::create([
            'tenant_id' => 'tenant-B',
            'name' => 'Tenant B Secret Product',
            'sku' => 'TENANT-B-001',
            'selling_price' => 50.00,
            'cost_price' => 20.00,
            'is_active' => true,
        ]);

        $tenantAUser = User::factory()->create([
            'role' => 'admin',
        ]);
        $tenantAUser->tenant_id = 'tenant-A';
        Sanctum::actingAs($tenantAUser, ['*']);

        $response = $this->putJson("/api/v1/products/{$productB->id}", [
            'name' => 'Hacked Name',
            'selling_price' => 1.00,
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('products', [
            'id' => $productB->id,
            'name' => 'Tenant B Secret Product',
            'selling_price' => 50.00,
        ]);
    }

    /**
     * Test Tenant A cannot delete a product belonging to Tenant B.
     */
    public function test_tenant_a_cannot_delete_product_of_tenant_b(): void
    {
        $productB = Product::create([
            'tenant_id' => 'tenant-B',
            'name' => 'Tenant B Product',
            'sku' => 'TENANT-B-DEL',
            'selling_price' => 25.00,
            'is_active' => true,
        ]);

        $tenantAUser = User::factory()->create([
            'role' => 'admin',
        ]);
        $tenantAUser->tenant_id = 'tenant-A';
        Sanctum::actingAs($tenantAUser, ['*']);

        $response = $this->deleteJson("/api/v1/products/{$productB->id}");
        $response->assertStatus(403);

        $this->assertDatabaseHas('products', [
            'id' => $productB->id,
            'is_active' => true,
        ]);
    }

    /**
     * Test unauthenticated export is blocked with HTTP 401.
     */
    public function test_unauthenticated_export_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/products/export');
        $response->assertStatus(401);
    }

    /**
     * Test export strictly scopes to the authenticated user's tenant.
     */
    public function test_authenticated_export_is_scoped_to_tenant(): void
    {
        Product::create([
            'tenant_id' => 'tenant-100',
            'name' => 'Product for Tenant 100',
            'sku' => 'T100-PROD',
            'selling_price' => 15.00,
            'cost_price' => 5.00,
            'is_active' => true,
        ]);

        Product::create([
            'tenant_id' => 'tenant-200',
            'name' => 'Product for Tenant 200',
            'sku' => 'T200-PROD',
            'selling_price' => 30.00,
            'cost_price' => 10.00,
            'is_active' => true,
        ]);

        $user100 = User::factory()->create([
            'role' => 'admin',
        ]);
        $user100->tenant_id = 'tenant-100';
        Sanctum::actingAs($user100, ['*']);

        $response = $this->get('/api/v1/products/export');
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $this->assertStringContainsString('Product for Tenant 100', $content);
        $this->assertStringNotContainsString('Product for Tenant 200', $content);
    }

    /**
     * Test normal users cannot perform cross-tenant queries using tenant_id=all.
     */
    public function test_normal_user_cannot_pass_tenant_id_all(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        $user->tenant_id = 'tenant-1';
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/products?tenant_id=all');
        $response->assertStatus(403);
    }

    /**
     * Test search supports both ?q= and ?search= parameters.
     */
    public function test_search_supports_both_q_and_search_parameters(): void
    {
        Product::create([
            'name' => 'Special Cold Brew',
            'sku' => 'BREW-001',
            'selling_price' => 4.50,
            'is_active' => true,
        ]);

        // Using ?q=
        $respQ = $this->getJson('/api/v1/products?q=Cold+Brew');
        $respQ->assertOk();
        $this->assertCount(1, $respQ->json('data'));

        // Using ?search=
        $respSearch = $this->getJson('/api/v1/products?search=Cold+Brew');
        $respSearch->assertOk();
        $this->assertCount(1, $respSearch->json('data'));
    }

    /**
     * Test unauthenticated product views hide internal cost prices.
     */
    public function test_unauthenticated_product_views_hide_cost_price(): void
    {
        $product = Product::create([
            'name' => 'Public Croissant',
            'sku' => 'CRO-001',
            'selling_price' => 2.50,
            'cost_price' => 0.75,
            'is_active' => true,
        ]);

        // Unauthenticated index
        $indexResp = $this->getJson('/api/v1/products');
        $indexResp->assertOk();
        $firstItem = $indexResp->json('data.0');
        $this->assertArrayNotHasKey('cost_price', $firstItem);

        // Unauthenticated show
        $showResp = $this->getJson("/api/v1/products/{$product->id}");
        $showResp->assertOk();
        $this->assertArrayNotHasKey('cost_price', $showResp->json('data'));
    }
}

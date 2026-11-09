<?php

namespace Tests\Feature\Validation;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationsInputValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create([
            'role' => 'cashier',
        ]);
        Sanctum::actingAs($this->cashier, ['*']);
    }

    /**
     * Test opening shift requires non-negative numeric float.
     */
    public function test_opening_shift_validation(): void
    {
        // 1. Missing opening_float
        $resMissing = $this->postJson('/api/v1/shifts/open', []);
        $resMissing->assertStatus(422)
            ->assertJsonValidationErrors(['opening_float']);

        // 2. Negative opening_float
        $resNegative = $this->postJson('/api/v1/shifts/open', [
            'opening_float' => -50.00,
        ]);
        $resNegative->assertStatus(422)
            ->assertJsonValidationErrors(['opening_float']);

        // 3. String non-numeric float
        $resString = $this->postJson('/api/v1/shifts/open', [
            'opening_float' => 'not-a-number',
        ]);
        $resString->assertStatus(422)
            ->assertJsonValidationErrors(['opening_float']);
    }

    /**
     * Test cash drawer movement requires type in:in,out, positive amount, and reason.
     */
    public function test_cash_movement_validation(): void
    {
        // First open a valid shift so endpoint passes shift existence check
        $this->postJson('/api/v1/shifts/open', ['opening_float' => 100.00])->assertStatus(201);

        // 1. Invalid movement type
        $resType = $this->postJson('/api/v1/shifts/cash-movement', [
            'type' => 'steal',
            'amount' => 20.00,
            'reason' => 'Invalid action',
        ]);
        $resType->assertStatus(422)
            ->assertJsonValidationErrors(['type']);

        // 2. Negative amount
        $resAmount = $this->postJson('/api/v1/shifts/cash-movement', [
            'type' => 'in',
            'amount' => -10.00,
            'reason' => 'Adding change',
        ]);
        $resAmount->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        // 3. Missing reason
        $resReason = $this->postJson('/api/v1/shifts/cash-movement', [
            'type' => 'out',
            'amount' => 15.00,
        ]);
        $resReason->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    /**
     * Test closing shift requires counted cash.
     */
    public function test_closing_shift_validation(): void
    {
        $this->postJson('/api/v1/shifts/open', ['opening_float' => 100.00])->assertStatus(201);

        // Missing counted_cash
        $response = $this->postJson('/api/v1/shifts/close', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['counted_cash']);

        // Negative counted_cash
        $negResponse = $this->postJson('/api/v1/shifts/close', [
            'counted_cash' => -25.00,
        ]);
        $negResponse->assertStatus(422)
            ->assertJsonValidationErrors(['counted_cash']);
    }

    public function test_product_form_requests_reject_svg_uploads_and_enforce_cross_tenant_403(): void
    {
        $managerA = User::factory()->create(['role' => 'admin']);
        $managerA->tenant_id = '101';
        Sanctum::actingAs($managerA, ['*']);

        $svgFile = UploadedFile::fake()->create('xss.svg', 2, 'image/svg+xml');
        $this->post('/api/v1/products', [
            'name' => 'Test Drink',
            'sku' => 'SVG-001',
            'selling_price' => 3.50,
            'image' => $svgFile,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->postJson('/api/v1/products', [
            'name' => 'Test Drink 2',
            'sku' => 'SVG-002',
            'selling_price' => 3.50,
            'image_url' => 'data:image/svg+xml;base64,PHN2Zy8+',
        ])->assertStatus(422);

        $this->postJson('/api/v1/products', [
            'name' => 'Test Drink 3',
            'sku' => 'SVG-003',
            'selling_price' => 3.50,
            'image_url' => 'https://cdn.example.com/evil.svg?v=1',
        ])->assertStatus(422);

        $foreignProduct = Product::create([
            'tenant_id' => '202',
            'name' => 'Foreign Coffee',
            'sku' => 'FOR-001',
            'selling_price' => 5.00,
            'is_active' => true,
        ]);

        // Cross-tenant update returns 403 even before body validation completes
        $this->putJson("/api/v1/products/{$foreignProduct->id}", [
            'selling_price' => -99,
        ])->assertStatus(403);

        $this->postJson("/api/v1/products/{$foreignProduct->id}/image", [
            'image_url' => 'https://cdn.example.com/ok.png',
        ])->assertStatus(403);

        $foreignOutletId = DB::table('outlets')->insertGetId([
            'tenant_id' => '202',
            'name' => 'Tenant 202 Branch',
            'code' => 'B-202',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cross-tenant checkout outlet returns 403 inside StoreCheckoutRequest
        $this->postJson('/api/v1/sales', [
            'outlet_id' => $foreignOutletId,
            'tender_type' => 'cash',
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertStatus(403);
    }
}

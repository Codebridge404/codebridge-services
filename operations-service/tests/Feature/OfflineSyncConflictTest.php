<?php

namespace Tests\Feature;

use App\Models\OfflineSyncConflict;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfflineSyncConflictTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $manager;
    protected int $outletId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'outlet_id' => $this->outletId,
        ]);

        $this->manager = User::factory()->create([
            'role' => 'outlet_manager',
            'outlet_id' => $this->outletId,
        ]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        static $counter = 1;
        $counter++;
        return Product::create(array_merge([
            'tenant_id' => 1,
            'name' => 'Product ' . $counter,
            'sku' => 'SKU-' . uniqid() . '-' . $counter,
            'barcode' => 'BAR-' . uniqid() . '-' . $counter,
            'cost_price' => 10.00,
            'selling_price' => 20.00,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Test offline transaction sync endpoint requires authentication.
     */
    public function test_offline_sync_requires_authentication(): void
    {
        $this->postJson('/api/v1/offline-sync', [])->assertStatus(401);
        $this->getJson('/api/v1/offline-sync/conflicts')->assertStatus(401);
    }

    /**
     * Test normal offline batch sync with sufficient inventory.
     */
    public function test_offline_batch_sync_succeeds_with_sufficient_stock(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $product = $this->createProduct([
            'selling_price' => 20.00,
        ]);

        DB::table('inventory_balances')->insert([
            'tenant_id' => 1,
            'outlet_id' => $this->outletId,
            'product_id' => $product->id,
            'on_hand' => 10,
            'available' => 10,
            'reserved' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'register_id' => 1,
            'transactions' => [
                [
                    'offline_id' => 'OFF-001',
                    'receipt_number' => 'REC-OFF-001',
                    'register_id' => 1,
                    'created_at' => '2026-10-20 10:00:00',
                    'items' => [
                        [
                            'id' => $product->id,
                            'name' => $product->name,
                            'quantity' => 2,
                            'price' => 20.00,
                        ],
                    ],
                    'tenders' => [
                        [
                            'tender_type' => 'cash',
                            'amount' => 44.00,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/offline-sync', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'summary' => [
                    'total_received' => 1,
                    'synced_count' => 1,
                    'already_synced_count' => 0,
                    'conflict_count' => 0,
                ],
                'results' => [
                    [
                        'offline_id' => 'OFF-001',
                        'status' => 'synced',
                        'receipt_number' => 'REC-OFF-001',
                        'conflicts' => [],
                    ],
                ],
            ]);

        // Check stock decremented to 8
        $balance = DB::table('inventory_balances')
            ->where('outlet_id', $this->outletId)
            ->where('product_id', $product->id)
            ->first();

        $this->assertEquals(8, $balance->on_hand);
        $this->assertDatabaseHas('sales', ['offline_id' => 'OFF-001']);
        $this->assertDatabaseCount('offline_sync_conflicts', 0);
    }

    /**
     * Test idempotency: Resubmitting the same offline transaction does not duplicate sales or inventory deductions.
     */
    public function test_offline_sync_idempotency(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $product = $this->createProduct(['selling_price' => 10.00]);
        DB::table('inventory_balances')->insert([
            'tenant_id' => 1,
            'outlet_id' => $this->outletId,
            'product_id' => $product->id,
            'on_hand' => 10,
            'available' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'transactions' => [
                [
                    'offline_id' => 'IDEMPOTENT-001',
                    'receipt_number' => 'REC-IDEM-001',
                    'items' => [
                        ['id' => $product->id, 'name' => $product->name, 'quantity' => 3, 'price' => 10.00],
                    ],
                    'tenders' => [
                        ['tender_type' => 'cash', 'amount' => 33.00],
                    ],
                ],
            ],
        ];

        // First sync
        $this->postJson('/api/v1/offline-sync', $payload)->assertStatus(200);

        // Second sync (identical offline_id)
        $secondResponse = $this->postJson('/api/v1/offline-sync', $payload);

        $secondResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'summary' => [
                    'total_received' => 1,
                    'synced_count' => 0,
                    'already_synced_count' => 1,
                    'conflict_count' => 0,
                ],
                'results' => [
                    [
                        'offline_id' => 'IDEMPOTENT-001',
                        'status' => 'already_synced',
                    ],
                ],
            ]);

        // Stock was deducted only once (10 - 3 = 7)
        $balance = DB::table('inventory_balances')->where('product_id', $product->id)->first();
        $this->assertEquals(7, $balance->on_hand);
        $this->assertEquals(1, DB::table('sales')->where('offline_id', 'IDEMPOTENT-001')->count());
    }

    /**
     * Test stock collision between multiple offline registers:
     * - Register 1 sells 4 units offline.
     * - Register 2 sells 3 units offline concurrently.
     * - Initial stock is 5 units.
     * - Register 1 syncs -> remaining stock becomes 1.
     * - Register 2 syncs -> stock collision detected (shortage of 2 units).
     * - Sale is recorded, conflict is created in offline_sync_conflicts table, stock allows negative balance (-2).
     */
    public function test_stock_collision_between_multiple_offline_registers(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $product = $this->createProduct([
            'name' => 'Cold Brew Coffee',
            'selling_price' => 5.00,
        ]);

        // Outlet has exactly 5 units on hand
        DB::table('inventory_balances')->insert([
            'tenant_id' => 1,
            'outlet_id' => $this->outletId,
            'product_id' => $product->id,
            'on_hand' => 5,
            'available' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Batch containing offline transactions from Register 1 and Register 2
        $payload = [
            'transactions' => [
                // Register 1 sold 4 units at 10:00 AM
                [
                    'offline_id' => 'REG1-TX-001',
                    'receipt_number' => 'REC-REG1-001',
                    'register_id' => 1,
                    'created_at' => '2026-10-20 10:00:00',
                    'items' => [
                        [
                            'id' => $product->id,
                            'name' => 'Cold Brew Coffee',
                            'quantity' => 4,
                            'price' => 5.00,
                        ],
                    ],
                    'tenders' => [
                        ['tender_type' => 'cash', 'amount' => 22.00],
                    ],
                ],
                // Register 2 sold 3 units at 10:05 AM (total = 7 units > 5 stock)
                [
                    'offline_id' => 'REG2-TX-002',
                    'receipt_number' => 'REC-REG2-002',
                    'register_id' => 2,
                    'created_at' => '2026-10-20 10:05:00',
                    'items' => [
                        [
                            'id' => $product->id,
                            'name' => 'Cold Brew Coffee',
                            'quantity' => 3,
                            'price' => 5.00,
                        ],
                    ],
                    'tenders' => [
                        ['tender_type' => 'cash', 'amount' => 16.50],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/offline-sync', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'summary' => [
                    'total_received' => 2,
                    'synced_count' => 2,
                    'already_synced_count' => 0,
                    'conflict_count' => 1,
                ],
            ]);

        $results = $response->json('results');
        $this->assertEquals('synced', $results[0]['status']);
        $this->assertEquals('synced_with_conflict', $results[1]['status']);
        $this->assertNotEmpty($results[1]['conflicts']);

        $conflictInfo = $results[1]['conflicts'][0];
        $this->assertEquals($product->id, $conflictInfo['product_id']);
        $this->assertEquals(3, $conflictInfo['requested_quantity']);
        $this->assertEquals(1, $conflictInfo['available_quantity']);
        $this->assertEquals(2, $conflictInfo['shortage_quantity']);
        $this->assertEquals('stock_collision', $conflictInfo['conflict_type']);

        // Verify conflict was recorded in database
        $this->assertDatabaseHas('offline_sync_conflicts', [
            'offline_id' => 'REG2-TX-002',
            'register_id' => 2,
            'product_id' => $product->id,
            'requested_quantity' => 3,
            'available_quantity' => 1,
            'shortage_quantity' => 2,
            'status' => 'pending',
        ]);

        // Verify physical negative on-hand balance (5 - 4 - 3 = -2)
        $balance = DB::table('inventory_balances')
            ->where('outlet_id', $this->outletId)
            ->where('product_id', $product->id)
            ->first();
        $this->assertEquals(-2, $balance->on_hand);

        // Verify collision movement logged
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'movement_type' => 'offline_sale_collision',
        ]);
    }

    /**
     * Test supervisor/manager can list, inspect, and resolve conflicts.
     */
    public function test_manager_can_review_and_resolve_stock_collision(): void
    {
        Sanctum::actingAs($this->manager, ['*']);

        $product = $this->createProduct(['name' => 'Energy Drink']);

        DB::table('inventory_balances')->insert([
            'tenant_id' => 1,
            'outlet_id' => $this->outletId,
            'product_id' => $product->id,
            'on_hand' => -2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $conflict = OfflineSyncConflict::create([
            'tenant_id' => 1,
            'outlet_id' => $this->outletId,
            'register_id' => 2,
            'offline_id' => 'OFF-CONF-999',
            'receipt_number' => 'REC-CONF-999',
            'product_id' => $product->id,
            'product_name' => $product->name,
            'requested_quantity' => 3,
            'available_quantity' => 1,
            'shortage_quantity' => 2,
            'conflict_type' => 'stock_collision',
            'status' => 'pending',
            'offline_created_at' => now(),
        ]);

        // 1. List conflicts
        $listResponse = $this->getJson('/api/v1/offline-sync/conflicts?status=pending');
        $listResponse->assertStatus(200)
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('data.0.id', $conflict->id);

        // 2. View conflict detail
        $this->getJson("/api/v1/offline-sync/conflicts/{$conflict->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $conflict->id)
            ->assertJsonPath('data.product_name', 'Energy Drink');

        // 3. Resolve conflict with inventory adjustment (+5 units restocked)
        $resolveResponse = $this->patchJson("/api/v1/offline-sync/conflicts/{$conflict->id}/resolve", [
            'resolution_action' => 'inventory_adjustment',
            'notes' => 'Received emergency warehouse transfer to cover shortfall.',
            'adjust_quantity' => 5,
        ]);

        $resolveResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution_strategy', 'inventory_adjustment');

        // Verify balance was adjusted (-2 + 5 = 3)
        $balance = DB::table('inventory_balances')->where('product_id', $product->id)->first();
        $this->assertEquals(3, $balance->on_hand);

        // Verify reconciliation movement was recorded
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'movement_type' => 'reconciliation_adjustment',
            'quantity_change' => 5,
        ]);
    }
}

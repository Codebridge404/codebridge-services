<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'pin_code' => '1111',
        ]);

        $this->supervisor = User::factory()->create([
            'role' => 'supervisor',
            'pin_code' => '9999',
        ]);
    }

    /**
     * Test complete shift lifecycle with balanced cash drawer reconciliation.
     */
    public function test_full_shift_lifecycle_balanced_reconciliation(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        // 1. Open shift with $100 starting float
        $openResponse = $this->postJson('/api/v1/shifts/open', [
            'opening_float' => 100.00,
            'note' => 'Morning shift open',
        ]);
        $openResponse->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'opening_float' => 100.00,
                    'status' => 'open',
                ],
            ]);

        $shiftId = $openResponse->json('data.id');

        // 2. Active shift summary
        $activeResponse = $this->getJson('/api/v1/shifts/active');
        $activeResponse->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'summary' => [
                        'opening_float' => 100.00,
                        'expected_cash' => 100.00,
                    ],
                ],
            ]);

        // 3. Cash in movement: adding $50 change
        $cashInResponse = $this->postJson('/api/v1/shifts/cash-movement', [
            'type' => 'in',
            'amount' => 50.00,
            'reason' => 'Additional small bills for change',
        ]);
        $cashInResponse->assertStatus(200);

        // 4. Cash out movement: $20 petty cash
        $cashOutResponse = $this->postJson('/api/v1/shifts/cash-movement', [
            'type' => 'out',
            'amount' => 20.00,
            'reason' => 'Store emergency supply purchase',
        ]);
        $cashOutResponse->assertStatus(200);

        // 5. Active shift now reflects movements: 100 + 50 - 20 = $130
        $updatedSummary = $this->getJson('/api/v1/shifts/active');
        $updatedSummary->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'summary' => [
                        'cash_in' => 50.00,
                        'cash_out' => 20.00,
                        'expected_cash' => 130.00,
                    ],
                ],
            ]);

        // 6. Close shift with counted cash = $130.00 (Zero variance)
        $closeResponse = $this->postJson('/api/v1/shifts/close', [
            'counted_cash' => 130.00,
            'closing_note' => 'Shift balanced perfectly',
        ]);

        $closeResponse->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'expected_cash' => 130.00,
                    'counted_cash' => 130.00,
                    'cash_variance' => 0.00,
                ],
            ]);

        // Verify shift status in database is closed
        $this->assertDatabaseHas('shifts', [
            'id' => $shiftId,
            'status' => 'closed',
            'counted_cash' => 130.00,
        ]);
    }

    /**
     * Test shift close exceeding tolerance ($5.00) requires supervisor PIN.
     */
    public function test_shift_variance_exceeding_tolerance_requires_supervisor_pin(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        // Open shift with $100
        $this->postJson('/api/v1/shifts/open', ['opening_float' => 100.00])->assertStatus(201);

        // 1. Close with $80 without supervisor PIN -> 403 Forbidden
        $failNoPin = $this->postJson('/api/v1/shifts/close', [
            'counted_cash' => 80.00, // $20 shortage
        ]);
        $failNoPin->assertStatus(403)
            ->assertJson([
                'status' => 'error',
            ]);

        // 2. Close with invalid supervisor PIN -> 403 Forbidden
        $failWrongPin = $this->postJson('/api/v1/shifts/close', [
            'counted_cash' => 80.00,
            'supervisor_pin' => '0000',
        ]);
        $failWrongPin->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid Supervisor PIN code.',
            ]);

        // 3. Close with valid supervisor PIN -> 200 OK
        $successWithPin = $this->postJson('/api/v1/shifts/close', [
            'counted_cash' => 80.00,
            'supervisor_pin' => '9999',
            'closing_note' => 'Supervisor chenda authorized $20 cash drop variance',
        ]);

        $successWithPin->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'expected_cash' => 100.00,
                    'counted_cash' => 80.00,
                    'cash_variance' => -20.00,
                ],
            ]);
    }

    /**
     * Test mid-shift X-Report generation.
     */
    public function test_x_report_generation(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $openRes = $this->postJson('/api/v1/shifts/open', ['opening_float' => 75.00]);
        $openRes->assertStatus(201);
        $shiftId = $openRes->json('data.id');

        $response = $this->getJson("/api/v1/shifts/{$shiftId}/x-report");

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
            ]);
    }
}

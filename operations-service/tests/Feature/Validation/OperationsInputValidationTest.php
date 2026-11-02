<?php

namespace Tests\Feature\Validation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

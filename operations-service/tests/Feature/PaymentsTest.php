<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test public webhook callback endpoint.
     */
    public function test_payment_webhook_callback(): void
    {
        $response = $this->postJson('/api/v1/payment-callbacks/aba', [
            'tran_id' => 'TXN-998877',
            'status' => 'APPROVED',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);
    }

    /**
     * Test reconciliation endpoints require authentication.
     */
    public function test_reconciliation_requires_authentication(): void
    {
        $this->getJson('/api/v1/reconciliation/exceptions')->assertStatus(401);
    }

    /**
     * Test authenticated accountant can view reconciliation exceptions.
     */
    public function test_accountant_can_view_reconciliation(): void
    {
        $accountant = User::factory()->create([
            'role' => 'accountant',
        ]);

        Sanctum::actingAs($accountant, ['*']);

        $this->getJson('/api/v1/reconciliation/exceptions')->assertStatus(200);
    }
}

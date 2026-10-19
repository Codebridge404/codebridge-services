<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test finance endpoints require authentication.
     */
    public function test_finance_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/expenses')->assertStatus(401);
        $this->getJson('/api/v1/income')->assertStatus(401);
        $this->getJson('/api/v1/bank-accounts')->assertStatus(401);
    }

    /**
     * Test authenticated accountant can view finance records.
     */
    public function test_accountant_can_view_finance_records(): void
    {
        $user = User::factory()->create([
            'role' => 'accountant',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/expenses')->assertStatus(200);
        $this->getJson('/api/v1/income')->assertStatus(200);
        $this->getJson('/api/v1/bank-accounts')->assertStatus(200);
    }
}

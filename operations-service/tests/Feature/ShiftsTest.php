<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test unauthenticated shift queries are blocked.
     */
    public function test_shift_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/shifts/active')->assertStatus(401);
        $this->getJson('/api/v1/shifts/history')->assertStatus(401);
        $this->postJson('/api/v1/shifts/open', [])->assertStatus(401);
    }

    /**
     * Test authenticated staff can check active shift.
     */
    public function test_cashier_can_check_active_shift_and_history(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
        ]);

        Sanctum::actingAs($cashier, ['*']);

        $this->getJson('/api/v1/shifts/active')->assertStatus(200);
        $this->getJson('/api/v1/shifts/history')->assertStatus(200);
    }
}

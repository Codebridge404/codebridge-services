<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test executive reports require authentication.
     */
    public function test_reports_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/dashboard/summary')->assertStatus(401);
        $this->getJson('/api/v1/reports/sales')->assertStatus(401);
    }

    /**
     * Test authenticated manager can view dashboard summary and reports.
     */
    public function test_manager_can_view_dashboard_and_reports(): void
    {
        $manager = User::factory()->create([
            'role' => 'outlet_manager',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/v1/admin/dashboard/summary')->assertStatus(200);
        $this->getJson('/api/v1/admin/dashboard/widgets')->assertStatus(200);
        $this->getJson('/api/v1/reports/sales')->assertStatus(200);
        $this->getJson('/api/v1/reports/shifts')->assertStatus(200);
    }
}

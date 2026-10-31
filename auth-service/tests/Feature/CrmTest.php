<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test CRM endpoints require authentication.
     */
    public function test_crm_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/crm/deals')->assertStatus(401);
        $this->getJson('/api/v1/crm/leads')->assertStatus(401);
        $this->getJson('/api/v1/attendance')->assertStatus(401);
    }

    /**
     * Test authenticated staff can view CRM deals and attendance.
     */
    public function test_authenticated_user_can_view_crm_and_attendance(): void
    {
        $user = User::factory()->inWorkspace()->create();

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/crm/deals')->assertStatus(200);
        $this->getJson('/api/v1/crm/leads')->assertStatus(200);
        $this->getJson('/api/v1/crm/activities')->assertStatus(200);
        $this->getJson('/api/v1/attendance')->assertStatus(200);
    }
}

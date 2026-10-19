<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test meeting endpoints require authentication.
     */
    public function test_meetings_require_authentication(): void
    {
        $this->getJson('/api/v1/meetings/stats')->assertStatus(401);
        $this->getJson('/api/v1/meetings')->assertStatus(401);
        $this->getJson('/api/v1/meeting-rooms')->assertStatus(401);
    }

    /**
     * Test authenticated staff can view meetings and rooms.
     */
    public function test_authenticated_user_can_view_meetings_and_rooms(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/meetings/stats')->assertStatus(200);
        $this->getJson('/api/v1/meetings')->assertStatus(200);
        $this->getJson('/api/v1/meeting-rooms')->assertStatus(200);
        $this->getJson('/api/v1/meetings/notifications')->assertStatus(200);
    }
}

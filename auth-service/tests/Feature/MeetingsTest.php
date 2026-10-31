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
        $user = User::factory()->inWorkspace()->create();

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/meetings/stats')->assertStatus(200);
        $this->getJson('/api/v1/meetings')->assertStatus(200);
        $this->getJson('/api/v1/meeting-rooms')->assertStatus(200);
        $this->getJson('/api/v1/meetings/notifications')->assertStatus(200);
    }

    /**
     * Test meeting creation, action items, and issues adhering strictly to table schemas.
     */
    public function test_meeting_creation_and_action_items_conforming_to_erd(): void
    {
        $tenantId = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId([
            'name' => 'Meeting Org',
            'slug' => 'meeting-org',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenantId,
        ]);

        Sanctum::actingAs($user, ['*']);

        // 1. Create Meeting Room
        $roomRes = $this->postJson('/api/v1/meeting-rooms', [
            'name' => 'Boardroom VIP Alpha',
            'code' => 'ROOM-VIP-01',
            'capacity' => 15,
            'floor' => 'Floor 4',
            'amenities' => '4K Projector, Whiteboard',
            'status' => 'active',
        ])->assertStatus(201);
        $roomId = $roomRes->json('data.id');
        $this->assertDatabaseHas('meeting_rooms', ['id' => $roomId, 'code' => 'ROOM-VIP-01']);

        // 2. Create Meeting
        $startTime = now()->addHours(2)->format('Y-m-d H:i:00');
        $endTime = now()->addHours(3)->format('Y-m-d H:i:00');
        $meetingRes = $this->postJson('/api/v1/meetings', [
            'room_id' => $roomId,
            'title' => 'Q4 Executive Architecture Review',
            'description' => 'Review microservice schemas and ERD audit',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'meeting_type' => 'internal',
        ])->assertStatus(201);
        $meetingId = $meetingRes->json('data.id');
        $this->assertDatabaseHas('meetings', ['id' => $meetingId, 'title' => 'Q4 Executive Architecture Review']);

        // 3. Create Action Item
        $actionRes = $this->postJson("/api/v1/meetings/{$meetingId}/actions", [
            'title' => 'Review Page 5 ERD schemas',
            'description' => 'Ensure all database column bindings are strictly verified',
            'assigned_to' => $user->id,
            'due_date' => now()->addDays(2)->toDateString(),
            'status' => 'pending',
        ])->assertStatus(201);
        $actionId = $actionRes->json('data.id');
        $this->assertDatabaseHas('meeting_action_items', ['id' => $actionId, 'assignee_id' => $user->id]);

        // 4. Report Room Issue
        $issueRes = $this->postJson("/api/v1/meetings/rooms/{$roomId}/issues", [
            'category' => 'audio_visual',
            'priority' => 'high',
            'description' => 'HDMI cable connector is loose',
        ])->assertStatus(201);
        $issueId = $issueRes->json('data.id');
        $this->assertDatabaseHas('meeting_room_issues', ['id' => $issueId, 'room_id' => $roomId]);
    }
}

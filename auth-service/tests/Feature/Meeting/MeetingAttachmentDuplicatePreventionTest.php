<?php

namespace Tests\Feature\Meeting;

use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingRoom;
use App\Models\Organization\Tenant;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingAttachmentDuplicatePreventionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected MeetingRoom $room;
    protected Meeting $meeting;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Attachment Test Org',
            'slug' => 'attachment-test-org-' . Str::random(5),
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Meeting Admin',
            'email' => 'admin.' . Str::random(5) . '@attachment.test',
            'password' => bcrypt('Password123!'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->room = MeetingRoom::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Strategy Room A',
            'code' => 'ROOM-STRAT-' . Str::upper(Str::random(4)),
            'capacity' => 10,
            'hourly_rate' => 0.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->meeting = Meeting::create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $this->room->id,
            'organizer_id' => $this->user->id,
            'title' => 'Quarterly Review Meeting',
            'start_time' => now()->addHour(),
            'end_time' => now()->addHours(2),
            'status' => 'confirmed',
        ]);
    }

    public function test_can_upload_attachment_first_time(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attachments", [
                'file_name' => 'Q4_Financial_Report.pdf',
                'file_path' => 'meetings/attachments/Q4_Financial_Report.pdf',
                'file_type' => 'application/pdf',
                'file_size' => 1048576,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'message' => 'Attachment uploaded successfully.',
            ])
            ->assertJsonPath('data.file_name', 'Q4_Financial_Report.pdf');

        $this->assertDatabaseHas('meeting_attachments', [
            'meeting_id' => $this->meeting->id,
            'file_name' => 'Q4_Financial_Report.pdf',
        ]);
    }

    public function test_rejects_duplicate_attachment_with_same_filename_on_same_meeting(): void
    {
        // First upload
        $this->actingAs($this->user)
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attachments", [
                'file_name' => 'Architecture_Blueprint.png',
                'file_path' => 'meetings/attachments/Architecture_Blueprint.png',
                'file_type' => 'image/png',
                'file_size' => 524288,
            ])
            ->assertStatus(201);

        // Attempt duplicate upload with same filename (case-insensitive)
        $duplicateResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attachments", [
                'file_name' => 'architecture_blueprint.png',
                'file_path' => 'meetings/attachments/Architecture_Blueprint_v2.png',
                'file_type' => 'image/png',
                'file_size' => 524288,
            ]);

        $duplicateResponse->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'DUPLICATE_FILE_UPLOAD',
            ])
            ->assertJsonPath('data.file_name', 'Architecture_Blueprint.png');
    }

    public function test_allows_same_filename_on_different_meetings(): void
    {
        $meeting2 = Meeting::create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $this->room->id,
            'organizer_id' => $this->user->id,
            'title' => 'Separate Project Sync',
            'start_time' => now()->addDays(2),
            'end_time' => now()->addDays(2)->addHour(),
            'status' => 'confirmed',
        ]);

        // Upload to Meeting 1
        $this->actingAs($this->user)
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attachments", [
                'file_name' => 'Shared_Guidelines.pdf',
                'file_path' => 'meetings/attachments/meeting1/Shared_Guidelines.pdf',
            ])
            ->assertStatus(201);

        // Upload same file name to Meeting 2 - must succeed because it's a different meeting
        $this->actingAs($this->user)
            ->postJson("/api/v1/meetings/{$meeting2->id}/attachments", [
                'file_name' => 'Shared_Guidelines.pdf',
                'file_path' => 'meetings/attachments/meeting2/Shared_Guidelines.pdf',
            ])
            ->assertStatus(201);
    }
}

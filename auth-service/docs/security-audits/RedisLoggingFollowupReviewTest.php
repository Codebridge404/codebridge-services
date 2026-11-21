<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Follow-up audit probes assert observed gaps, not security acceptance behavior. */
class RedisLoggingFollowupReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        Http::fake();
    }

    public function test_observed_public_issue_request_is_attributed_to_an_unrelated_user_and_tenant(): void
    {
        $user = User::factory()->inWorkspace()->create(['name' => 'Unrelated account']);
        $roomTenant = Tenant::create(['name' => 'Actual room workspace', 'slug' => 'room-workspace-'.Str::uuid(), 'status' => 'active']);
        $roomId = DB::table('meeting_rooms')->insertGetId([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $roomTenant->id,
            'name' => 'Follow-up audit room', 'code' => 'AUDIT-ROOM', 'capacity' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $requestId = 'public-actor-'.Str::uuid();
        $response = $this->withHeader('X-Request-ID', $requestId)
            ->postJson('/api/v1/meeting-rooms/'.$roomId.'/issues/public', ['description' => 'Synthetic audit issue'])
            ->assertCreated();
        $this->assertNull($this->app['auth']->guard('sanctum')->user());
        $this->assertSame($user->id, (int) $response->json('data.id'));
        $this->assertSame($user->id, (int) $response->json('data.reported_by'));
        $this->assertSame($roomTenant->id, (int) $response->json('data.tenant_id'));
        $this->assertNotSame($roomTenant->id, $user->tenant_id);
        $this->assertDatabaseHas('api_request_logs', ['request_id' => $requestId, 'user_id' => $user->id, 'tenant_id' => $user->tenant_id]);
        $this->assertDatabaseHas('audit_logs', ['request_id' => $requestId, 'user_id' => $user->id, 'tenant_id' => $user->tenant_id]);
    }

    public function test_observed_invalid_utf8_in_an_extra_form_field_prevents_api_request_persistence(): void
    {
        $warnings = [];
        Log::listen(static function ($message) use (&$warnings): void {
            if ($message->message === 'Failed to record api_request_log') {
                $warnings[] = $message->context['exception_class'] ?? '';
            }
        });
        $requestId = 'invalid-utf8-'.Str::uuid();
        // This is a form request, equivalent to a percent-encoded %FF byte, not malformed JSON.
        $this->withHeaders(['X-Request-ID' => $requestId, 'Accept' => 'application/json'])
            ->post('/api/v1/auth/login', [
                'email' => 'missing-user@example.test', 'password' => 'FakeAuditPassword123!',
                'extra_field' => "invalid-byte-\xFF",
            ])->assertStatus(422);
        $this->assertDatabaseMissing('api_request_logs', ['request_id' => $requestId]);
        $this->assertContains(JsonEncodingException::class, $warnings);
    }

    public function test_observed_invalid_utf8_also_prevents_automatic_audit_for_successful_tenantless_request(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => 'FakeAuditPassword123!']);
        $requestId = 'invalid-utf8-success-'.Str::uuid();
        $this->withToken($user->createToken('Follow-up audit')->plainTextToken)
            ->withHeaders(['X-Request-ID' => $requestId, 'Accept' => 'application/json'])
            ->post('/api/v1/auth/reauthenticate/verify', [
                'password' => 'FakeAuditPassword123!', 'extra_field' => "invalid-byte-\xFF",
            ])->assertOk();
        $this->assertDatabaseMissing('api_request_logs', ['request_id' => $requestId]);
        $this->assertDatabaseMissing('audit_logs', ['request_id' => $requestId]);
    }
}

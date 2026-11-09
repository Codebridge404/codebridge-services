<?php

namespace Tests\Feature\Security;

use App\Models\ApiClient;
use App\Models\User;
use App\Models\UserSession;
use App\Support\SchemaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepTwoHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_touch_last_seen_throttles_writes_within_sixty_seconds(): void
    {
        $user = User::factory()->create();
        $client = ApiClient::firstOrFail();
        $initialSeenAt = Carbon::now()->subSeconds(20);

        $session = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'api_client_id' => $client->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'POS-Client/1.0',
            'expires_at' => Carbon::now()->addHour(),
            'last_seen_at' => $initialSeenAt,
        ]);

        // Within 60s with same IP: must skip DB update
        $updatedSoon = $session->touchLastSeen('203.0.113.10', 'POS-Client/1.0');
        $this->assertFalse($updatedSoon);
        $this->assertEquals(
            $initialSeenAt->toDateTimeString(),
            $session->fresh()->last_seen_at->toDateTimeString()
        );

        // Changed IP bypasses throttle immediately
        $updatedNewIp = $session->touchLastSeen('203.0.113.99', 'POS-Client/1.0');
        $this->assertTrue($updatedNewIp);
        $this->assertEquals('203.0.113.99', $session->fresh()->ip_address);

        // Past 60s window triggers DB update
        $session->forceFill(['last_seen_at' => Carbon::now()->subSeconds(90)])->saveQuietly();
        $updatedAfterWindow = $session->touchLastSeen('203.0.113.99', 'POS-Client/2.0');
        $this->assertTrue($updatedAfterWindow);
        $this->assertEquals('POS-Client/2.0', $session->fresh()->user_agent);
    }

    public function test_schema_guard_and_escape_like_behavior(): void
    {
        $this->assertSame('100\\%\\_off\\\\deal', SchemaGuard::escapeLike('100%_off\\deal'));
        $this->assertTrue(SchemaGuard::isSqlite());
        $this->assertTrue(SchemaGuard::hasTable('users'));
        $this->assertFalse(SchemaGuard::hasTable('non_existent_table_xyz'));
    }

    public function test_users_index_caps_per_page_at_100_and_neutralizes_like_wildcards(): void
    {
        $admin = User::factory()->inWorkspace()->create([
            'name' => 'Workspace Admin',
            'email' => 'admin.p2@sunnycafe.kh',
            'role' => 'admin',
        ]);

        User::factory()->create([
            'name' => 'Barista Alice',
            'email' => 'alice.p2@sunnycafe.kh',
            'tenant_id' => $admin->tenant_id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // per_page=9999 must be capped at 100
        $cappedRes = $this->getJson('/api/v1/users?per_page=9999')
            ->assertStatus(200)
            ->assertJsonPath('pagination.per_page', 100);
        $this->assertGreaterThanOrEqual(2, $cappedRes->json('pagination.total'));

        // Wildcard-only search ("%%%") must NOT match arbitrary names
        $wildcardRes = $this->getJson('/api/v1/users?search=' . urlencode('%%%'))
            ->assertStatus(200);
        $this->assertCount(0, $wildcardRes->json('data'));
    }
}

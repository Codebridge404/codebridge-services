<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\RefreshToken;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_app_key_records_security_event(): void
    {
        $response = $this->withHeader('X-App-Key', 'invalid_unregistered_app_key')
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@sunnycafe.kh',
                'password' => 'Password123!',
            ]);

        $response->assertStatus(401);

        $event = SecurityEvent::where('event_type', 'app_key_invalid')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertEquals('SEC_APP_KEY_INVALID', $event->rule_code);
        $this->assertEquals('medium', $event->severity);
        $this->assertEquals('blocked', $event->outcome);
        $this->assertEquals(401, $event->status_code);
    }

    public function test_refresh_token_replay_attack_records_critical_security_event(): void
    {
        $client = ApiClient::create([
            'name' => 'POS Client',
            'client_key' => 'client_pos_rot_test',
            'client_type' => 'public',
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $tenant = Tenant::create([
            'name' => 'Cafe Tenant',
            'slug' => 'cafe-' . uniqid(),
            'status' => 'active',
            'owner_user_id' => $user->id,
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $session = UserSession::create([
            'user_id' => $user->id,
            'api_client_id' => $client->id,
            'tenant_id' => $tenant->id,
            'device_name' => 'Register 1',
            'permissions' => ['pos.cashier'],
            'expires_at' => now()->addDays(30),
        ]);

        $root = RefreshToken::issueRoot($session);
        $consumedToken = $root['token'];
        $consumedToken->update(['consumed_at' => now()]);

        // Attempt replay of the consumed token
        try {
            RefreshToken::rotate($root['credential']);
            $this->fail('Expected RuntimeException on replay attack');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('consumed', $e->getMessage());
        }

        $event = SecurityEvent::where('event_type', 'refresh_token_replay_attack')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertEquals('SEC_TOKEN_REPLAY', $event->rule_code);
        $this->assertEquals('critical', $event->severity);
        $this->assertEquals('blocked', $event->outcome);
        $this->assertEquals($user->id, $event->user_id);
        $this->assertEquals($tenant->id, $event->tenant_id);
        $this->assertEquals(hash('sha256', $session->uuid), $event->session_ref_hash);
    }

    public function test_vulnerability_scanner_records_high_severity_security_event(): void
    {
        $response = $this->withHeader('User-Agent', 'sqlmap/1.5.2#stable (https://sqlmap.org)')
            ->getJson('/api/v1/auth/sessions');

        $response->assertStatus(403);

        $event = SecurityEvent::where('event_type', 'attack_scanner_detected')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertEquals('SEC_ATTACK_SCANNER_DETECTED', $event->rule_code);
        $this->assertEquals('high', $event->severity);
        $this->assertEquals('blocked', $event->outcome);
        $this->assertEquals(403, $event->status_code);
    }

    public function test_no_cascading_deletion_of_evidence_on_delete_restrict(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::create([
            'name' => 'Restrict Tenant',
            'slug' => 'restrict-' . uniqid(),
            'status' => 'active',
            'owner_user_id' => $user->id,
        ]);

        $event = SecurityEvent::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'request_id' => 'req_restrict_test',
            'event_type' => 'tenant_access_denied',
            'severity' => 'medium',
            'confidence' => 'confirmed',
            'outcome' => 'blocked',
            'rule_code' => 'SEC_TENANT_DENIED',
            'summary' => 'Tenant boundary protection',
        ]);

        // Attempting to delete tenant with associated security evidence must fail due to foreign key RESTRICT
        $this->expectException(\Illuminate\Database\QueryException::class);
        $tenant->delete();
    }
}

<?php

namespace Tests\Unit;

use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SecurityEventModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_auto_generates_uuid_and_defaults(): void
    {
        $event = SecurityEvent::create([
            'event_type' => 'test_event',
            'severity' => 'low',
            'confidence' => 'observed',
            'outcome' => 'allowed',
            'request_id' => 'req_test_12345',
            'rule_code' => 'TEST_RULE_001',
            'summary' => 'Test security event execution',
        ]);

        $this->assertNotEmpty($event->uuid);
        $this->assertEquals(36, strlen($event->uuid));
        $this->assertEquals('auth-service', $event->service_name);
        $this->assertNotNull($event->occurred_at);
        $this->assertNotNull($event->created_at);
    }

    public function test_sensitive_credentials_are_redacted_from_metadata(): void
    {
        $event = SecurityEvent::create([
            'event_type' => 'login_probe',
            'severity' => 'medium',
            'confidence' => 'suspected',
            'outcome' => 'blocked',
            'request_id' => 'req_probe_999',
            'rule_code' => 'SEC_PROBE_001',
            'summary' => 'Probing detected',
            'metadata' => [
                'password' => 'Secret123!',
                'pin_code' => '1234',
                'access_token' => 'jwt.token.secret',
                'refresh_token' => 'rt_test_secret',
                'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
                'safe_param' => 'public_value',
                'nested' => [
                    'secret' => 'nested_secret_key',
                    'allowed' => 'nested_allowed',
                ],
            ],
        ]);

        $meta = $event->fresh()->metadata;

        $this->assertEquals('[REDACTED]', $meta['password']);
        $this->assertEquals('[REDACTED]', $meta['pin_code']);
        $this->assertEquals('[REDACTED]', $meta['access_token']);
        $this->assertEquals('[REDACTED]', $meta['refresh_token']);
        $this->assertEquals('[REDACTED]', $meta['bot_token']);
        $this->assertEquals('[REDACTED]', $meta['nested']['secret']);
        $this->assertEquals('public_value', $meta['safe_param']);
        $this->assertEquals('nested_allowed', $meta['nested']['allowed']);
    }

    public function test_relationships_to_user_and_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::create([
            'name' => 'Sec Tenant',
            'slug' => 'sec-tenant-' . uniqid(),
            'status' => 'active',
            'owner_user_id' => $user->id,
        ]);

        $event = SecurityEvent::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'request_id' => 'req_rel_123',
            'event_type' => 'tenant_access_denied',
            'severity' => 'medium',
            'confidence' => 'confirmed',
            'outcome' => 'blocked',
            'rule_code' => 'SEC_TENANT_DENIED',
            'summary' => 'Tenant boundary violated',
        ]);

        $this->assertInstanceOf(User::class, $event->user);
        $this->assertEquals($user->id, $event->user->id);
        $this->assertInstanceOf(Tenant::class, $event->tenant);
        $this->assertEquals($tenant->id, $event->tenant->id);

        $this->assertTrue($user->securityEvents->contains($event));
        $this->assertTrue($tenant->securityEvents->contains($event));
    }

    public function test_query_scopes(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::create([
            'name' => 'Scope Tenant',
            'slug' => 'scope-tenant-' . uniqid(),
            'status' => 'active',
            'owner_user_id' => $user->id,
        ]);

        SecurityEvent::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'request_id' => 'req_scope_1',
            'event_type' => 'jwt_invalid_signature',
            'severity' => 'critical',
            'confidence' => 'confirmed',
            'outcome' => 'blocked',
            'rule_code' => 'SEC_JWT_01',
            'summary' => 'Bad signature',
        ]);

        SecurityEvent::create([
            'tenant_id' => null,
            'user_id' => null,
            'request_id' => 'req_scope_2',
            'event_type' => 'login_failed',
            'severity' => 'low',
            'confidence' => 'observed',
            'outcome' => 'failed',
            'rule_code' => 'AUTH_FAIL_01',
            'summary' => 'Wrong password',
        ]);

        $this->assertCount(1, SecurityEvent::forTenant($tenant->id)->get());
        $this->assertCount(1, SecurityEvent::severity('critical')->get());
        $this->assertCount(1, SecurityEvent::eventType('jwt_invalid_signature')->get());
        $this->assertCount(1, SecurityEvent::outcome('blocked')->get());
    }

    public function test_record_factory_helper(): void
    {
        $request = Request::create('/api/v1/some-secure-path', 'POST', ['client_info' => 'test']);
        $traceId = md5('test-trace-id');
        $request->headers->set('X-Request-ID', 'req-corr-uuid-1234');
        $request->headers->set('X-Trace-ID', $traceId);
        $request->headers->set('User-Agent', 'CodeBridge-Scanner-Agent');

        $event = SecurityEvent::record(
            eventType: 'app_key_invalid',
            ruleCode: 'SEC_APP_KEY_INVALID',
            summary: 'Invalid application key submitted',
            severity: 'medium',
            outcome: 'blocked',
            confidence: 'confirmed',
            request: $request,
            metadata: ['client_key_prefix' => 'ck_fake_'],
            statusCode: 401
        );

        $this->assertEquals('req-corr-uuid-1234', $event->request_id);
        $this->assertEquals($traceId, $event->trace_id);
        $this->assertEquals('POST', $event->method);
        $this->assertEquals(401, $event->status_code);
        $this->assertEquals('app_key_invalid', $event->event_type);
        $this->assertEquals('SEC_APP_KEY_INVALID', $event->rule_code);
    }
}

<?php

namespace Tests\Feature\Security;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use App\Models\Security\ApiRequestLog;
use App\Models\Security\AuditLog;
use App\Models\Security\SecurityEvent;
use App\Services\IpThreatDefenseService;
use App\Support\TenantSettingsCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RedisAndLoggingRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        Http::fake();
    }

    public function test_login_records_redacted_api_request_and_explicit_audit_in_database(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => 'AuditOnlyPassword123!']);
        $requestId = 'audit-login-'.Str::uuid();
        $this->withHeader('X-Request-ID', $requestId)->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'AuditOnlyPassword123!',
        ])->assertOk();
        $request = ApiRequestLog::where('request_id', $requestId)->firstOrFail();
        $audit = AuditLog::where('request_id', $requestId)->where('action', 'auth.login')->firstOrFail();
        $this->assertSame('********', $request->request_data['password']);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame($request->trace_id, $audit->trace_id);
    }

    public function test_invalid_app_key_records_api_request_and_security_event(): void
    {
        $id = 'audit-key-'.Str::uuid();
        $this->withHeaders(['X-Request-ID' => $id, 'X-App-Key' => 'audit-invalid-key'])
            ->getJson('/api/v1/me')->assertUnauthorized();
        $request = ApiRequestLog::where('request_id', $id)->firstOrFail();
        $event = SecurityEvent::where('request_id', $id)->firstOrFail();
        $this->assertSame(401, $request->status_code);
        $this->assertSame('app_key_invalid', $event->event_type);
        $this->assertSame($request->trace_id, $event->trace_id);
    }

    public static function tenantContexts(): array
    {
        return ['workspace account' => [true], 'tenantless account' => [false]];
    }

    #[DataProvider('tenantContexts')]
    public function test_reauthentication_recording_depends_on_nullable_tenant_context(bool $withTenant): void
    {
        $warnings = [];
        Log::listen(static function ($message) use (&$warnings) {
            if (str_contains((string) $message->message, 'Failed to record')) {
                $warnings[] = (string) $message->message;
            }
        });
        $factory = User::factory();
        if ($withTenant) {
            $factory = $factory->inWorkspace();
        }
        $user = $factory->create(['is_active' => true, 'password' => 'AuditOnlyPassword123!']);
        $this->withToken($user->createToken('Redis audit')->plainTextToken);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/reauthenticate/verify', ['password' => 'WrongAuditPassword123!'])->assertStatus(422);
        }
        $id = 'audit-throttle-'.Str::uuid();
        $this->withHeader('X-Request-ID', $id)
            ->postJson('/api/v1/auth/reauthenticate/verify', ['password' => 'WrongAuditPassword123!'])->assertStatus(429);
        $this->assertSame([], $warnings);
        $this->assertDatabaseHas('api_request_logs', ['request_id' => $id, 'status_code' => 429, 'tenant_id' => $user->tenant_id]);
        $this->assertDatabaseHas('security_events', ['request_id' => $id, 'status_code' => 429, 'tenant_id' => $user->tenant_id]);
    }

    public function test_successful_tenantless_sanctum_request_records_api_and_automatic_audit(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => 'AuditOnlyPassword123!']);
        $id = 'audit-tenantless-'.Str::uuid();
        $this->withToken($user->createToken('Tenantless audit')->plainTextToken)
            ->withHeader('X-Request-ID', $id)
            ->postJson('/api/v1/auth/reauthenticate/verify', ['password' => 'AuditOnlyPassword123!'])->assertOk();
        $this->assertDatabaseHas('api_request_logs', ['request_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['request_id' => $id, 'tenant_id' => null]);
    }

    public function test_settings_cache_invalidation_and_tenant_separation(): void
    {
        $value = 'before';
        $resolve = static function () use (&$value) {
            return $value;
        };
        $this->assertSame('before', TenantSettingsCache::rememberOutlet('terminal', 'tenant-a', 999, $resolve));
        $value = 'after';
        $this->assertSame('before', TenantSettingsCache::rememberOutlet('terminal', 'tenant-a', 999, $resolve));
        $this->assertSame('after', TenantSettingsCache::rememberOutlet('terminal', 'tenant-b', 999, $resolve));
        TenantSettingsCache::invalidateOutlet('tenant-a', 999);
        $this->assertSame('after', TenantSettingsCache::rememberOutlet('terminal', 'tenant-a', 999, $resolve));
        $value = 'meeting-before';
        $this->assertSame('meeting-before', TenantSettingsCache::rememberMeetingSettings('tenant-a', $resolve));
        $value = 'meeting-after';
        $this->assertSame('meeting-before', TenantSettingsCache::rememberMeetingSettings('tenant-a', $resolve));
        TenantSettingsCache::invalidateMeetingSettings('tenant-a');
        $this->assertSame('meeting-after', TenantSettingsCache::rememberMeetingSettings('tenant-a', $resolve));
    }

    public function test_scanner_block_records_api_request_security_event_and_audit(): void
    {
        $id = 'audit-scanner-'.Str::uuid();
        $this->withHeaders(['X-Request-ID' => $id, 'User-Agent' => 'sqlmap/audit'])
            ->getJson('/api/v1/me')->assertForbidden();
        $this->assertDatabaseHas('security_events', ['request_id' => $id, 'event_type' => 'attack_scanner_detected']);
        $this->assertDatabaseHas('audit_logs', ['request_id' => $id, 'action' => 'security.attack_blocked']);
        $this->assertDatabaseHas('api_request_logs', ['request_id' => $id]);
        $this->assertDatabaseCount('security_events', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_scanner_block_without_incoming_request_id_correlates_all_records(): void
    {
        $this->withHeader('User-Agent', 'sqlmap/audit')->getJson('/api/v1/me')->assertForbidden();
        $event = SecurityEvent::where('event_type', 'attack_scanner_detected')->firstOrFail();
        $audit = AuditLog::where('action', 'security.attack_blocked')->firstOrFail();
        $this->assertSame($event->request_id, $audit->request_id);
        $this->assertSame($event->trace_id, $audit->trace_id);
        $this->assertDatabaseHas('api_request_logs', ['request_id' => $event->request_id, 'trace_id' => $event->trace_id]);
    }

    public function test_expired_ban_is_pruned_and_empty_index_is_forgotten(): void
    {
        $ip = '192.0.2.77';
        Cache::forever('sec_banned_ips_index', [$ip => ['ip' => $ip, 'expires_at' => now()->subMinute()->toIso8601String()]]);
        $this->assertFalse(Cache::has('sec_ip_ban:'.$ip));
        $this->assertSame([], IpThreatDefenseService::getBannedIps());
        $this->assertFalse(Cache::has('sec_banned_ips_index'));
    }

    public function test_audit_helper_redacts_nested_payload(): void
    {
        $audit = AuditLog::record('audit.probe', payload: ['nested' => ['password' => 'fake-audit-password', 'grant_token' => 'fake-grant-token']]);
        $this->assertNotNull($audit);
        $this->assertSame('********', $audit->fresh()->payload['nested']['password']);
        $this->assertSame('********', $audit->fresh()->payload['nested']['grant_token']);
    }

    public function test_security_event_redacts_grant_token_alias(): void
    {
        $event = SecurityEvent::record('audit.probe', 'SEC_AUDIT_PROBE', 'Audit redaction probe', metadata: [
            'password' => 'fake-audit-password', 'nested' => ['grant_token' => 'fake-grant-token'],
        ]);
        $this->assertSame('[REDACTED]', $event->fresh()->metadata['password']);
        $this->assertSame('[REDACTED]', $event->fresh()->metadata['nested']['grant_token']);
    }

    public function test_untrusted_tenant_body_cannot_attribute_guest_or_tenantless_logs(): void
    {
        $tenant = Tenant::create(['name' => 'Unrelated workspace', 'slug' => 'unrelated-'.Str::uuid(), 'status' => 'active']);
        $guestId = 'guest-spoof-'.Str::uuid();
        $this->withoutHeader('X-App-Key')->withHeader('X-Request-ID', $guestId)
            ->postJson('/api/v1/auth/login', ['tenant_id' => $tenant->id])->assertUnauthorized();
        $this->assertDatabaseHas('api_request_logs', ['request_id' => $guestId, 'tenant_id' => null]);
        $this->assertDatabaseHas('security_events', ['request_id' => $guestId, 'tenant_id' => null]);

        $user = User::factory()->create(['is_active' => true, 'password' => 'AuditOnlyPassword123!']);
        $id = 'tenantless-spoof-'.Str::uuid();
        $this->withHeader('X-App-Key', 'client_test_suite_key')->withHeader('X-Request-ID', $id)
            ->withToken($user->createToken('Spoof test')->plainTextToken)
            ->postJson('/api/v1/auth/reauthenticate/verify', ['password' => 'AuditOnlyPassword123!', 'tenant_id' => $tenant->id])
            ->assertOk();
        $this->assertDatabaseHas('api_request_logs', ['request_id' => $id, 'tenant_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['request_id' => $id, 'tenant_id' => null]);
    }

    public static function correlationHeaders(): array
    {
        return ['blank' => ['', ''], 'oversized' => [str_repeat('r', 90), str_repeat('t', 90)]];
    }

    #[DataProvider('correlationHeaders')]
    public function test_scanner_correlation_headers_fit_all_record_columns(string $requestId, string $traceId): void
    {
        $response = $this->withHeaders(['X-Request-ID' => $requestId, 'X-Trace-ID' => $traceId, 'User-Agent' => 'sqlmap/regression'])
            ->getJson('/api/v1/me')->assertForbidden();
        $id = $response->headers->get('X-Request-ID');
        $trace = $response->headers->get('X-Trace-ID');
        $this->assertNotEmpty($id);
        $this->assertLessThanOrEqual(64, strlen($id));
        $this->assertNotEmpty($trace);
        $this->assertLessThanOrEqual(32, strlen($trace));
        foreach (['api_request_logs', 'audit_logs', 'security_events'] as $table) {
            $this->assertDatabaseHas($table, ['request_id' => $id, 'trace_id' => $trace]);
        }
    }

    public function test_ban_index_pruning_preserves_active_bans_and_unban_forgets_last_index(): void
    {
        $active = ['ip' => '192.0.2.11', 'expires_at' => now()->addMinute()->toIso8601String()];
        Cache::put('sec_ip_ban:192.0.2.11', $active, 60);
        Cache::put('sec_ip_offense:192.0.2.11', 3, 60);
        Cache::forever('sec_banned_ips_index', [
            '192.0.2.11' => $active,
            '192.0.2.12' => ['ip' => '192.0.2.12', 'expires_at' => now()->subMinute()->toIso8601String()],
        ]);
        $this->assertSame([$active], IpThreatDefenseService::getBannedIps());
        $this->assertSame(['192.0.2.11' => $active], Cache::get('sec_banned_ips_index'));
        $this->assertTrue(IpThreatDefenseService::unbanIp('192.0.2.11'));
        $this->assertFalse(Cache::has('sec_banned_ips_index'));
        $this->assertFalse(Cache::has('sec_ip_ban:192.0.2.11'));
        $this->assertFalse(Cache::has('sec_ip_offense:192.0.2.11'));
    }

    public function test_ban_and_unban_updates_preserve_other_active_index_entries(): void
    {
        IpThreatDefenseService::banIp('192.0.2.21', 1);
        IpThreatDefenseService::banIp('192.0.2.22', 1);
        $this->assertCount(2, IpThreatDefenseService::getBannedIps());
        IpThreatDefenseService::unbanIp('192.0.2.21');
        $this->assertNotNull(IpThreatDefenseService::isBanned('192.0.2.22'));
        $this->assertSame(['192.0.2.22'], array_column(IpThreatDefenseService::getBannedIps(), 'ip'));
    }

    public function test_audit_and_security_models_redact_aliases_on_create_and_save(): void
    {
        $sensitive = ['grant_token', 'client_key', 'recovery_codes', 'OTP', 'X-Client-Key'];
        $payload = ['safe_label' => 'visible', 'nested' => array_fill_keys($sensitive, 'fake-secret')];
        $audit = AuditLog::create(['action' => 'redaction.create', 'module' => 'security', 'payload' => $payload]);
        $event = SecurityEvent::record('redaction.probe', 'SEC_REDACTION', 'Probe', metadata: $payload);
        foreach ($sensitive as $key) {
            $this->assertSame('********', $audit->fresh()->payload['nested'][$key]);
            $this->assertSame('[REDACTED]', $event->fresh()->metadata['nested'][$key]);
        }
        $this->assertSame('visible', $audit->fresh()->payload['safe_label']);
        $this->assertSame('visible', $event->fresh()->metadata['safe_label']);
        $audit->update(['payload' => ['grant_token' => 'replacement-secret']]);
        $event->update(['metadata' => ['grant_token' => 'replacement-secret']]);
        $this->assertSame('********', $audit->fresh()->payload['grant_token']);
        $this->assertSame('[REDACTED]', $event->fresh()->metadata['grant_token']);
    }

    public function test_failed_explicit_audit_allows_generic_fallback_without_logging_exception_secrets(): void
    {
        $attempts = 0;
        AuditLog::creating(static function () use (&$attempts): void {
            if ($attempts++ === 0) {
                throw new \RuntimeException('fake-exception-secret-must-not-be-logged');
            }
        });
        $warnings = [];
        Log::listen(static function ($message) use (&$warnings): void {
            if ($message->level === 'warning') {
                $warnings[] = [$message->message, $message->context];
            }
        });
        $user = User::factory()->create(['is_active' => true, 'password' => 'AuditOnlyPassword123!']);
        $id = 'audit-fallback-'.Str::uuid();
        $this->withHeader('X-Request-ID', $id)->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'AuditOnlyPassword123!',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['request_id' => $id, 'action' => 'post api/v1/auth/login']);
        $this->assertNotEmpty($warnings);
        $this->assertStringNotContainsString('fake-exception-secret-must-not-be-logged', json_encode($warnings));
    }

    public function test_failed_specific_security_event_allows_central_fallback(): void
    {
        $attempts = 0;
        SecurityEvent::creating(static function () use (&$attempts): void {
            if ($attempts++ === 0) {
                throw new \RuntimeException('Injected persistence failure');
            }
        });
        $id = 'event-fallback-'.Str::uuid();
        $this->withHeaders(['X-Request-ID' => $id, 'User-Agent' => 'sqlmap/regression'])
            ->getJson('/api/v1/me')->assertForbidden();
        $this->assertDatabaseHas('security_events', ['request_id' => $id, 'event_type' => 'access_forbidden']);
        $this->assertDatabaseHas('api_request_logs', ['request_id' => $id, 'status_code' => 403]);
        $this->assertDatabaseCount('security_events', 1);
    }
}

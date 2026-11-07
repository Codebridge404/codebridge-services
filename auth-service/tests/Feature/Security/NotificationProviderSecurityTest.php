<?php

namespace Tests\Feature\Security;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\NotificationProvider;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationProviderSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $name = 'Sec Org'): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'active',
        ]);
    }

    /**
     * =========================================================================
     * 1. SQL Injection Prevention Tests
     * =========================================================================
     */

    public function test_sql_injection_in_query_parameters_is_neutralized(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');

        $adminA = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenantA->id,
        ]);

        NotificationProvider::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Safe Provider A',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.a.com'],
            'is_enabled' => true,
        ]);

        NotificationProvider::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Secret Provider B',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.b.com'],
            'is_enabled' => true,
        ]);

        Sanctum::actingAs($adminA, ['*']);

        $maliciousQueries = [
            "q=" . urlencode("' OR '1'='1"),
            "q=" . urlencode("'; DROP TABLE notification_providers; --"),
            "channel=" . urlencode("email' OR '1'='1"),
            "channel=" . urlencode("' UNION SELECT 1,2,3,4,5,6,7,8,9,10,11,12,13,14,15--"),
            "environment=" . urlencode("production' OR 1=1--"),
            "is_enabled=" . urlencode("true' OR '1'='1"),
            "tenant_id=" . urlencode("1 OR 1=1"),
        ];

        foreach ($maliciousQueries as $param) {
            $response = $this->getJson("/api/v1/notification-providers?{$param}");

            // Must never crash with 500 error or leak database exceptions
            $this->assertNotEquals(500, $response->getStatusCode(), "Crashed on SQLi parameter: {$param}");
            $this->assertContains($response->getStatusCode(), [200, 422]);

            // When status is 200, verify tenant isolation was NOT breached by SQLi
            if ($response->getStatusCode() === 200) {
                $names = collect($response->json('data'))->pluck('name')->toArray();
                $this->assertNotContains('Secret Provider B', $names, "SQLi breached tenant boundary on parameter: {$param}");
            }
        }

        // Verify table still exists and data was not dropped
        $this->assertDatabaseHas('notification_providers', ['name' => 'Safe Provider A']);
        $this->assertDatabaseHas('notification_providers', ['name' => 'Secret Provider B']);
    }

    public function test_sql_injection_in_route_parameters_is_handled_safely(): void
    {
        $tenant = $this->createTenant('Route SQLi Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Persistent Provider',
            'channel' => 'sms',
            'provider' => 'twilio',
            'environment' => 'production',
            'credentials' => ['sid' => '123'],
            'is_enabled' => true,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $maliciousIds = [
            "1' OR '1'='1",
            "1; DROP TABLE notification_providers;--",
            "' UNION SELECT * FROM notification_providers--",
            "admin'--",
            "0 OR 1=1",
        ];

        foreach ($maliciousIds as $badId) {
            $encoded = urlencode($badId);

            // GET by ID
            $getRes = $this->getJson("/api/v1/notification-providers/{$encoded}");
            $this->assertNotEquals(500, $getRes->getStatusCode());
            $this->assertContains($getRes->getStatusCode(), [404, 422]);

            // PUT by ID
            $putRes = $this->putJson("/api/v1/notification-providers/{$encoded}", ['name' => 'Injected']);
            $this->assertNotEquals(500, $putRes->getStatusCode());
            $this->assertContains($putRes->getStatusCode(), [404, 422]);

            // TOGGLE by ID
            $toggleRes = $this->patchJson("/api/v1/notification-providers/{$encoded}/toggle");
            $this->assertNotEquals(500, $toggleRes->getStatusCode());
            $this->assertContains($toggleRes->getStatusCode(), [404, 422]);

            // TEST by ID
            $testRes = $this->postJson("/api/v1/notification-providers/{$encoded}/test");
            $this->assertNotEquals(500, $testRes->getStatusCode());
            $this->assertContains($testRes->getStatusCode(), [404, 422]);

            // DELETE by ID
            $delRes = $this->deleteJson("/api/v1/notification-providers/{$encoded}");
            $this->assertNotEquals(500, $delRes->getStatusCode());
            $this->assertContains($delRes->getStatusCode(), [404, 422]);
        }

        // Original provider must remain unmodified and intact
        $this->assertDatabaseHas('notification_providers', [
            'id' => $provider->id,
            'name' => 'Persistent Provider',
        ]);
    }

    public function test_sql_injection_payload_in_body_is_escaped_and_not_executed(): void
    {
        $tenant = $this->createTenant('Payload SQLi Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $maliciousName = "SMTP'; DROP TABLE notification_providers; --";

        $res = $this->postJson('/api/v1/notification-providers', [
            'name' => $maliciousName,
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.test.com'],
            'settings' => ['note' => "1' OR '1'='1"],
        ])->assertStatus(201);

        $providerId = $res->json('data.id');

        // Verify the record was inserted literally with SQL escaped safely
        $record = DB::table('notification_providers')->where('id', $providerId)->first();
        $this->assertNotNull($record);
        $this->assertEquals($maliciousName, $record->name);

        // Verify table still exists and functions
        $this->assertTrue(DB::table('notification_providers')->count() > 0);
    }

    /**
     * =========================================================================
     * 2. XSS (Cross-Site Scripting) Mitigation Tests
     * =========================================================================
     */

    public function test_xss_script_payloads_in_provider_are_safely_serialized(): void
    {
        $tenant = $this->createTenant('XSS Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $xssName = "<script>alert('XSS-NAME')</script>";
        $xssFromName = "<img src=x onerror=alert('XSS-IMG')>";
        $xssFooter = "<svg/onload=alert('XSS-SVG')>";

        $res = $this->postJson('/api/v1/notification-providers', [
            'name' => $xssName,
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.test.com'],
            'settings' => [
                'from_name' => $xssFromName,
                'footer' => $xssFooter,
            ],
        ])->assertStatus(201);

        $providerId = $res->json('data.id');

        // Response must strictly be application/json
        $res->assertHeader('content-type', 'application/json');

        // Inspect via GET
        $getRes = $this->getJson("/api/v1/notification-providers/{$providerId}")
            ->assertStatus(200);

        $getRes->assertHeader('content-type', 'application/json');

        // Payload is properly encoded in JSON string
        $this->assertEquals($xssName, $getRes->json('data.name'));
        $this->assertEquals($xssFromName, $getRes->json('data.settings.from_name'));
        $this->assertEquals($xssFooter, $getRes->json('data.settings.footer'));
    }

    public function test_xss_in_test_health_probe_recipient_is_safely_neutralized(): void
    {
        $tenant = $this->createTenant('XSS Probe Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        $provider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Probe Target Provider',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => ['host' => 'smtp.test.com'],
            'is_enabled' => true,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $xssRecipient = "\"><script>alert('probe-xss')</script>@domain.com";

        $res = $this->postJson("/api/v1/notification-providers/{$provider->id}/test", [
            'recipient' => $xssRecipient,
        ])->assertStatus(200);

        $res->assertHeader('content-type', 'application/json');
        $this->assertEquals('healthy', $res->json('data.probe.status'));
        $this->assertEquals($xssRecipient, $res->json('data.probe.target'));
    }

    /**
     * =========================================================================
     * 3. Audit Redaction & Secret Protection Tests
     * =========================================================================
     */

    public function test_audit_logs_redact_sensitive_credentials(): void
    {
        $tenant = $this->createTenant('Audit Redact Org');
        $admin = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $plainPassword = 'SuperSecretSmtpPass2026!';
        $plainApiKey = 'live_sk_abcdef1234567890';
        $plainBotToken = '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ';
        $plainWebhookUrl = 'https://hooks.slack.com/services/SECRET/KEY/123';

        // 1. Create provider with high-sensitivity secrets
        $res = $this->postJson('/api/v1/notification-providers', [
            'name' => 'Secure Enterprise Provider',
            'channel' => 'email',
            'provider' => 'smtp',
            'environment' => 'production',
            'credentials' => [
                'password' => $plainPassword,
                'api_key' => $plainApiKey,
                'bot_token' => $plainBotToken,
                'webhook_url' => $plainWebhookUrl,
            ],
            'settings' => ['encryption' => 'tls'],
        ])->assertStatus(201);

        $providerId = $res->json('data.id');

        // Security Check A: Database storage encryption
        $rawDbRecord = DB::table('notification_providers')->where('id', $providerId)->first();
        $this->assertNotEmpty($rawDbRecord->credentials);
        $this->assertStringNotContainsString($plainPassword, $rawDbRecord->credentials);
        $this->assertStringNotContainsString($plainApiKey, $rawDbRecord->credentials);
        $this->assertStringNotContainsString($plainBotToken, $rawDbRecord->credentials);
        $this->assertStringNotContainsString($plainWebhookUrl, $rawDbRecord->credentials);

        // Security Check B: API Response credentials masking
        $this->assertArrayNotHasKey('credentials', $res->json('data')); // raw credentials never exposed
        $this->assertArrayHasKey('masked_credentials', $res->json('data'));
        $this->assertStringNotContainsString($plainPassword, $res->json('data.masked_credentials.password'));
        $this->assertStringNotContainsString($plainApiKey, $res->json('data.masked_credentials.api_key'));
        $this->assertStringNotContainsString($plainBotToken, $res->json('data.masked_credentials.bot_token'));

        // Security Check C: Audit Logs table redaction
        $auditLogs = AuditLog::where('entity_id', (string) $providerId)->get();
        $this->assertNotEmpty($auditLogs);

        foreach ($auditLogs as $log) {
            $details = (string) $log->details;
            $this->assertStringNotContainsString($plainPassword, $details, "Plaintext password leaked in audit details!");
            $this->assertStringNotContainsString($plainApiKey, $details, "Plaintext api_key leaked in audit details!");
            $this->assertStringNotContainsString($plainBotToken, $details, "Plaintext bot_token leaked in audit details!");
            $this->assertStringNotContainsString($plainWebhookUrl, $details, "Plaintext webhook_url leaked in audit details!");

            if (!empty($log->payload)) {
                $payloadStr = json_encode($log->payload);
                $this->assertStringNotContainsString($plainPassword, $payloadStr, "Plaintext password leaked in audit payload!");
                $this->assertStringNotContainsString($plainApiKey, $payloadStr, "Plaintext api_key leaked in audit payload!");
                $this->assertStringNotContainsString($plainBotToken, $payloadStr, "Plaintext bot_token leaked in audit payload!");
            }
        }

        // Security Check D: API Request Logs redaction (if captured by middleware)
        $requestLogs = ApiRequestLog::where('path', 'like', '%notification-providers%')->get();
        foreach ($requestLogs as $reqLog) {
            if (!empty($reqLog->request_data)) {
                $reqDataStr = json_encode($reqLog->request_data);
                $this->assertStringNotContainsString($plainPassword, $reqDataStr, "Plaintext password leaked in api_request_logs!");
                $this->assertStringNotContainsString($plainApiKey, $reqDataStr, "Plaintext api_key leaked in api_request_logs!");
            }
        }
    }
}

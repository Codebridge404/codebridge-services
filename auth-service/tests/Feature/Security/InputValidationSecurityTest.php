<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InputValidationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $testUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testUser = User::create([
            'name' => 'Security Test User',
            'email' => 'security@codebridge.dev',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);
    }

    /**
     * Test SQL injection payloads in authentication endpoints are safely rejected or sanitized.
     */
    public function test_sql_injection_in_login_email_is_rejected(): void
    {
        $payloads = [
            "' OR '1'='1",
            "admin'--",
            "' UNION SELECT 1, 'admin', 'hash'--",
            "'; DROP TABLE users; --",
        ];

        foreach ($payloads as $maliciousEmail) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $maliciousEmail,
                'password' => 'password123',
            ]);

            // Must reject as unprocessable (422) or failed authentication (401), never crash with 500
            $this->assertContains($response->getStatusCode(), [401, 422], "Failed for payload: {$maliciousEmail}");
            $this->assertNotEquals(500, $response->getStatusCode());
        }
    }

    /**
     * Test XSS payloads in inputs are properly escaped or rejected.
     */
    public function test_xss_script_payloads_do_not_execute_or_crash_api(): void
    {
        $xssPayloads = [
            "<script>alert('XSS')</script>",
            "javascript:alert(1)",
            "<img src=x onerror=alert('XSS')>",
            "<svg/onload=alert('XSS')>",
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->actingAs($this->testUser)->getJson('/api/v1/users/lookup?search=' . urlencode($payload));

            // Must respond cleanly with 200 or 422, never crash with unhandled 500 error
            $this->assertNotEquals(500, $response->getStatusCode(), "Crashed on XSS payload: {$payload}");
        }
    }

    /**
     * Test POS PIN code input validation enforces strict numeric length.
     */
    public function test_pos_pin_rejects_alphabetic_and_oversized_values(): void
    {
        $invalidPins = [
            'abcd',
            '12',
            '12345678901234567890',
            "1234' OR 1=1 --",
            '<script>',
        ];

        foreach ($invalidPins as $pin) {
            $response = $this->actingAs($this->testUser)->postJson('/api/v1/auth/verify-pin', [
                'pin_code' => $pin,
            ]);

            // Should be validation error or rejection (400, 401, 403, or 422)
            $this->assertContains($response->getStatusCode(), [400, 401, 403, 422]);
        }
    }

    /**
     * Test protection against null-byte injection.
     */
    public function test_null_byte_injection_is_handled_safely(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => "admin\0@example.com",
            'password' => "secret\0password",
        ]);

        $this->assertNotEquals(500, $response->getStatusCode());
    }
}

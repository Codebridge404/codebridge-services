<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test invalid email formats are rejected by registration request validation.
     */
    public function test_invalid_email_format_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Bad Email User',
            'email' => 'not-a-valid-email-string',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test account enumeration mitigation returns consistent error messages.
     */
    public function test_existing_email_or_phone_returns_consistent_message(): void
    {
        User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Existing User',
            'email' => 'existing@codebridges.io',
            'phone' => '+85512345678',
            'password' => bcrypt('password123'),
            'role' => 'user',
            'is_active' => true,
        ]);

        // Probe with existing email
        $emailProbe = $this->postJson('/api/v1/auth/register', [
            'name' => 'Probe 1',
            'email' => 'existing@codebridges.io',
            'password' => 'Password123!',
        ]);

        $emailProbe->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'The provided email address or phone number is already registered.',
            ]);

        // Probe with existing phone
        $phoneProbe = $this->postJson('/api/v1/auth/register', [
            'name' => 'Probe 2',
            'phone' => '+85512345678',
            'password' => 'Password123!',
        ]);

        $phoneProbe->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'The provided email address or phone number is already registered.',
            ]);
    }
}

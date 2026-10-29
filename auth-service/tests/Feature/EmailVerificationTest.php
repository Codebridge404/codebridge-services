<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Test user receives verification email upon public registration.
     */
    public function test_user_receives_verification_email_on_registration(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alice Verified',
            'email' => 'alice@codebridge.dev',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
            ]);

        $user = User::where('email', 'alice@codebridge.dev')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->hasVerifiedEmail());

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    /**
     * Test user can verify email using a signed verification URL.
     */
    public function test_user_can_verify_email_via_signed_url(): void
    {
        $user = User::factory()->create([
            'email' => 'bob@codebridge.dev',
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->hasVerifiedEmail());

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $response = $this->getJson($signedUrl);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Email address verified successfully.',
            ]);

        $freshUser = $user->fresh();
        $this->assertTrue($freshUser->hasVerifiedEmail());
        $this->assertNotNull($freshUser->email_verified_at);
    }

    /**
     * Test verification URL with invalid/tampered hash is rejected.
     */
    public function test_invalid_verification_hash_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'carol@codebridge.dev',
            'email_verified_at' => null,
        ]);

        $response = $this->getJson("/api/v1/auth/email/verify/{$user->id}/invalid-hash-value");

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 'INVALID_VERIFICATION_HASH',
            ]);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /**
     * Test user can verify email using a 6-digit verification code.
     */
    public function test_user_can_verify_email_via_six_digit_code(): void
    {
        $user = User::factory()->create([
            'email' => 'david@codebridge.dev',
            'email_verified_at' => null,
        ]);

        Cache::put("auth:email_verify_code:{$user->id}", '789123', now()->addMinutes(15));

        $response = $this->postJson('/api/v1/auth/email/verify-code', [
            'email' => 'david@codebridge.dev',
            'code' => '789123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Email address verified successfully.',
            ]);

        $freshUser = $user->fresh();
        $this->assertTrue($freshUser->hasVerifiedEmail());
        $this->assertNotNull($freshUser->email_verified_at);
    }

    /**
     * Test invalid or expired 6-digit code is rejected.
     */
    public function test_invalid_or_expired_code_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'eve@codebridge.dev',
            'email_verified_at' => null,
        ]);

        Cache::put("auth:email_verify_code:{$user->id}", '111222', now()->addMinutes(15));

        $response = $this->postJson('/api/v1/auth/email/verify-code', [
            'email' => 'eve@codebridge.dev',
            'code' => '999999',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'code' => 'INVALID_CODE',
            ]);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /**
     * Test check email verification status endpoint.
     */
    public function test_can_check_email_verification_status(): void
    {
        $unverifiedUser = User::factory()->create([
            'email' => 'unverified@codebridge.dev',
            'email_verified_at' => null,
        ]);

        $verifiedUser = User::factory()->create([
            'email' => 'verified@codebridge.dev',
            'email_verified_at' => now(),
        ]);

        // 1. Unverified check
        $res1 = $this->postJson('/api/v1/auth/email/check', [
            'email' => 'unverified@codebridge.dev',
        ]);
        $res1->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'email' => 'unverified@codebridge.dev',
                'is_verified' => false,
                'email_verified_at' => null,
            ]);

        // 2. Verified check
        $res2 = $this->postJson('/api/v1/auth/email/check', [
            'email' => 'verified@codebridge.dev',
        ]);
        $res2->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'email' => 'verified@codebridge.dev',
                'is_verified' => true,
            ]);
    }

    /**
     * Test resending verification email.
     */
    public function test_can_resend_verification_email(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'frank@codebridge.dev',
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/email/verification-notification', [
            'email' => 'frank@codebridge.dev',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    /**
     * Test create-link endpoint returns delivery status and dispatches mail.
     */
    public function test_can_create_verification_link_endpoint(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'george@codebridge.dev',
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/email/create-link', [
            'email' => 'george@codebridge.dev',
            'send_mail' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'email',
                'expires_at',
                'otp_expires_at',
                'mail_sent',
                'mailpit_url',
            ]);

        $this->assertTrue($response->json('mail_sent'));
        // Verify credentials are NOT leaked in response
        $this->assertNull($response->json('verification_link'));
        $this->assertNull($response->json('verification_code'));

        // Delivered exclusively through email notification
        Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            $link = $mail->viewData['verificationUrl'] ?? null;
            $code = $mail->viewData['code'] ?? null;

            $this->assertNotNull($link);
            $this->assertNotNull($code);
            $this->assertStringContainsString('/api/v1/auth/email/verify/', $link);

            // Calling signed link succeeds
            $verifyRes = $this->getJson($link);
            $verifyRes->assertStatus(200);

            return true;
        });

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /**
     * Test verification URL with custom redirect_url parameter redirects properly.
     */
    public function test_verification_link_with_redirect_url(): void
    {
        $user = User::factory()->create([
            'email' => 'helen@codebridge.dev',
            'email_verified_at' => null,
        ]);

        $signedUrl = $user->createEmailVerificationUrl() . '&redirect_url=' . urlencode('http://localhost:3000/auth/login');

        $response = $this->get($signedUrl);
        $response->assertRedirect('http://localhost:3000/auth/login?verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /**
     * Test preview email verification HTML template endpoint.
     */
    public function test_can_preview_email_verification_template(): void
    {
        $response = $this->get('/api/v1/auth/email/template-preview?name=TestUser&code=123456');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Verify Your Email Address', $content);
        $this->assertStringContainsString('CodeBridges', $content);
        $this->assertStringContainsString('TestUser', $content);
        $this->assertStringContainsString('123456', $content);
        $this->assertStringContainsString('Verify Email Address', $content);
    }

    /**
     * Test send-link endpoint dispatches email and returns link.
     */
    public function test_can_send_link_via_dedicated_endpoint(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'ian@codebridge.dev',
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/email/send-link', [
            'email' => 'ian@codebridge.dev',
            'send_mail' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'email' => 'ian@codebridge.dev',
                'mail_sent' => true,
            ]);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    /**
     * Test VerifyEmailNotification builds with emails.verify-email template.
     */
    public function test_notification_renders_blade_template(): void
    {
        $user = User::factory()->create([
            'name' => 'Jennifer',
            'email' => 'jennifer@codebridge.dev',
            'email_verified_at' => null,
        ]);

        $notification = new VerifyEmailNotification('http://example.com/verify', '654321');
        $mail = $notification->toMail($user);

        $this->assertEquals('emails.verify-email', $mail->view);
        $this->assertEquals('Verify Your Email Address - CodeBridges', $mail->subject);
        $this->assertEquals('654321', $mail->viewData['code']);
        $this->assertEquals('http://example.com/verify', $mail->viewData['verificationUrl']);
    }
}


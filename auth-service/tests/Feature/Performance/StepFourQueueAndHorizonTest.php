<?php

namespace Tests\Feature\Performance;

use App\Http\Controllers\Api\V1\MeetingTelegramController;
use App\Jobs\SendMeetingTelegramAlertJob;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StepFourQueueAndHorizonTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_configuration_and_super_admin_gate_fail_closed(): void
    {
        $this->assertSame('codebridge_auth_horizon:', config('horizon.prefix'));
        $this->assertContains('notifications', config('horizon.defaults.supervisor-1.queue'));
        $this->assertArrayHasKey('staging', config('horizon.environments'));
        $this->assertArrayHasKey('production', config('horizon.environments'));

        Config::set('horizon.allowed_emails', []);

        $cashier = User::factory()->create(['role' => 'cashier', 'email' => 'cashier@horizon.test']);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'email' => 'super@horizon.test']);
        $allowedUser = User::factory()->create(['role' => 'admin', 'email' => 'ops-lead@horizon.test']);

        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($cashier)->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewHorizon'));

        Config::set('horizon.allowed_emails', ['ops-lead@horizon.test']);
        $this->assertTrue(Gate::forUser($allowedUser)->allows('viewHorizon'));
    }

    public function test_verify_email_notification_is_queued_and_caches_otp_synchronously(): void
    {
        Queue::fake();

        $this->assertContains(ShouldQueue::class, class_implements(VerifyEmailNotification::class));

        $user = User::factory()->create([
            'email' => 'queued.user@codebridge.dev',
            'email_verified_at' => null,
        ]);

        $user->sendEmailVerificationNotification();

        // OTP code must be cached synchronously so user can verify even before queue delivery finishes
        $cachedOtp = Cache::get("auth:email_verify_code:{$user->id}");
        $this->assertIsString($cachedOtp);
        $this->assertSame(6, strlen($cachedOtp));

        Queue::assertPushedOn('notifications', SendQueuedNotifications::class, function ($job) {
            return $job->notification instanceof VerifyEmailNotification;
        });
    }

    public function test_meeting_telegram_alert_dispatches_to_queue_without_exposing_bot_token_in_payload(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $tenant = Tenant::create([
            'name' => 'Telegram Async Org',
            'slug' => 'tg-async-' . Str::random(5),
            'status' => 'active',
        ]);

        $secretToken = '123456:ABC-SUPER-SECRET-TELEGRAM-TOKEN';

        DB::table('meeting_system_settings')->insert([
            [
                'tenant_id' => $tenant->id,
                'setting_key' => 'notification.telegram_enabled',
                'setting_value' => 'true',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $tenant->id,
                'setting_key' => 'notification.telegram_bot_token',
                'setting_value' => $secretToken,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $tenant->id,
                'setting_key' => 'notification.telegram_default_chat_id',
                'setting_value' => '-100999888777',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $dispatched = MeetingTelegramController::dispatchTelegramAlert('confirmation', [
            'title' => 'Q4 Architecture Review',
            'room' => 'Boardroom A',
            'startTime' => 'Nov 9, 2026 10:00',
            'endTime' => '11:00',
            'organizer' => 'Alice',
            'materials' => 'Projector',
        ], (string) $tenant->id);

        $this->assertTrue($dispatched);

        // No synchronous HTTP requests should fire inside API request lifecycle
        Http::assertNothingSent();

        Queue::assertPushedOn('notifications', SendMeetingTelegramAlertJob::class, function (SendMeetingTelegramAlertJob $job) use ($secretToken, $tenant) {
            $serialized = serialize($job);
            $this->assertStringNotContainsString($secretToken, $serialized);
            return $job->type === 'confirmation' && $job->tenantId === (string) $tenant->id;
        });

        // Running the queued job delivers via Telegram Bot API
        (new SendMeetingTelegramAlertJob('confirmation', [
            'title' => 'Q4 Architecture Review',
            'room' => 'Boardroom A',
            'startTime' => 'Nov 9, 2026 10:00',
            'endTime' => '11:00',
            'organizer' => 'Alice',
            'materials' => 'Projector',
        ], (string) $tenant->id))->handle();

        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/bot' . $secretToken . '/sendMessage')
            && $req['chat_id'] === '-100999888777'
            && str_contains($req['text'], 'Q4 Architecture Review'));
    }
}


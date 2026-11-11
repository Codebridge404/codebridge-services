<?php

namespace Tests\Feature\Security;

use App\Exceptions\CredentialEncryptionException;
use App\Exceptions\TelegramPermanentException;
use App\Exceptions\TelegramRetryableException;
use App\Http\Controllers\Api\V1\MeetingTelegramController;
use App\Jobs\SendMeetingTelegramAlertJob;
use App\Models\NotificationProvider;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingTelegramEncryptionAndRetryTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $adminUserA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant Alpha',
            'slug' => 'tenant-alpha',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant Beta',
            'slug' => 'tenant-beta',
            'status' => 'active',
        ]);

        $this->adminUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'admin',
            'email' => 'admin@alpha.corp',
            'is_active' => true,
        ]);
    }

    /**
     * Acceptance: Saving configuration leaves no raw token in either legacy table.
     */
    public function test_saving_telegram_config_encrypts_credentials_in_notification_provider_and_leaves_no_raw_token_in_legacy_tables(): void
    {
        $rawBotToken = '123456789:ABCdefGHIjklMNOpqrSTUvwxYZ_test_secret_token';
        $chatId = '-100987654321';

        $response = $this->actingAs($this->adminUserA)
            ->putJson('/api/v1/meetings/telegram/config', [
                'bot_enabled' => true,
                'bot_token' => $rawBotToken,
                'bot_username' => 'AlphaMeetingBot',
                'default_chat_id' => $chatId,
                'default_reminder_minutes' => 15,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.bot_enabled', true)
            ->assertJsonPath('data.has_token', true);

        // 1. Raw token MUST NOT exist in meeting_system_settings
        $settingToken = DB::table('meeting_system_settings')
            ->where('tenant_id', $this->tenantA->id)
            ->where('setting_key', 'notification.telegram_bot_token')
            ->value('setting_value');
        $this->assertNull($settingToken, 'Plaintext token found in meeting_system_settings!');

        // 2. Raw token MUST NOT exist in meeting_telegram_configs
        $legacyConfig = DB::table('meeting_telegram_configs')
            ->where('tenant_id', $this->tenantA->id)
            ->first();
        if ($legacyConfig) {
            $this->assertEmpty($legacyConfig->bot_token, 'Plaintext token found in meeting_telegram_configs!');
        }

        // 3. Encrypted NotificationProvider must exist
        $provider = NotificationProvider::where('channel', 'telegram')
            ->where('tenant_id', $this->tenantA->id)
            ->first();
        $this->assertNotNull($provider, 'Encrypted NotificationProvider was not created!');
        $this->assertTrue($provider->is_enabled);

        // Direct database column must be ciphertext and not contain the raw token
        $rawStoredInDb = DB::table('notification_providers')->where('id', $provider->id)->value('credentials');
        $this->assertStringNotContainsString($rawBotToken, $rawStoredInDb);

        // Decrypted credentials must match original input
        $decrypted = $provider->getDecryptedCredentials();
        $this->assertEquals($rawBotToken, $decrypted['bot_token']);
        $this->assertEquals($chatId, $decrypted['chat_id']);
    }

    /**
     * Acceptance: Tenant A cannot read or use tenant B’s provider.
     */
    public function test_tenant_a_cannot_read_or_use_tenant_b_provider(): void
    {
        $rawBotTokenA = '111111:TokenTenantAlpha';
        $chatIdA = '-100111111';

        // Setup Tenant A's provider
        $providerA = new NotificationProvider();
        $providerA->uuid = (string) Str::uuid();
        $providerA->tenant_id = $this->tenantA->id;
        $providerA->name = 'Tenant A Bot';
        $providerA->channel = 'telegram';
        $providerA->provider = 'telegram';
        $providerA->environment = 'all';
        $providerA->is_enabled = true;
        $providerA->credentials = [
            'bot_token' => $rawBotTokenA,
            'chat_id' => $chatIdA,
        ];
        $providerA->settings = ['default_chat_id' => $chatIdA];
        $providerA->save();

        // Tenant B has no provider configured, even if a global (tenant_id = null) security bot exists
        $globalProvider = new NotificationProvider();
        $globalProvider->uuid = (string) Str::uuid();
        $globalProvider->tenant_id = null;
        $globalProvider->name = 'CodeBridge Security Alert Bot';
        $globalProvider->channel = 'telegram';
        $globalProvider->provider = 'telegram';
        $globalProvider->environment = 'all';
        $globalProvider->is_enabled = true;
        $globalProvider->credentials = [
            'bot_token' => '999999:GlobalSecurityBotSecret',
            'chat_id' => '-100999888777',
        ];
        $globalProvider->settings = ['default_chat_id' => '-100999888777'];
        $globalProvider->save();

        Http::fake();

        // Attempting to deliver for Tenant B must return false (cannot read Tenant A or global bot)
        $deliveredB = MeetingTelegramController::deliverTelegramAlertNow('confirmation', [
            'title' => 'Tenant B Meeting',
        ], (string) $this->tenantB->id);

        $this->assertFalse($deliveredB);
        Http::assertNothingSent();

        // Tenant B admin API config endpoint must NOT expose Tenant A or global bot
        $adminUserB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role' => 'admin',
            'email' => 'admin@beta.corp',
            'is_active' => true,
        ]);

        $this->actingAs($adminUserB)
            ->getJson('/api/v1/meetings/telegram/config')
            ->assertOk()
            ->assertJsonPath('data.has_token', false)
            ->assertJsonPath('data.bot_token_masked', '');

        $this->actingAs($adminUserB)
            ->postJson('/api/v1/meetings/telegram/test')
            ->assertStatus(400);

        Http::assertNothingSent();
    }

    /**
     * Acceptance: Migration encrypts pre-existing legacy credentials from both legacy tables and removes plaintext copies.
     */
    public function test_migration_encrypts_legacy_credentials_from_both_tables_and_purges_plaintext(): void
    {
        $legacyTokenConfig = '777111:LegacyConfigTableToken';
        $legacyChatConfig = '-100777111';
        $legacyTokenSetting = '888222:LegacySystemSettingToken';
        $legacyChatSetting = '-100888222';

        DB::table('meeting_telegram_configs')->insert([
            'tenant_id' => $this->tenantA->id,
            'bot_token' => $legacyTokenConfig,
            'chat_id' => $legacyChatConfig,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('meeting_system_settings')->insert([
            [
                'tenant_id' => $this->tenantB->id,
                'setting_key' => 'notification.telegram_enabled',
                'setting_value' => 'true',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->tenantB->id,
                'setting_key' => 'notification.telegram_bot_token',
                'setting_value' => $legacyTokenSetting,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->tenantB->id,
                'setting_key' => 'notification.telegram_default_chat_id',
                'setting_value' => $legacyChatSetting,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = require database_path('migrations/2026_11_10_000001_migrate_meeting_telegram_credentials_to_notification_providers.php');
        $migration->up();

        // Neither legacy table may retain any raw token
        $this->assertSame(
            '',
            (string) DB::table('meeting_telegram_configs')->where('tenant_id', $this->tenantA->id)->value('bot_token')
        );
        $this->assertNull(
            DB::table('meeting_system_settings')
                ->where('tenant_id', $this->tenantB->id)
                ->where('setting_key', 'notification.telegram_bot_token')
                ->value('setting_value')
        );

        // Both tenants must now have encrypted NotificationProvider records
        $providerA = NotificationProvider::where('channel', 'telegram')->where('tenant_id', $this->tenantA->id)->first();
        $providerB = NotificationProvider::where('channel', 'telegram')->where('tenant_id', $this->tenantB->id)->first();
        $this->assertNotNull($providerA);
        $this->assertNotNull($providerB);

        $this->assertStringNotContainsString($legacyTokenConfig, (string) DB::table('notification_providers')->where('id', $providerA->id)->value('credentials'));
        $this->assertStringNotContainsString($legacyTokenSetting, (string) DB::table('notification_providers')->where('id', $providerB->id)->value('credentials'));
        $this->assertSame($legacyTokenConfig, $providerA->getDecryptedCredentials()['bot_token']);
        $this->assertSame($legacyTokenSetting, $providerB->getDecryptedCredentials()['bot_token']);

        // Validate token endpoint still resolves encrypted credentials when UI sends masked dots
        Http::fake([
            'https://api.telegram.org/bot' . $legacyTokenConfig . '/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 12345, 'username' => 'MigratedBot', 'first_name' => 'Migrated'],
            ], 200),
            'https://api.telegram.org/bot' . $legacyTokenSetting . '/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 555],
            ], 200),
        ]);

        $this->actingAs($this->adminUserA)
            ->postJson('/api/v1/meetings/telegram/validate-token', ['token' => '7771••••••••oken'])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('bot_username', 'MigratedBot');

        $this->assertTrue(
            MeetingTelegramController::deliverTelegramAlertNow('confirmation', [
                'title' => 'Migrated Settings Meeting',
            ], (string) $this->tenantB->id)
        );
    }

    /**
     * Acceptance: A worker sends successfully using decrypted credentials.
     */
    public function test_worker_sends_successfully_using_decrypted_credentials(): void
    {
        $rawBotToken = '222222:RealDecryptedToken';
        $chatId = '-100222222';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Telegram Alerts';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = [
            'bot_token' => $rawBotToken,
            'chat_id' => $chatId,
        ];
        $provider->settings = ['default_chat_id' => $chatId];
        $provider->save();

        Http::fake([
            'https://api.telegram.org/bot' . $rawBotToken . '/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 999],
            ], 200),
        ]);

        $delivered = MeetingTelegramController::deliverTelegramAlertNow('confirmation', [
            'title' => 'Project Kickoff',
            'room' => 'Room 101',
            'startTime' => '10:00 AM',
            'endTime' => '11:00 AM',
            'organizer' => 'Alice',
            'materials' => 'Projector',
        ], (string) $this->tenantA->id);

        $this->assertTrue($delivered);

        Http::assertSent(function ($request) use ($rawBotToken, $chatId) {
            return $request->url() === 'https://api.telegram.org/bot' . $rawBotToken . '/sendMessage'
                && $request['chat_id'] === $chatId
                && str_contains($request['text'], 'Project Kickoff');
        });
    }

    /**
     * Acceptance: Corrupted ciphertext stops delivery without exposing secrets.
     */
    public function test_corrupted_ciphertext_stops_delivery_without_exposing_secrets(): void
    {
        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Corrupted Provider';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => 'valid_temporary_token', 'chat_id' => '-100'];
        $provider->save();

        // Corrupt the ciphertext in the database
        DB::table('notification_providers')->where('id', $provider->id)->update([
            'credentials' => 'eyJpdiI6ImJhZEl2IiwidmFsdWUiOiJjb3JydXB0ZWRfcGF5bG9hZCIsIm1hYyI6ImJhZE1hYyJ9',
        ]);

        Http::fake();

        $this->expectException(CredentialEncryptionException::class);

        try {
            MeetingTelegramController::deliverTelegramAlertNow('confirmation', [
                'title' => 'Corrupted Secret Meeting',
            ], (string) $this->tenantA->id);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * Acceptance: Queued worker resolves encrypted credentials internally, leaves no secrets in job payload,
     * and marks corrupted ciphertext failed safely without retries or HTTP calls.
     */
    public function test_queued_job_omits_secrets_from_payload_and_fails_safely_on_corrupted_ciphertext(): void
    {
        $rawBotToken = '232323:QueueSecretIsolationToken';
        $chatId = '-100232323';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Queued Bot';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => $rawBotToken, 'chat_id' => $chatId];
        $provider->save();

        Queue::fake();

        $this->assertTrue(
            MeetingTelegramController::dispatchTelegramAlert('confirmation', [
                'title' => 'Zero Secret Payload Check',
            ], (string) $this->tenantA->id)
        );

        Queue::assertPushedOn('notifications', SendMeetingTelegramAlertJob::class, function (SendMeetingTelegramAlertJob $pushedJob) use ($rawBotToken) {
            return !str_contains(serialize($pushedJob), $rawBotToken);
        });

        // Now corrupt ciphertext before worker executes
        DB::table('notification_providers')->where('id', $provider->id)->update([
            'credentials' => 'not-valid-ciphertext',
        ]);

        Http::fake();
        Log::spy();

        $job = new SendMeetingTelegramAlertJob('confirmation', [
            'title' => 'Corrupted Worker Delivery',
        ], (string) $this->tenantA->id);
        $job->withFakeQueueInteractions();
        $job->handle();

        $job->assertFailedWith(CredentialEncryptionException::class)
            ->assertNotReleased();
        Http::assertNothingSent();
    }

    /**
     * Acceptance: Fake a timeout, 429 and 500 followed by success: each triggers retry and eventual delivery.
     */
    public function test_fake_timeout_followed_by_success_triggers_retry_and_eventual_delivery(): void
    {
        $rawBotToken = '333333:TimeoutTestToken';
        $chatId = '-100333333';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Telegram Alerts';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => $rawBotToken, 'chat_id' => $chatId];
        $provider->settings = ['default_chat_id' => $chatId];
        $provider->save();

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds');
            }
            return Http::response(['ok' => true, 'result' => ['message_id' => 101]], 200);
        });

        $job = new SendMeetingTelegramAlertJob('confirmation', [
            'title' => 'Timeout Retry Test',
            'room' => '1A',
            'startTime' => '09:00',
            'endTime' => '10:00',
            'organizer' => 'Bob',
            'materials' => 'None',
        ], (string) $this->tenantA->id);

        $job->withFakeQueueInteractions();

        // First attempt: connection timeout triggers release for retry
        $job->handle();
        $this->assertTrue($job->job->isReleased(), 'Job should be released for retry on timeout.');

        // Second attempt: succeeds
        $job->handle();
        $this->assertEquals(2, $attempts);
    }

    /**
     * Acceptance: HTTP 429 rate limit respects Telegram retry delay.
     */
    public function test_fake_429_rate_limit_respects_telegram_retry_delay_and_retries(): void
    {
        $rawBotToken = '444444:RateLimitTestToken';
        $chatId = '-100444444';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Telegram Alerts';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => $rawBotToken, 'chat_id' => $chatId];
        $provider->settings = ['default_chat_id' => $chatId];
        $provider->save();

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                return Http::response([
                    'ok' => false,
                    'error_code' => 429,
                    'description' => 'Too Many Requests: retry after 7',
                    'parameters' => ['retry_after' => 7],
                ], 429);
            }
            return Http::response(['ok' => true, 'result' => ['message_id' => 102]], 200);
        });

        $job = new SendMeetingTelegramAlertJob('confirmation', [
            'title' => 'Rate Limit Test',
            'room' => '1B',
            'startTime' => '11:00',
            'endTime' => '12:00',
            'organizer' => 'Charlie',
            'materials' => 'None',
        ], (string) $this->tenantA->id);

        $job->withFakeQueueInteractions();

        // First attempt: should be released with delay matching Telegram retry_after (7 seconds)
        $job->handle();
        $job->assertReleased(7);

        // Second attempt succeeds
        $job->handle();
        $this->assertEquals(2, $attempts);
    }

    /**
     * Acceptance: HTTP 500 server error followed by success triggers retry and eventual delivery.
     */
    public function test_fake_500_server_error_followed_by_success_triggers_retry_and_eventual_delivery(): void
    {
        $rawBotToken = '555555:ServerErrorToken';
        $chatId = '-100555555';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Telegram Alerts';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => $rawBotToken, 'chat_id' => $chatId];
        $provider->settings = ['default_chat_id' => $chatId];
        $provider->save();

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                return Http::response([
                    'ok' => false,
                    'error_code' => 500,
                    'description' => 'Internal server error',
                ], 500);
            }
            return Http::response(['ok' => true, 'result' => ['message_id' => 103]], 200);
        });

        $job = new SendMeetingTelegramAlertJob('confirmation', [
            'title' => 'Server Error Retry Test',
            'room' => '1C',
            'startTime' => '13:00',
            'endTime' => '14:00',
            'organizer' => 'Dave',
            'materials' => 'None',
        ], (string) $this->tenantA->id);

        $job->withFakeQueueInteractions();

        // First attempt releases back to queue
        $job->handle();
        $this->assertTrue($job->job->isReleased(), 'Job should be released on HTTP 500.');

        // Second attempt succeeds
        $job->handle();
        $this->assertEquals(2, $attempts);
    }

    /**
     * Acceptance: Exhausted attempts produce a visible failure, with no bot token in logs.
     */
    public function test_exhausted_attempts_produce_visible_failure_with_no_bot_token_in_logs(): void
    {
        $rawBotToken = '666666:ExhaustedSuperSecretBotToken';
        $chatId = '-100666666';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Telegram Alerts';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => $rawBotToken, 'chat_id' => $chatId];
        $provider->settings = ['default_chat_id' => $chatId];
        $provider->save();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 500,
                'description' => 'Persistent Telegram internal outage',
            ], 500),
        ]);

        $job = new class('confirmation', [
            'title' => 'Exhausted Job Test',
            'room' => '1D',
            'startTime' => '15:00',
            'endTime' => '16:00',
            'organizer' => 'Eve',
            'materials' => 'None',
        ], (string) $this->tenantA->id) extends SendMeetingTelegramAlertJob {
            public int $mockAttempts = 3;
            public function attempts(): int
            {
                return $this->mockAttempts;
            }
        };

        // Track log output
        $loggedMessages = [];
        Log::shouldReceive('warning')->zeroOrMoreTimes()->andReturnUsing(function ($message) use (&$loggedMessages) {
            $loggedMessages[] = (string) $message;
        });
        Log::shouldReceive('error')->atLeast()->once()->andReturnUsing(function ($message) use (&$loggedMessages) {
            $loggedMessages[] = (string) $message;
        });

        $caughtException = null;
        try {
            $job->handle();
            $this->fail('Expected TelegramRetryableException was not thrown.');
        } catch (TelegramRetryableException $e) {
            $caughtException = $e;
        }

        $this->assertInstanceOf(TelegramRetryableException::class, $caughtException);
        $this->assertStringNotContainsString($rawBotToken, (string) $caughtException, 'Bot token leaked in exception!');
        $combinedLog = implode(' ', $loggedMessages);
        $this->assertNotEmpty($loggedMessages, 'Failure must be visibly logged.');
        $this->assertStringNotContainsString($rawBotToken, $combinedLog, 'Bot token was leaked in logs!');
    }

    /**
     * Acceptance: Network timeout exception stringification never leaks raw bot token into failed_jobs.
     */
    public function test_network_timeout_exception_chain_redacts_bot_token(): void
    {
        $rawBotToken = '777777:TimeoutSecretInUrlToken';
        $chatId = '-100777777';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Timeout Leak Check Bot';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => $rawBotToken, 'chat_id' => $chatId];
        $provider->save();

        Http::fake(function () use ($rawBotToken) {
            throw new ConnectionException("cURL error 28: Operation timed out for https://api.telegram.org/bot{$rawBotToken}/sendMessage");
        });

        $job = new class('confirmation', ['title' => 'Leak Check'], (string) $this->tenantA->id) extends SendMeetingTelegramAlertJob {
            public function attempts(): int
            {
                return 3;
            }
        };
        $job->withFakeQueueInteractions();

        try {
            $job->handle();
            $this->fail('Expected TelegramRetryableException was not thrown.');
        } catch (TelegramRetryableException $e) {
            $this->assertStringNotContainsString($rawBotToken, $e->getMessage());
            $this->assertStringNotContainsString($rawBotToken, (string) $e);
            $job->assertFailedWith(TelegramRetryableException::class);
            $this->assertStringNotContainsString($rawBotToken, (string) $job->job->failedWith);
        }
    }

    /**
     * Acceptance: Permanent HTTP 4xx failure stops retrying immediately and logs visibly without credentials.
     */
    public function test_permanent_401_failure_fails_immediately_without_retrying_or_leaking_token(): void
    {
        $rawBotToken = '888888:RevokedPermanentBotToken';
        $chatId = '-100888888';

        $provider = new NotificationProvider();
        $provider->uuid = (string) Str::uuid();
        $provider->tenant_id = $this->tenantA->id;
        $provider->name = 'Permanent Failure Bot';
        $provider->channel = 'telegram';
        $provider->provider = 'telegram';
        $provider->environment = 'all';
        $provider->is_enabled = true;
        $provider->credentials = ['bot_token' => $rawBotToken, 'chat_id' => $chatId];
        $provider->save();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 401,
                'description' => "Unauthorized bot {$rawBotToken}",
            ], 401),
        ]);

        $loggedErrors = [];
        Log::shouldReceive('error')->atLeast()->once()->andReturnUsing(function ($message) use (&$loggedErrors) {
            $loggedErrors[] = (string) $message;
        });

        $job = new SendMeetingTelegramAlertJob('confirmation', ['title' => 'Permanent 401'], (string) $this->tenantA->id);
        $job->withFakeQueueInteractions();
        $job->handle();

        $job->assertFailedWith(TelegramPermanentException::class)
            ->assertNotReleased();
        $this->assertStringNotContainsString($rawBotToken, implode(' ', $loggedErrors));
        $this->assertStringNotContainsString($rawBotToken, (string) $job->job->failedWith);
    }

    /**
     * Acceptance: NotificationProviderSeeder contains zero hardcoded secrets, skips cleanly when unset,
     * and encrypts credentials from config('services.telegram_security.*') when configured.
     */
    public function test_notification_provider_seeder_reads_config_without_hardcoded_secrets(): void
    {
        $seederSource = (string) file_get_contents(database_path('seeders/NotificationProviderSeeder.php'));
        $this->assertDoesNotMatchRegularExpression('/\d{6,}:[A-Za-z0-9_-]{20,}/', $seederSource);
        $this->assertDoesNotMatchRegularExpression('/\benv\s*\(/', $seederSource);

        // 1. Unset config skips safely without creating empty/plaintext records
        config([
            'services.telegram_security.bot_token' => '',
            'services.telegram_security.chat_id' => '',
        ]);

        $this->seed(\Database\Seeders\NotificationProviderSeeder::class);
        $this->assertDatabaseMissing('notification_providers', [
            'channel' => 'telegram',
            'tenant_id' => null,
        ]);

        // 2. Configured values seed encrypted credentials cleanly and update idempotently
        $syntheticToken = '123456789:SyntheticSeederTestToken_abcdef12345';
        $syntheticChatId = '-1001122334455';
        config([
            'services.telegram_security.bot_token' => $syntheticToken,
            'services.telegram_security.chat_id' => $syntheticChatId,
            'services.telegram_security.bot_username' => 'synthetic_alerts_bot',
        ]);

        $this->seed(\Database\Seeders\NotificationProviderSeeder::class);

        $provider = NotificationProvider::where('channel', 'telegram')
            ->whereNull('tenant_id')
            ->first();

        $this->assertNotNull($provider);
        $this->assertTrue((bool) $provider->is_enabled);
        $this->assertSame('synthetic_alerts_bot', $provider->settings['bot_username'] ?? null);

        $rawCredentials = (string) DB::table('notification_providers')->where('id', $provider->id)->value('credentials');
        $this->assertStringNotContainsString($syntheticToken, $rawCredentials);

        $decrypted = $provider->getDecryptedCredentials();
        $this->assertSame($syntheticToken, $decrypted['bot_token'] ?? null);
        $this->assertSame($syntheticChatId, $decrypted['chat_id'] ?? null);
    }
}
